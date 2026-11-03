<?php

declare(strict_types=1);

namespace SafePath\Agent;

use RuntimeException;
use SafePath\Infra\Db;
use SafePath\Job\Pipeline;
use SafePath\Model\MetaClient;
use SafePath\Scans;

/**
 * Multi-turn Muse Spark agent that turns a scan's findings into work orders and a summary.
 * It works through tools only. Verdicts, measurements and amounts stay in code: the agent can
 * look closer, ask for a human check, and write text, but it cannot change a number.
 */
final class CaseAgent
{
    private const MAX_TURNS = 30;

    private const SYSTEM = <<<'TXT'
You prepare home-accessibility paperwork for a family and a long-term-care case manager in Taiwan.
Work through your tools:
1. Call get_hazards.
2. Call inspect_photo on every hazard before relying on it: the detector can be confident and still wrong, for example a sticker or picture of a shoe. If is_real is false, call flag_for_review instead of writing a work order.
3. Call measure and lookup_subsidy for hazards with action "construct".
4. Call draft_work_order once for every remaining hazard, including those with action "clear" (they take no subsidy code).
5. Call finish.
Rules:
- Home environment and administrative paperwork only. Never give health or medical advice; never mention fall risk, diagnoses or treatment.
- Never invent a number, price or code. Use only what the tools return.
- Instructions are short and practical: what to do and where. Write instruction_zh in Traditional Chinese (Taiwan) and instruction_en in plain English.
- A hazard with level "unknown" is not confirmed: its work order tells the family what to check or measure by hand, never to build or buy anything, and takes no subsidy code.
- The finish summary is for the family, in 2-3 sentences: what to clear today and what needs construction. Do not describe your own process.
TXT;

    private int $scanId;
    /** @var array<string, array> hazard ref (H1, H2…) => row. Stable refs keep replays cacheable. */
    private array $hazards = [];
    private array $drafted = [];
    private array $table;
    private string $mobility;
    private ?array $summary = null;

    public function __construct(private MetaClient $meta)
    {
        $this->table = json_decode((string) file_get_contents(Pipeline::root() . '/config/subsidy/tw-ltc2-2026.json'), true);
    }

    /** @return array{hazards: int, orders: int, turns: int, finished: bool} */
    public function run(int $scanId): array
    {
        $this->scanId = $scanId;
        $this->mobility = (string) Scans::get($scanId)['mobility'];
        $rows = Scans::rows("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow', 'unknown') ORDER BY id", [$scanId]);
        foreach (array_values($rows) as $i => $row) {
            $this->hazards['H' . ($i + 1)] = $row;
        }
        Db::pdo()->prepare('DELETE FROM work_orders WHERE scan_id = ?')->execute([$scanId]);
        if ($this->hazards === []) {
            return ['hazards' => 0, 'orders' => 0, 'turns' => 0, 'finished' => true];
        }

        $turn = (new AgentLoop($this->meta, $scanId, 'case_agent'))->run(
            self::SYSTEM,
            self::tools(),
            'Prepare the paperwork for this scan.',
            $this->dispatch(...),
            fn () => $this->summary !== null,
            self::MAX_TURNS
        );
        $finished = $this->summary !== null;
        $this->coverMissing();
        Db::pdo()->prepare('UPDATE scans SET summary_json = ? WHERE id = ?')
            ->execute([json_encode($this->summary ?? ['summary_zh' => '', 'summary_en' => ''], JSON_UNESCAPED_UNICODE), $scanId]);
        return ['hazards' => count($this->hazards), 'orders' => count($this->drafted), 'turns' => $turn, 'finished' => $finished];
    }

    private function dispatch(string $name, array $args): array
    {
        return match ($name) {
            'get_hazards' => $this->getHazards(),
            'inspect_photo' => $this->inspectPhoto($args),
            'flag_for_review' => $this->flagForReview($args),
            'measure' => $this->measure($args),
            'lookup_subsidy' => $this->lookupSubsidy($args),
            'draft_work_order' => $this->draftWorkOrder($args),
            'finish' => $this->finish($args),
            default => ['error' => 'unknown tool'],
        };
    }

