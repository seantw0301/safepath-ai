<?php

declare(strict_types=1);

namespace SafePath;

use RuntimeException;
use SafePath\Infra\Db;
use SafePath\Job\Pipeline;
use SafePath\Job\Queue;
use SafePath\Vision\SamTool;

/** Creating scans and reading them back; shared by the API, web pages and the demo seeder. */
final class Scans
{
    public static function createHousehold(string $name, string $mobility, int $level, int $category): int
    {
        if (!in_array($mobility, ['independent', 'walker', 'wheelchair'], true)) {
            throw new RuntimeException('invalid mobility');
        }
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO households (name, mobility, ltc_level, ltc_category) VALUES (?, ?, ?, ?)')
            ->execute([$name, $mobility, $level, $category]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<int, array{path: string, room: string, px_per_cm: ?float}> $photos
     */
    public static function create(int $householdId, array $photos, ?int $parentId = null, bool $simulated = false, bool $demo = false): int
    {
        $pdo = Db::pdo();
        $pdo->prepare("INSERT INTO scans (household_id, parent_scan_id, status, simulated, is_demo) VALUES (?, ?, 'uploaded', ?, ?)")
            ->execute([$householdId, $parentId, (int) $simulated, (int) $demo]);
        $scanId = (int) $pdo->lastInsertId();
        $dir = Pipeline::scanDir($scanId) . '/photos';
        mkdir($dir, 0775, true);
        foreach ($photos as $i => $photo) {
            $to = sprintf('%s/%d.jpg', $dir, $i);
            // Applies the phone's EXIF rotation, strips metadata such as GPS, and caps the size.
            SamTool::run('normalize', ['image' => $photo['path'], 'out' => $to]);
            $source = $photo['px_per_cm'] !== null ? 'simulated' : null;
            $pdo->prepare('INSERT INTO frames (scan_id, idx, path, room, px_per_cm, scale_source) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$scanId, $i, sprintf('storage/scans/%d/photos/%d.jpg', $scanId, $i), $photo['room'], $photo['px_per_cm'], $source]);
        }
        Queue::push($scanId, 'segment');
        return $scanId;
    }

    /** Walkthrough video: frames are created by the 'prepare' job. */
    public static function createFromVideo(int $householdId, string $videoPath, ?int $parentId = null, bool $simulated = false, bool $demo = false): int
    {
        $pdo = Db::pdo();
        $pdo->prepare("INSERT INTO scans (household_id, parent_scan_id, status, simulated, is_demo) VALUES (?, ?, 'uploaded', ?, ?)")
            ->execute([$householdId, $parentId, (int) $simulated, (int) $demo]);
        $scanId = (int) $pdo->lastInsertId();
        mkdir(Pipeline::scanDir($scanId) . '/photos', 0775, true);
        $rel = sprintf('storage/scans/%d/source.video', $scanId);
        copy($videoPath, Pipeline::root() . '/' . $rel);
        $pdo->prepare('UPDATE scans SET video_path = ? WHERE id = ?')->execute([$rel, $scanId]);
        Queue::push($scanId, 'prepare');
        return $scanId;
    }

    /**
     * Demo only: a video already normalised, with its evidence frames extracted. Skipping ffmpeg keeps
     * every file byte-identical on any host, so the cached model answers still apply.
     *
     * @param array{frames: int, keyframes: array<int, int>} $manifest
     */
    public static function createFromPreparedVideo(int $householdId, string $dir, array $manifest, ?int $parentId, string $room): int
    {
        $pdo = Db::pdo();
        $pdo->prepare("INSERT INTO scans (household_id, parent_scan_id, status, simulated, is_demo, video_path, video_frames) VALUES (?, ?, 'uploaded', 1, 1, ?, ?)")
            ->execute([$householdId, $parentId, 'demo', $manifest['frames']]);
        $scanId = (int) $pdo->lastInsertId();
        $photos = Pipeline::scanDir($scanId) . '/photos';
        mkdir($photos, 0775, true);
        copy("$dir/walk.mp4", Pipeline::scanDir($scanId) . '/walk.mp4');
        foreach ($manifest['keyframes'] as $idx => $frame) {
            copy("$dir/$frame.jpg", "$photos/$frame.jpg");
            $pdo->prepare('INSERT INTO frames (scan_id, idx, path, room, source_frame) VALUES (?, ?, ?, ?, ?)')
                ->execute([$scanId, $idx, sprintf('storage/scans/%d/photos/%d.jpg', $scanId, $frame), $room, $frame]);
        }
        Queue::push($scanId, 'segment');
        return $scanId;
    }

    public static function get(int $id): ?array
    {
        $q = Db::pdo()->prepare('SELECT s.*, h.name AS household, h.mobility, h.ltc_level, h.ltc_category FROM scans s JOIN households h ON h.id = s.household_id WHERE s.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function rows(string $sql, array $args): array
    {
        $q = Db::pdo()->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    public static function report(int $id): ?array
    {
        $scan = self::get($id);
        if ($scan === null) {
            return null;
        }
        $hazards = self::rows('SELECT * FROM hazards WHERE scan_id = ? ORDER BY frame_idx, id', [$id]);
        foreach ($hazards as $i => $h) {
            $hazards[$i]['evidence'] = json_decode((string) $h['evidence_json'], true);
            $hazards[$i]['bounds'] = json_decode((string) $h['bounds_json'], true);
            unset($hazards[$i]['evidence_json'], $hazards[$i]['bounds_json']);
        }
        $pack = self::rows('SELECT * FROM subsidy_packages WHERE scan_id = ?', [$id])[0] ?? null;
        return [
            'scan' => $scan,
            'summary' => json_decode((string) $scan['summary_json'], true),
            'frames' => self::rows('SELECT * FROM frames WHERE scan_id = ? ORDER BY idx', [$id]),
            'hazards' => $hazards,
            'work_orders' => self::rows('SELECT * FROM work_orders WHERE scan_id = ? ORDER BY id', [$id]),
            'subsidy' => $pack === null ? null : json_decode((string) $pack['items_json'], true),
            'steps' => self::rows('SELECT agent, tool, input_json, output_json, ms FROM scan_steps WHERE scan_id = ? ORDER BY id', [$id]),
            'verify_scan' => self::rows('SELECT id, status FROM scans WHERE parent_scan_id = ? ORDER BY id DESC LIMIT 1', [$id])[0] ?? null,
        ];
    }
}
