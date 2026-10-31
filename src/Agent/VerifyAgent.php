<?php

declare(strict_types=1);

namespace SafePath\Agent;

use RuntimeException;
use SafePath\Job\Pipeline;
use SafePath\Model\MetaClient;
use SafePath\Scans;

/**
 * Multi-turn agent for the re-shoot. A construction fix must be seen, not inferred from a missed
 * detection, so the agent calls verify_after on each one. The verdict is what the before/after
 * comparison returned: the agent can look again, but cannot declare a fix itself.
 */
final class VerifyAgent
{
    private const MAX_TURNS = 12;

    private const SYSTEM = <<<'TXT'
You check whether home-accessibility repairs were really done after a re-shoot.
1. Call get_items to see the construction items that no longer show up in the re-shoot.
2. Call verify_after once for every item. If the result is "unsure", you may call it once more with a more specific question.
3. Call finish with a 1-2 sentence summary for the family: what was confirmed and what still needs a person to check.
Report only what the tools returned. Do not give health advice. Write summary_zh in Traditional Chinese (Taiwan) and summary_en in plain English.
TXT;

    private const COMPARE_SYSTEM = <<<'TXT'
You check whether a home-accessibility fix was really done, by comparing a BEFORE photo (first image) with an AFTER photo (second image) of the same spot.
Judge only what is visible. Answer "unsure" if the spot is hidden or the photos do not match. Do not give health advice. Do not estimate measurements.
TXT;

    private const FIX = [
        'threshold' => 'Is the raised door threshold now handled, for example covered by a ramp or levelled?',
        'wet_floor' => 'Is the wet floor now dry or covered by a non-slip mat or non-slip treatment?',
        'grab_bar' => 'Is there now a grab bar fixed to the wall near the toilet?',
        'doorway' => 'Has the doorway been visibly widened?',
    ];

    /** @var array<string, array> */
    private array $items = [];
    /** @var array<string, array{verdict: string, evidence: string}> */
    private array $checks = [];
    private ?array $summary = null;
    private int $beforeScan = 0;
    private int $afterScan = 0;

    public function __construct(private MetaClient $meta)
    {
    }

    /**
     * @param array<int, array> $hazards parent-scan hazards that vanished in the re-shoot
     * @return array{checks: array<int, array{verdict: string, evidence: string}>, summary: ?array} checks by hazard id
     */
    public function run(array $hazards, int $beforeScan, int $afterScan): array
    {
        $this->beforeScan = $beforeScan;
        $this->afterScan = $afterScan;
        foreach (array_values($hazards) as $i => $h) {
            $this->items['V' . ($i + 1)] = $h;
        }
        if ($this->items === []) {
            return ['checks' => [], 'summary' => null];
        }
        (new AgentLoop($this->meta, $afterScan, 'verify'))->run(
            self::SYSTEM,
            self::tools(),
            'Verify the repairs for this re-shoot.',
            $this->dispatch(...),
            fn () => $this->summary !== null,
            self::MAX_TURNS
        );
        $byHazard = [];
        foreach ($this->items as $ref => $h) {
            // An item the agent never checked stays unconfirmed: it is not a pass.
            $byHazard[(int) $h['id']] = $this->checks[$ref] ?? ['verdict' => 'unsure', 'evidence' => 'Not confirmed by the agent.'];
        }
        return ['checks' => $byHazard, 'summary' => $this->summary];
    }

    private function dispatch(string $name, array $args): array
    {
        return match ($name) {
            'get_items' => $this->getItems(),
            'verify_after' => $this->verifyAfter($args),
            'finish' => $this->finish($args),
            default => ['error' => 'unknown tool'],
        };
    }

    private function getItems(): array
    {
        $list = [];
        foreach ($this->items as $ref => $h) {
            $list[] = ['item' => $ref, 'room' => $h['room'], 'name' => $h['name'], 'finding_before' => $h['note_en']];
        }
        return ['items' => $list];
    }

    private function verifyAfter(array $args): array
    {
        $ref = (string) ($args['item'] ?? '');
        $h = $this->items[$ref] ?? throw new RuntimeException('no such item: ' . $ref);
        $question = self::FIX[$h['type']] ?? ('Has this been fixed: ' . $h['name'] . '?');
        $extra = trim((string) ($args['question'] ?? ''));
        // The agent's wording is often only a rephrase, so its first look uses the fixed question.
        if ($extra !== '' && isset($this->checks[$ref])) {
            $question .= ' ' . $extra;
        }
        $check = $this->meta->sparkVisionJson(self::COMPARE_SYSTEM, $question, [
            $this->photo($this->beforeScan, (int) $h['frame_idx']),
            $this->photo($this->afterScan, (int) $h['frame_idx']),
        ], 'fix_check', [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['fixed', 'not_fixed', 'unsure']],
                'evidence' => ['type' => 'string'],
            ],
            'required' => ['verdict', 'evidence'],
            'additionalProperties' => false,
        ]);
        $this->checks[$ref] = $check;
        return $check;
    }

    private function finish(array $args): array
    {
        $missing = array_values(array_diff(array_keys($this->items), array_keys($this->checks)));
        if ($missing !== []) {
            throw new RuntimeException('call verify_after first for: ' . implode(', ', $missing));
        }
        $this->summary = ['summary_zh' => (string) ($args['summary_zh'] ?? ''), 'summary_en' => (string) ($args['summary_en'] ?? '')];
        return ['ok' => true];
    }

    private function photo(int $scanId, int $idx): string
    {
        $rows = Scans::rows('SELECT blurred_path FROM frames WHERE scan_id = ? AND idx = ?', [$scanId, $idx]);
        return Pipeline::root() . '/' . $rows[0]['blurred_path'];
    }

    private static function tools(): array
    {
        return [
            AgentLoop::tool('get_items', 'List the construction items that vanished in the re-shoot and need visual confirmation.', [], []),
            AgentLoop::tool('verify_after', 'Compare the before and after photos of an item. The verdict is recorded as returned.', [
                'item' => ['type' => 'string', 'description' => 'Item reference such as V1'],
                'question' => ['type' => 'string', 'description' => 'Optional, more specific question for a second look'],
            ], ['item']),
            AgentLoop::tool('finish', 'Finish after every item has been checked.', [
                'summary_zh' => ['type' => 'string'],
                'summary_en' => ['type' => 'string'],
            ], ['summary_zh', 'summary_en']),
        ];
    }
}
