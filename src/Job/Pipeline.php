<?php

declare(strict_types=1);

namespace SafePath\Job;

use RuntimeException;
use SafePath\Agent\PaperworkAgent;
use SafePath\Agent\VerifyAgent;
use SafePath\Infra\Db;
use SafePath\Model\MetaClient;
use SafePath\Rules\RuleEngine;
use SafePath\Subsidy\SubsidyPack;
use SafePath\Vision\SamTool;

/** segment → measure → assess → paperwork | verify. Each stage saves its result, so a failure resumes there. */
final class Pipeline
{
    private array $config;

    public function __construct(private MetaClient $meta = new MetaClient())
    {
        $this->config = json_decode((string) file_get_contents(self::root() . '/config/rules/hazard-classes.json'), true);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function scanDir(int $scanId): string
    {
        return self::root() . '/storage/scans/' . $scanId;
    }

    public function stage(string $type, int $scanId): ?string
    {
        return match ($type) {
            'segment' => $this->segment($scanId),
            'measure' => $this->measure($scanId),
            'assess' => $this->assess($scanId),
            'paperwork' => $this->paperwork($scanId),
            'verify' => $this->verify($scanId),
            default => throw new RuntimeException('unknown job type: ' . $type),
        };
    }

    private function setStatus(int $scanId, string $status): void
    {
        Db::pdo()->prepare('UPDATE scans SET status = ? WHERE id = ?')->execute([$status, $scanId]);
    }

    private function frames(int $scanId): array
    {
        $q = Db::pdo()->prepare('SELECT * FROM frames WHERE scan_id = ? ORDER BY idx');
        $q->execute([$scanId]);
        return $q->fetchAll();
    }

    private function step(int $scanId, string $tool, array $input, array $output, float $since, ?string $agent = null): void
    {
        Db::pdo()->prepare('INSERT INTO scan_steps (scan_id, agent, tool, input_json, output_json, ms) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$scanId, $agent, $tool, json_encode($input, JSON_UNESCAPED_UNICODE), json_encode($output, JSON_UNESCAPED_UNICODE), (int) ((microtime(true) - $since) * 1000)]);
    }

    /** SAM per class and prompt; results land in det/<frame>/<class>.json with duplicates merged. */
    private function segment(int $scanId): string
    {
        $this->setStatus($scanId, 'segmenting');
        $min = (float) $this->config['min_confidence'];
        foreach ($this->frames($scanId) as $frame) {
            $dir = self::scanDir($scanId) . '/det/' . $frame['idx'];
            @mkdir($dir . '/masks', 0775, true);
            foreach ($this->config['classes'] as $class => $def) {
                $merged = $this->detect($scanId, $frame, $def['prompts'], $dir, $min);
                file_put_contents("$dir/$class.json", json_encode(['objects' => $merged]));
            }
            $blurred = "$dir/blurred.jpg";
            SamTool::run('blur', ['image' => self::root() . '/' . $frame['path'], 'objects' => ["$dir/person.json"], 'out' => $blurred]);
            Db::pdo()->prepare('UPDATE frames SET blurred_path = ? WHERE id = ?')->execute([$this->rel($blurred), $frame['id']]);
        }
        return 'measure';
    }

    private function detect(int $scanId, array $frame, array $prompts, string $dir, float $min): array
    {
        $merged = [];
        foreach ($prompts as $prompt) {
            $t = microtime(true);
            $raw = $this->meta->samImage(self::root() . '/' . $frame['path'], $prompt);
            $label = (string) preg_replace('/\W+/', '_', $prompt);
            $parsed = SamTool::run('parse', ['media' => 'image', 'label' => $label, 'out-dir' => "$dir/masks"], $raw);
            $found = array_values(array_filter($parsed['objects'], static fn ($o) => ($o['confidence'] ?? 0) >= $min));
            $this->step($scanId, 'sam_segment', ['frame' => $frame['idx'], 'prompt' => $prompt], ['found' => count($found), 'confidence' => array_column($found, 'confidence')], $t);
            foreach ($found as $o) {
                foreach ($merged as $i => $m) {
                    if ($this->iou($o['bounds'], $m['bounds']) > 0.5) {
                        if ($o['confidence'] > $m['confidence']) {
                            $merged[$i] = $o;
                        }
                        continue 2;
                    }
                }
                $merged[] = $o;
            }
        }
        return $merged;
    }

    /** Scale for this frame, then the walking corridor and which floor objects sit on it. */
    private function measure(int $scanId): string
    {
        $this->setStatus($scanId, 'measuring');
        foreach ($this->frames($scanId) as $frame) {
            $dir = self::scanDir($scanId) . '/det/' . $frame['idx'];
            $image = self::root() . '/' . $frame['path'];
            if ($frame['scale_source'] !== 'simulated') {
                $this->calibrate($scanId, $frame, $dir, $image);
            }
            $floor = json_decode((string) file_get_contents("$dir/floor.json"), true)['objects'];
            if ($floor === []) {
                continue;
            }
            $files = array_map(static fn ($c) => "$dir/$c.json", ['rug', 'cable', 'clutter']);
            $t = microtime(true);
            $args = ['image' => $image, 'floor' => "$dir/floor.json", 'objects' => $files, 'corridor-out' => "$dir/corridor.png"];
            if (json_decode((string) file_get_contents("$dir/doorway.json"), true)['objects'] !== []) {
                $args['doorway'] = "$dir/doorway.json";
            }
            $res = SamTool::run('path', $args);
            file_put_contents("$dir/path.json", json_encode($res, JSON_UNESCAPED_UNICODE));
            $this->step($scanId, 'walking_path', ['frame' => $frame['idx']], ['objects' => $res['objects']], $t);
        }
        return 'assess';
    }

    /** Real uploads: use the sheet only if it really looks like an upright A4, otherwise leave the scale empty. */
    private function calibrate(int $scanId, array $frame, string $dir, string $image): void
    {
        $t = microtime(true);
        $res = SamTool::run('measure', ['image' => $image, 'reference' => "$dir/reference.json"]);
        $usable = isset($res['px_per_cm']) && $res['reference_aspect_error'] <= 0.15;
        $this->step($scanId, 'a4_scale', ['frame' => $frame['idx']], $res + ['usable' => $usable], $t);
        if ($usable) {
            Db::pdo()->prepare("UPDATE frames SET px_per_cm = ?, scale_source = 'a4' WHERE id = ?")->execute([$res['px_per_cm'], $frame['id']]);
        }
    }

    private function assess(int $scanId): string
    {
        $this->setStatus($scanId, 'assessing');
        $pdo = Db::pdo();
        $engine = new RuleEngine();
        $q = $pdo->prepare('SELECT h.mobility, s.parent_scan_id FROM scans s JOIN households h ON h.id = s.household_id WHERE s.id = ?');
        $q->execute([$scanId]);
        $scan = $q->fetch();
        $pdo->prepare('DELETE FROM hazards WHERE scan_id = ?')->execute([$scanId]);
        foreach ($this->frames($scanId) as $frame) {
            $dir = self::scanDir($scanId) . '/det/' . $frame['idx'];
            $detections = $this->loadDetections($dir);
            $scale = $frame['px_per_cm'] === null ? null : (float) $frame['px_per_cm'];
            if ($frame['scale_source'] === 'simulated') {
                $detections['doorway'] = []; // the simulated scale assumes an 80 cm door, so measuring the door would be circular
            }
            $t = microtime(true);
            $hazards = $engine->evaluate($detections, $scale, $scan['mobility']);
            $spec = ['items' => [], 'corridor' => is_file("$dir/corridor.png") ? "$dir/corridor.png" : null];
            foreach ($hazards as $h) {
                $pdo->prepare('INSERT INTO hazards (scan_id, frame_idx, room, type, name, action, value_cm, level, status, note, bounds_json, evidence_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $scanId, $frame['idx'], $frame['room'], $h['type'], $h['name'], $h['action'], $h['value_cm'], $h['level'],
                    $h['action'] === 'noted' ? 'noted' : 'open', $h['note'], json_encode($h['bounds']),
                    json_encode(['codes' => $h['codes'], 'confidence' => $h['confidence'], 'on_path_ratio' => $h['on_path_ratio'] ?? null]),
                ]);
                $tag = $h['name'] . ($h['value_cm'] !== null ? sprintf(' %.1f cm', $h['value_cm']) : '') . ' · ' . strtoupper($h['level']);
                $spec['items'][] = ['bounds' => $h['bounds'], 'mask' => $h['mask'], 'level' => $h['level'], 'tag' => $tag];
            }
            file_put_contents("$dir/annotate.json", json_encode($spec));
            $overlay = "$dir/overlay.jpg";
            SamTool::run('annotate', ['image' => self::root() . '/' . $frame['blurred_path'], 'spec' => "$dir/annotate.json", 'out' => $overlay]);
            $pdo->prepare('UPDATE frames SET overlay_path = ? WHERE id = ?')->execute([$this->rel($overlay), $frame['id']]);
            $this->step($scanId, 'rules_check', ['frame' => $frame['idx'], 'mobility' => $scan['mobility'], 'px_per_cm' => $scale], ['levels' => array_count_values(array_column($hazards, 'level'))], $t);
        }
        return $scan['parent_scan_id'] === null ? 'paperwork' : 'verify';
    }

    private function loadDetections(string $dir): array
    {
        $path = is_file("$dir/path.json") ? json_decode((string) file_get_contents("$dir/path.json"), true)['objects'] : [];
        $ratio = [];
        foreach ($path as $p) {
            $ratio[$p['label'] . '#' . $p['object_id']] = $p['on_path_ratio'];
        }
        $detections = [];
        foreach (array_keys($this->config['classes']) as $class) {
            $objects = json_decode((string) file_get_contents("$dir/$class.json"), true)['objects'];
            foreach ($objects as $i => $o) {
                $objects[$i]['on_path_ratio'] = $ratio[$o['label'] . '#' . $o['object_id']] ?? 0;
            }
            $detections[$class] = $objects;
        }
        return $detections;
    }

    private function paperwork(int $scanId): ?string
    {
        $t = microtime(true);
        $result = (new PaperworkAgent($this->meta))->run($scanId);
        $this->step($scanId, 'write_work_orders', ['hazards' => $result['hazards']], ['orders' => $result['orders']], $t, 'paperwork');
        $t = microtime(true);
        $pack = (new SubsidyPack())->build($scanId);
        $this->step($scanId, 'make_subsidy_pack', ['version' => $pack['version']], ['items' => count($pack['items'])], $t, 'paperwork');
        $this->setStatus($scanId, 'report_ready');
        return null;
    }

    /** Re-shoot: every red/yellow hazard of the parent scan must be gone from the same room to count as fixed. */
    private function verify(int $scanId): ?string
    {
        $this->setStatus($scanId, 'verifying');
        $pdo = Db::pdo();
        $q = $pdo->prepare('SELECT parent_scan_id FROM scans WHERE id = ?');
        $q->execute([$scanId]);
        $parentId = (int) $q->fetchColumn();
        $before = $pdo->prepare("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow')");
        $before->execute([$parentId]);
        $after = $pdo->prepare("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow', 'unknown')");
        $after->execute([$scanId]);
        $remaining = $after->fetchAll();
        $results = [];
        $fixed = 0;
        foreach ($before->fetchAll() as $h) {
            $still = null;
            foreach ($remaining as $r) {
                if ($r['type'] === $h['type'] && $r['room'] === $h['room'] && ($h['type'] === 'grab_bar' || $this->iou(json_decode($h['bounds_json'], true), json_decode($r['bounds_json'], true)) > 0.1)) {
                    $still = $r;
                    break;
                }
            }
            $status = $still === null ? 'verified_fixed' : 'still_open';
            $check = null;
            // A construction fix must be seen, not just inferred from a missed detection.
            if ($still === null && $h['action'] === 'construct') {
                $t = microtime(true);
                $check = (new VerifyAgent($this->meta))->check($h, $parentId, $scanId);
                $this->step($scanId, 'confirm_fix', ['hazard' => $h['name'], 'room' => $h['room']], $check, $t, 'verify');
                $status = match ($check['verdict']) {
                    'fixed' => 'verified_fixed',
                    'not_fixed' => 'still_open',
                    default => 'unknown',
                };
            }
            $fixed += $status === 'verified_fixed' ? 1 : 0;
            $after = ['verified_fixed' => 'green', 'unknown' => 'unknown'][$status] ?? ($still['level'] ?? 'red');
            $pdo->prepare('UPDATE hazards SET status = ? WHERE id = ?')->execute([$status, $h['id']]);
            $results[] = ['hazard_id' => (int) $h['id'], 'room' => $h['room'], 'name' => $h['name'], 'before' => $h['level'], 'after' => $after, 'status' => $status, 'confirmation' => $check['evidence'] ?? null];
        }
        $pdo->prepare('UPDATE scans SET summary_json = ? WHERE id = ?')->execute([json_encode(['verify' => $results], JSON_UNESCAPED_UNICODE), $scanId]);
        $this->setStatus($scanId, $fixed === count($results) ? 'verified' : 'partially_verified');
        return null;
    }

    private function iou(array $a, array $b): float
    {
        $w = min($a[2], $b[2]) - max($a[0], $b[0]);
        $h = min($a[3], $b[3]) - max($a[1], $b[1]);
        if ($w <= 0 || $h <= 0) {
            return 0.0;
        }
        $inter = $w * $h;
        return $inter / (($a[2] - $a[0]) * ($a[3] - $a[1]) + ($b[2] - $b[0]) * ($b[3] - $b[1]) - $inter);
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(self::root(), '', $path), '/');
    }
}