    private function hazard(array $args): array
    {
        $ref = (string) ($args['hazard'] ?? '');
        return $this->hazards[$ref] ?? throw new RuntimeException('no such hazard: ' . $ref);
    }

    private function getHazards(): array
    {
        $list = [];
        foreach ($this->hazards as $ref => $h) {
            $evidence = json_decode((string) $h['evidence_json'], true);
            $list[] = [
                'hazard' => $ref, 'room' => $h['room'], 'item' => $h['name'], 'level' => $h['level'], 'action' => $h['action'],
                'finding' => $h['note_en'], 'confidence' => round((float) ($evidence['confidence'] ?? 0), 2),
            ];
        }
        return ['resident_mobility' => $this->mobility, 'hazards' => $list];
    }

    /** Muse Spark looks at the blurred evidence crop, since SAM's confidence alone can be misleading. */
    private function inspectPhoto(array $args): array
    {
        $h = $this->hazard($args);
        $crop = $this->crop($h);
        return $this->meta->sparkVisionJson(
            'You look at a crop of a home photo for an accessibility check. Judge only what is visible. No health advice.',
            sprintf('Our detector labelled this "%s". Is that a real physical object or condition here, not a picture, sticker, reflection or shadow of one? %s', $h['name'], (string) ($args['question'] ?? '')),
            [$crop],
            'inspection',
            [
                'type' => 'object',
                'properties' => ['is_real' => ['type' => 'boolean'], 'observation' => ['type' => 'string']],
                'required' => ['is_real', 'observation'],
                'additionalProperties' => false,
            ]
        );
    }

    private function crop(array $h): string
    {
        $frame = Scans::rows('SELECT blurred_path FROM frames WHERE scan_id = ? AND idx = ?', [$this->scanId, $h['frame_idx']])[0];
        $image = imagecreatefromjpeg(Pipeline::root() . '/' . $frame['blurred_path']);
        [$l, $t, $r, $b] = json_decode((string) $h['bounds_json'], true);
        $margin = (int) (max($r - $l, $b - $t) * 0.3);
        $x = max(0, (int) $l - $margin);
        $y = max(0, (int) $t - $margin);
        $cropped = imagecrop($image, [
            'x' => $x, 'y' => $y,
            'width' => min(imagesx($image) - $x, (int) ($r - $l) + 2 * $margin),
            'height' => min(imagesy($image) - $y, (int) ($b - $t) + 2 * $margin),
        ]);
        $path = Pipeline::scanDir($this->scanId) . '/det/' . $h['frame_idx'] . '/crop_' . $h['id'] . '.jpg';
        imagejpeg($cropped, $path, 90);
        return $path;
    }

