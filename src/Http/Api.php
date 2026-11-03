<?php

declare(strict_types=1);

namespace SafePath\Http;

use RuntimeException;
use SafePath\Infra\Db;
use SafePath\Job\Pipeline;
use SafePath\Model\MetaClient;
use SafePath\Scans;

/** The JSON API. Web pages call these same endpoints from the browser. */
final class Api
{
    private const MAX_PHOTOS = 12;
    private const MAX_VIDEO_BYTES = 300 * 1024 * 1024;
    private const MAX_AUDIO_BYTES = 32 * 1024 * 1024;

    private static function refused(): bool
    {
        $why = Access::uploadRefusal();
        if ($why !== null) {
            Response::json(['error' => $why], $why === 'daily_upload_limit_reached' ? 429 : 403);
            return true;
        }
        return false;
    }

    /** Returns the scan if this visitor may see it; otherwise answers 404. */
    private static function visible(int $id): ?array
    {
        $scan = Scans::get($id);
        if ($scan === null || !Access::canView($scan)) {
            Response::json(['error' => 'scan_not_found'], 404);
            return null;
        }
        return $scan;
    }

    private static function created(int $scanId): void
    {
        Access::remember($scanId);
        Response::json(['id' => $scanId], 201);
    }

    public static function createHousehold(): void
    {
        if (self::refused()) {
            return;
        }
        $in = $_POST ?: (json_decode((string) file_get_contents('php://input'), true) ?? []);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            Response::json(['error' => 'name_required'], 422);
            return;
        }
        try {
            $id = Scans::createHousehold($name, (string) ($in['mobility'] ?? 'independent'), self::between($in['ltc_level'] ?? 4, 1, 8), self::between($in['ltc_category'] ?? 3, 1, 3));
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 422);
            return;
        }
        Response::json(['id' => $id], 201);
    }

    public static function createScan(array $p): void
    {
        if (self::refused()) {
            return;
        }
        $home = (int) $p['id'];
        if (Scans::rows('SELECT id FROM households WHERE id = ?', [$home]) === []) {
            Response::json(['error' => 'household_not_found'], 404);
            return;
        }
        $video = self::uploadedVideo();
        if ($video !== null) {
            is_string($video) && !is_file($video)
                ? Response::json(['error' => $video], 422)
                : self::created(Scans::createFromVideo($home, $video));
            return;
        }
        $photos = self::uploadedPhotos();
        if (is_string($photos)) {
            Response::json(['error' => $photos], 422);
            return;
        }
        self::created(Scans::create($home, $photos));
    }

    public static function verify(array $p): void
    {
        if (self::refused()) {
            return;
        }
        $parent = self::visible((int) $p['id']);
        if ($parent === null) {
            return;
        }
        if ($parent['is_demo']) {
            Response::json(['error' => 'demo_is_read_only'], 403);
            return;
        }
        if ($parent['status'] !== 'report_ready') {
            Response::json(['error' => 'scan_not_ready_for_verify'], 409);
            return;
        }
        $video = self::uploadedVideo();
        if ($video !== null) {
            is_string($video) && !is_file($video)
                ? Response::json(['error' => $video], 422)
                : self::created(Scans::createFromVideo((int) $parent['household_id'], $video, (int) $parent['id'], (bool) $parent['simulated']));
            return;
        }
        $photos = self::uploadedPhotos();
        if (is_string($photos)) {
            Response::json(['error' => $photos], 422);
            return;
        }
        $rooms = Scans::rows('SELECT room, px_per_cm, scale_source FROM frames WHERE scan_id = ? ORDER BY idx', [$parent['id']]);
        foreach ($photos as $i => $photo) {
            $photos[$i]['room'] = $rooms[$i]['room'] ?? $photo['room'];
            $photos[$i]['px_per_cm'] = ($rooms[$i]['scale_source'] ?? null) === 'simulated' ? (float) $rooms[$i]['px_per_cm'] : null;
        }
        self::created(Scans::create((int) $parent['household_id'], $photos, (int) $parent['id'], (bool) $parent['simulated']));
    }

    public static function status(array $p): void
    {
        $scan = self::visible((int) $p['id']);
        if ($scan === null) {
            return;
        }
        $steps = Scans::rows('SELECT COUNT(*) AS n FROM scan_steps WHERE scan_id = ?', [$scan['id']])[0]['n'];
        Response::json(['id' => (int) $scan['id'], 'status' => $scan['status'], 'error' => $scan['error'], 'steps' => (int) $steps]);
    }

    public static function report(array $p): void
    {
        if (self::visible((int) $p['id']) !== null) {
            Response::json(Scans::report((int) $p['id']));
        }
    }

    public static function workOrders(array $p): void
    {
        if (self::visible((int) $p['id']) === null) {
            return;
        }
        Response::json(Scans::rows('SELECT * FROM work_orders WHERE scan_id = ? ORDER BY id', [(int) $p['id']]));
    }

    public static function packPdf(array $p): void
    {
        if (self::visible((int) $p['id']) === null) {
            return;
        }
        $rows = Scans::rows('SELECT pdf_path FROM subsidy_packages WHERE scan_id = ?', [(int) $p['id']]);
        if ($rows === [] || !is_file(Pipeline::root() . '/' . $rows[0]['pdf_path'])) {
            Response::json(['error' => 'pack_not_ready'], 404);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="safepath-subsidy-draft-' . (int) $p['id'] . '.pdf"');
        readfile(Pipeline::root() . '/' . $rows[0]['pdf_path']);
    }

    /** A person can overrule a verdict; the reason is kept. */
    public static function overrideHazard(array $p, ?string $body = null): void
    {
        $rows = Scans::rows('SELECT level, scan_id FROM hazards WHERE id = ?', [(int) $p['id']]);
        $scan = $rows === [] ? null : Scans::get((int) $rows[0]['scan_id']);
        if ($scan === null || !Access::canView($scan)) { // a hazard left over from a deleted scan counts as missing
            Response::json(['error' => 'hazard_not_found'], 404);
            return;
        }
        if (!Access::canEdit($scan)) {
            Response::json(['error' => 'read_only'], 403);
            return;
        }
        $in = json_decode($body ?? (string) file_get_contents('php://input'), true) ?? [];
        $level = (string) ($in['level'] ?? '');
        $reason = trim((string) ($in['reason'] ?? ''));
        if (!in_array($level, ['red', 'yellow', 'green', 'unknown'], true) || $reason === '') {
            Response::json(['error' => 'level_and_reason_required'], 422);
            return;
        }
        Db::pdo()->prepare('INSERT INTO hazard_overrides (hazard_id, old_level, new_level, reason) VALUES (?, ?, ?, ?)')
            ->execute([(int) $p['id'], $rows[0]['level'], $level, $reason]);
        Db::pdo()->prepare('UPDATE hazards SET level = ? WHERE id = ?')->execute([$level, (int) $p['id']]);
        Response::json(['ok' => true]);
    }

    /** Family describes how the resident moves: the audio is transcribed and discarded, only the text and mobility come back. */
    public static function voiceProfile(): void
    {
        if (self::refused()) {
            return;
        }
        $profile = self::readVoice();
        if ($profile !== null) {
            Response::json($profile);
        }
    }

    public static function voice(array $p): void
    {
        if (self::refused()) {
            return;
        }
        if (Scans::rows('SELECT id FROM households WHERE id = ?', [(int) $p['id']]) === []) {
            Response::json(['error' => 'household_not_found'], 404);
            return;
        }
        $profile = self::readVoice();
        if ($profile !== null) {
            Db::pdo()->prepare('UPDATE households SET mobility = ? WHERE id = ?')->execute([$profile['mobility'], (int) $p['id']]);
            Response::json($profile);
        }
    }

    /** @return array{transcript: string, mobility: string}|null null when it has already answered with an error */
    private static function readVoice(): ?array
    {
        $file = $_FILES['audio']['tmp_name'] ?? '';
        if ($file === '' || !is_uploaded_file($file)) {
            Response::json(['error' => 'audio_required'], 422);
            return null;
        }
        if ($_FILES['audio']['size'] > self::MAX_AUDIO_BYTES) {
            Response::json(['error' => 'audio_too_large'], 422);
            return null;
        }
        $meta = new MetaClient();
        $text = $meta->transcribe($file);
        if (trim($text) === '') {
            Response::json(['error' => 'no_speech_heard'], 422);
            return null;
        }
        $out = $meta->sparkJson(
            'From what the family said, extract how the resident moves around their home. Use "independent" if they walk unaided. '
            . 'Also return the same words as transcript: if they are Chinese, write them in Traditional Chinese (Taiwan), otherwise leave them unchanged. Do not add or remove anything. Do not give health advice.',
            $text,
            'voice_profile',
            [
                'type' => 'object',
                'properties' => [
                    'mobility' => ['type' => 'string', 'enum' => ['independent', 'walker', 'wheelchair']],
                    'transcript' => ['type' => 'string'],
                ],
                'required' => ['mobility', 'transcript'],
                'additionalProperties' => false,
            ]
        );
        return ['transcript' => $out['transcript'], 'mobility' => $out['mobility']];
    }

    /** @return string|null the uploaded file's path, an error code, or null when no video was sent */
    private static function uploadedVideo(): ?string
    {
        $file = $_FILES['video'] ?? null;
        if ($file === null || is_array($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'upload_failed';
        }
        if ($file['size'] > self::MAX_VIDEO_BYTES) {
            return 'video_too_large';
        }
        $type = mime_content_type($file['tmp_name']);
        return in_array($type, ['video/mp4', 'video/quicktime'], true) ? $file['tmp_name'] : 'video_must_be_mp4_or_mov';
    }

    /** @return array<int, array{path: string, room: string, px_per_cm: ?float}>|string */
    private static function uploadedPhotos(): array|string
    {
        $files = $_FILES['photos'] ?? null;
        if ($files === null || !is_array($files['tmp_name'])) {
            return 'photos_required';
        }
        $rooms = $_POST['rooms'] ?? [];
        $photos = [];
        foreach ($files['tmp_name'] as $i => $tmp) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                return 'upload_failed';
            }
            if (getimagesize($tmp) === false || !in_array(mime_content_type($tmp), ['image/jpeg', 'image/png'], true)) {
                return 'photos_must_be_jpeg_or_png';
            }
            $room = trim((string) ($rooms[$i] ?? ''));
            $photos[] = ['path' => $tmp, 'room' => $room !== '' ? $room : 'Room ' . (count($photos) + 1), 'px_per_cm' => null];
        }
        if ($photos === []) {
            return 'photos_required';
        }
        return count($photos) > self::MAX_PHOTOS ? 'too_many_photos' : $photos;
    }

    private static function between(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
