<?php

declare(strict_types=1);

namespace SafePath\Job;

use SafePath\Infra\Db;
use SafePath\Infra\Env;

/**
 * Job queue on the jobs table; no broker needed.
 * A running job sends heartbeats; one whose worker died is put back, or failed after too many attempts.
 */
final class Queue
{
    private const BEAT_EVERY_SECONDS = 10;

    private static ?int $current = null;
    private static float $lastBeat = 0;

    public static function push(int $scanId, string $type): void
    {
        Db::pdo()->prepare('INSERT INTO jobs (scan_id, type) VALUES (?, ?)')->execute([$scanId, $type]);
    }

    public static function claim(): ?array
    {
        $pdo = Db::pdo();
        $job = $pdo->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch();
        if ($job === false) {
            return null;
        }
        $claim = $pdo->prepare("UPDATE jobs SET status = 'running', attempt = attempt + 1, heartbeat_at = ? WHERE id = ? AND status = 'queued'");
        $claim->execute([self::now(), $job['id']]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        self::$current = (int) $job['id'];
        self::$lastBeat = microtime(true);
        return $job;
    }

    /** Called from long-running work (model calls, samtool); cheap, and throttled. */
    public static function beat(): void
    {
        if (self::$current === null || microtime(true) - self::$lastBeat < self::BEAT_EVERY_SECONDS) {
            return;
        }
        self::$lastBeat = microtime(true);
        Db::pdo()->prepare('UPDATE jobs SET heartbeat_at = ? WHERE id = ?')->execute([self::now(), self::$current]);
    }

    public static function finish(int $id, ?string $error = null): void
    {
        self::$current = null;
        Db::pdo()->prepare('UPDATE jobs SET status = ?, error = ? WHERE id = ?')
            ->execute([$error === null ? 'done' : 'failed', $error, $id]);
    }

    /**
     * Puts back running jobs whose heartbeat stopped, so a dead worker can't block a scan forever.
     * After too many attempts the job and its scan are marked failed with the reason.
     *
     * @return array{requeued: int, failed: int}
     */
    public static function recover(?int $timeoutSeconds = null, ?int $maxAttempts = null): array
    {
        $timeout = $timeoutSeconds ?? (int) Env::get('JOB_TIMEOUT_SECONDS', '300');
        $max = $maxAttempts ?? (int) Env::get('JOB_MAX_ATTEMPTS', '3');
        $pdo = Db::pdo();
        $stale = $pdo->prepare("SELECT * FROM jobs WHERE status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < ?)");
        $stale->execute([gmdate('Y-m-d H:i:s', time() - $timeout)]);
        $result = ['requeued' => 0, 'failed' => 0];
        foreach ($stale->fetchAll() as $job) {
            if ((int) $job['attempt'] >= $max) {
                $reason = sprintf('worker stopped responding during %s (%d attempts)', $job['type'], $job['attempt']);
                $pdo->prepare("UPDATE jobs SET status = 'failed', error = ? WHERE id = ? AND status = 'running'")->execute([$reason, $job['id']]);
                $pdo->prepare("UPDATE scans SET status = 'failed', error = ? WHERE id = ?")->execute([$reason, $job['scan_id']]);
                $result['failed']++;
            } else {
                $pdo->prepare("UPDATE jobs SET status = 'queued', heartbeat_at = NULL WHERE id = ? AND status = 'running'")->execute([$job['id']]);
                $result['requeued']++;
            }
        }
        return $result;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
