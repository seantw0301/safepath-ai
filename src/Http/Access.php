<?php

declare(strict_types=1);

namespace SafePath\Http;

use SafePath\Infra\Env;
use SafePath\Scans;

/**
 * Judge-safe mode for a public host: demos are open and read-only, live uploads are off
 * or capped per day, and an uploaded scan is visible only to the browser that made it.
 */
final class Access
{
    public static function judgeMode(): bool
    {
        return Env::get('JUDGE_MODE', '0') === '1';
    }

    public static function liveUploads(): bool
    {
        return !self::judgeMode() || Env::get('LIVE_UPLOADS', '0') === '1';
    }

    /** @return string|null why this upload is refused, or null if it may go ahead */
    public static function uploadRefusal(): ?string
    {
        if (!self::liveUploads()) {
            return 'live_uploads_disabled';
        }
        if (self::judgeMode()) {
            $since = gmdate('Y-m-d H:i:s', time() - 86400);
            $used = (int) Scans::rows('SELECT COUNT(*) AS n FROM scans WHERE is_demo = 0 AND created_at >= ?', [$since])[0]['n'];
            if ($used >= (int) Env::get('LIVE_UPLOADS_PER_DAY', '3')) {
                return 'daily_upload_limit_reached';
            }
        }
        return null;
    }

    public static function remember(int $scanId): void
    {
        if (self::judgeMode()) {
            self::start();
            $_SESSION['scans'][] = $scanId;
        }
    }

    public static function canView(array $scan): bool
    {
        if (!self::judgeMode() || $scan['is_demo']) {
            return true;
        }
        self::start();
        return in_array((int) $scan['id'], $_SESSION['scans'] ?? [], true);
    }

    /** Demos stay as published; on a public host nobody can edit findings. */
    public static function canEdit(array $scan): bool
    {
        return !$scan['is_demo'] && !self::judgeMode();
    }

    private static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
