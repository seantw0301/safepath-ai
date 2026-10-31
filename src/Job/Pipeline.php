<?php

declare(strict_types=1);

namespace SafePath\Job;

use RuntimeException;
use SafePath\Agent\CaseAgent;
use SafePath\Agent\VerifyAgent;
use SafePath\Infra\Db;
use SafePath\Infra\Env;
use SafePath\Model\MetaClient;
use SafePath\Rules\RuleEngine;
use SafePath\Scans;
use SafePath\Subsidy\SubsidyPack;
use SafePath\Vision\SamTool;

/** segment → measure → assess → paperwork | verify. Each stage saves its result, so a failure resumes there. */
final class Pipeline
{
    private const VIDEO_FPS = 3;
    private const VIDEO_MAX_SECONDS = 60;
    private const VIDEO_KEYFRAMES = 6;
    private const VIDEO_ROOM = 'Walkthrough';
    private const VIDEO_EVERY_FRAME = ['floor', 'doorway', 'toilet', 'grab_bar', 'reference', 'person'];

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
            'prepare' => $this->prepare($scanId),
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

    /** Video: re-encode to a low frame rate (SAM bills per frame) and pull evenly spaced evidence frames. */
    private function prepare(int $scanId): string
    {
        $this->setStatus($scanId, 'preparing');
        $scan = Scans::get($scanId);
        $dir = self::scanDir($scanId);
        $source = self::root() . '/' . $scan['video_path'];
        $video = "$dir/walk.mp4";
        $this->ffmpeg(['-i', $source, '-t', (string) self::VIDEO_MAX_SECONDS, '-vf', 'fps=' . self::VIDEO_FPS . ",scale='min(1280,iw)':-2", '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $video]);
        $this->ffmpeg(['-i', $video, '-fps_mode', 'passthrough', "$dir/photos/%d.jpg"]);
        $files = glob("$dir/photos/*.jpg");
        $count = count($files);
        if ($count === 0) {
            throw new RuntimeException('no frames could be read from this video');
        }
        $step = max(1, (int) ceil($count / self::VIDEO_KEYFRAMES));
        $idx = 0;
        // ffmpeg numbers images from 1, SAM numbers frames from 0.
        for ($n = 0; $n < $count; $n += $step) {
            Db::pdo()->prepare('INSERT INTO frames (scan_id, idx, path, room, source_frame) VALUES (?, ?, ?, ?, ?)')
                ->execute([$scanId, $idx++, sprintf('storage/scans/%d/photos/%d.jpg', $scanId, $n + 1), self::VIDEO_ROOM, $n]);
        }
        Db::pdo()->prepare('UPDATE scans SET video_frames = ? WHERE id = ?')->execute([$count, $scanId]);
        return 'segment';
    }

    private function ffmpeg(array $args): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', array_merge([(string) Env::get('FFMPEG_BIN', 'ffmpeg'), '-y', '-loglevel', 'error'], $args)));
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('could not read this video');
        }
    }

    /** One SAM video call per prompt, then each tracked object is reported once, on the frame where it looks largest. */
    private function segmentVideo(int $scanId, array $scan, array $frames, float $min): void
    {
        $video = self::scanDir($scanId) . '/walk.mp4';
        $keep = implode(',', array_column($frames, 'source_frame'));
        $byFrame = array_column($frames, 'idx', 'source_frame');
        $maskDir = self::scanDir($scanId) . '/det/masks';
        @mkdir($maskDir, 0775, true);
        foreach ($frames as $f) {
            @mkdir(self::scanDir($scanId) . '/det/' . $f['idx'], 0775, true);
        }
        foreach ($this->config['classes'] as $class => $def) {
            $perFrame = array_fill_keys(array_column($frames, 'idx'), []);
            foreach ($def['prompts'] as $prompt) {
                $t = microtime(true);
                $raw = $this->meta->samVideo($video, $prompt, (int) $scan['video_frames']);
                $label = (string) preg_replace('/\W+/', '_', $prompt);
                $parsed = SamTool::run('parse', ['media' => 'video', 'label' => $label, 'out-dir' => $maskDir, 'keep-frames' => $keep], $raw);
                $found = array_values(array_filter($parsed['objects'], static fn ($o) => ($o['confidence'] ?? 0) >= $min));
                // Scene context must exist on every frame; hazards should appear only once.
                $objects = in_array($class, self::VIDEO_EVERY_FRAME, true) ? $found : $this->largestPerTrack($found);
                $this->step($scanId, 'sam_track', ['prompt' => $prompt], ['tracks' => count(array_unique(array_column($found, 'object_id')))], $t);
                foreach ($objects as $o) {
                    $idx = $byFrame[$o['frame']];
                    $o['frame'] = 0; // overlay/measure treat each evidence frame as a single image
                    foreach ($perFrame[$idx] as $m) {
                        if ($this->iou($o['bounds'], $m['bounds']) > 0.5) {
                            continue 2;
                        }
                    }
                    $perFrame[$idx][] = $o;
                }
            }
            foreach ($perFrame as $idx => $objects) {
                file_put_contents(self::scanDir($scanId) . "/det/$idx/$class.json", json_encode(['objects' => $objects]));
            }
        }
        foreach ($frames as $f) {
            $dir = self::scanDir($scanId) . '/det/' . $f['idx'];
            SamTool::run('blur', ['image' => self::root() . '/' . $f['path'], 'objects' => ["$dir/person.json"], 'out' => "$dir/blurred.jpg"]);
            Db::pdo()->prepare('UPDATE frames SET blurred_path = ? WHERE id = ?')->execute([$this->rel("$dir/blurred.jpg"), $f['id']]);
        }
    }