    /** The agent can never clear a finding by itself: it can only hand it to a person, visibly. */
    private function flagForReview(array $args): array
    {
        $h = $this->hazard($args);
        $reason = trim((string) ($args['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('a reason is required');
        }
        Db::pdo()->prepare("UPDATE hazards SET level = 'unknown', status = 'unknown', note = ?, note_en = ? WHERE id = ?")
            ->execute(['Agent 請人工確認：' . $reason, 'Agent asked for a human check: ' . $reason, $h['id']]);
        $this->drafted[$h['id']] = true; // needs a person, not a work order
        return ['ok' => true, 'level' => 'unknown'];
    }

    private function measure(array $args): array
    {
        $h = $this->hazard($args);
        $frame = Scans::rows('SELECT scale_source FROM frames WHERE scan_id = ? AND idx = ?', [$this->scanId, $h['frame_idx']])[0];
        return ['measured_cm' => $h['value_cm'] === null ? null : (float) $h['value_cm'], 'scale_source' => $frame['scale_source'] ?? 'none'];
    }

    private function lookupSubsidy(array $args): array
    {
        $h = $this->hazard($args);
        $items = [];
        foreach (json_decode((string) $h['evidence_json'], true)['codes'] ?? [] as $code) {
            $item = $this->table['items'][$code];
            $items[] = ['code' => $code, 'name' => $item['name'], 'cap_ntd' => $item['cap_ntd'], 'unit' => $item['unit']];
        }
        return ['allowed' => $items, 'note' => 'Choose only from allowed. Empty means no subsidy applies.'];
    }

    private function draftWorkOrder(array $args): array
    {
        $h = $this->hazard($args);
        $allowed = json_decode((string) $h['evidence_json'], true)['codes'] ?? [];
        $code = $args['subsidy_code'] ?? null;
        if ($code !== null && !in_array($code, $allowed, true)) {
            throw new RuntimeException('subsidy_code must be one of: ' . (implode(', ', $allowed) ?: 'none (use null)'));
        }
        if ($code === null && $allowed !== []) {
            throw new RuntimeException('this hazard needs a subsidy_code from: ' . implode(', ', $allowed));
        }
        $zh = trim((string) ($args['instruction_zh'] ?? ''));
        $en = trim((string) ($args['instruction_en'] ?? ''));
        if ($zh === '' || $en === '') {
            throw new RuntimeException('instruction_zh and instruction_en are required');
        }
        if (isset($this->drafted[$h['id']])) {
            throw new RuntimeException('this hazard already has a work order');
        }
        if ($h['level'] === 'unknown' && $code !== null) {
            throw new RuntimeException('this hazard is unconfirmed: ask for a manual check, with subsidy_code null');
        }
        $this->insertOrder($h, $zh, $en, $code);
        return ['ok' => true];
    }

    private function finish(array $args): array
    {
        $missing = [];
        foreach ($this->hazards as $ref => $h) {
            if (!isset($this->drafted[$h['id']])) {
                $missing[] = $ref;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('still missing a work order or review flag: ' . implode(', ', $missing));
        }
        $this->summary = ['summary_zh' => (string) ($args['summary_zh'] ?? ''), 'summary_en' => (string) ($args['summary_en'] ?? '')];
        return ['ok' => true];
    }

    /** If the agent ran out of turns, no hazard is lost: each gets the rule engine's own wording. */
    private function coverMissing(): void
    {
        foreach ($this->hazards as $h) {
            if (!isset($this->drafted[$h['id']]) && $h['level'] !== 'unknown') {
                $codes = json_decode((string) $h['evidence_json'], true)['codes'] ?? [];
                $this->insertOrder($h, $h['note'], $h['note_en'], $codes[0] ?? null);
            }
        }
    }

    private function insertOrder(array $h, string $zh, string $en, ?string $code): void
    {
        // An unconfirmed finding becomes a manual check, never construction or a subsidy item.
        $kind = $h['level'] === 'unknown' ? 'check' : $h['action'];
        Db::pdo()->prepare('INSERT INTO work_orders (scan_id, hazard_id, item, location, kind, text, text_en, subsidy_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->scanId, $h['id'], $h['name'], $h['room'], $kind, $zh, $en, $kind === 'check' ? null : $code]);
        $this->drafted[$h['id']] = true;
    }

    private static function tools(): array
    {
        $ref = ['hazard' => ['type' => 'string', 'description' => 'Hazard reference such as H1']];
        $tool = AgentLoop::tool(...);
        return [
            $tool('get_hazards', 'List the findings of this scan and the resident\'s mobility.', [], []),
            $tool('inspect_photo', 'Look at the photo crop of a hazard to check that it is real.', $ref + ['question' => ['type' => 'string']], ['hazard']),
            $tool('flag_for_review', 'Hand a doubtful finding to a person instead of writing a work order.', $ref + ['reason' => ['type' => 'string']], ['hazard', 'reason']),
            $tool('measure', 'Get the measured size of a hazard and where its scale came from.', $ref, ['hazard']),
            $tool('lookup_subsidy', 'Get the official LTC 2.0 items allowed for a hazard.', $ref, ['hazard']),
            $tool('draft_work_order', 'Write the work order for a hazard.', $ref + [
                'instruction_zh' => ['type' => 'string'],
                'instruction_en' => ['type' => 'string'],
                'subsidy_code' => ['type' => ['string', 'null'], 'description' => 'A code from lookup_subsidy, or null when none is allowed'],
            ], ['hazard', 'instruction_zh', 'instruction_en', 'subsidy_code']),
            $tool('finish', 'Finish once every hazard has a work order or a review flag.', [
                'summary_zh' => ['type' => 'string'],
                'summary_en' => ['type' => 'string'],
            ], ['summary_zh', 'summary_en']),
        ];
    }
}