    private function largestPerTrack(array $objects): array
    {
        $best = [];
        foreach ($objects as $o) {
            if (!isset($best[$o['object_id']]) || $o['area_px'] > $best[$o['object_id']]['area_px']) {
                $best[$o['object_id']] = $o;
            }
        }
        return array_values($best);
    }

    /** SAM per class and prompt; results land in det/<frame>/<class>.json with duplicates merged. */
    private function segment(int $scanId): string
    {
        $this->setStatus($scanId, 'segmenting');
        $min = (float) $this->config['min_confidence'];
        $scan = Scans::get($scanId);
        if ($scan['video_path'] !== null) {
            $this->segmentVideo($scanId, $scan, $this->frames($scanId), $min);
            return 'measure';
        }
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
        $isVideo = Scans::get($scanId)['video_path'] !== null;
        $grabBarSeen = $isVideo && $this->anyDetected($scanId, 'grab_bar');
        $grabBarReported = false;
        foreach ($this->frames($scanId) as $frame) {
            $dir = self::scanDir($scanId) . '/det/' . $frame['idx'];
            $detections = $this->loadDetections($dir);
            $scale = $frame['px_per_cm'] === null ? null : (float) $frame['px_per_cm'];
            if ($frame['scale_source'] === 'simulated') {
                $detections['doorway'] = []; // the simulated scale assumes an 80 cm door, so measuring the door would be circular
            }
            $t = microtime(true);
            $hazards = $engine->evaluate($detections, $scale, $scan['mobility']);
            if ($isVideo) {
                // One walkthrough, one finding: a bar seen anywhere counts, and a missing bar is reported once.
                $hazards = array_values(array_filter($hazards, static function ($h) use ($grabBarSeen, &$grabBarReported) {
                    if ($h['type'] !== 'grab_bar') {
                        return true;
                    }
                    $keep = !$grabBarSeen && !$grabBarReported;
                    $grabBarReported = true;
                    return $keep;
                }));
            }
            $spec = ['items' => [], 'corridor' => is_file("$dir/corridor.png") ? "$dir/corridor.png" : null];
            foreach ($hazards as $h) {
                $pdo->prepare('INSERT INTO hazards (scan_id, frame_idx, room, type, name, action, value_cm, level, status, note, note_en, bounds_json, evidence_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $scanId, $frame['idx'], $frame['room'], $h['type'], $h['name'], $h['action'], $h['value_cm'], $h['level'],
                    $h['action'] === 'noted' ? 'noted' : 'open', $h['note'], $h['note_en'], json_encode($h['bounds']),
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

    private function anyDetected(int $scanId, string $class): bool
    {
        foreach ($this->frames($scanId) as $frame) {
            $file = self::scanDir($scanId) . '/det/' . $frame['idx'] . "/$class.json";
            if (json_decode((string) file_get_contents($file), true)['objects'] !== []) {
                return true;
            }
        }
        return false;
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
        $result = (new CaseAgent($this->meta))->run($scanId);
        $this->step($scanId, 'agent_run', [], $result, $t, 'case_agent');
        $t = microtime(true);
        $pack = (new SubsidyPack())->build($scanId);
        $this->step($scanId, 'make_subsidy_pack', ['version' => $pack['version']], ['items' => count($pack['items'])], $t);
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
        $before = $pdo->prepare("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow', 'unknown')");
        $before->execute([$parentId]);
        $after = $pdo->prepare("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow', 'unknown')");
        $after->execute([$scanId]);
        $remaining = $after->fetchAll();
        $results = [];
        $fixed = 0;
        $used = [];
        $isVideo = Scans::get($scanId)['video_path'] !== null;
        foreach ($before->fetchAll() as $h) {
            $still = null;
            foreach ($remaining as $r) {
                $samePlace = $isVideo
                    ? $r['name'] === $h['name'] && !in_array($r['id'], $used, true) // the camera moves, so positions cannot be compared
                    : $r['room'] === $h['room'] && ($h['type'] === 'grab_bar' || $this->iou(json_decode($h['bounds_json'], true), json_decode($r['bounds_json'], true)) > 0.1);
                if ($r['type'] === $h['type'] && $samePlace) {
                    $still = $r;
                    $used[] = $r['id'];
                    break;
                }
            }
            $status = $still === null ? 'verified_fixed' : ($still['level'] === 'unknown' ? 'unknown' : 'still_open');
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
            $after = ['verified_fixed' => 'green', 'unknown' => 'unknown'][$status] ?? ($still['level'] ?? 'red'); // a Spark "not fixed" has no re-detected hazard
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
