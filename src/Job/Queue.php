<?php

declare(strict_types=1);

namespace SafePath\Job;

use SafePath\Infra\Db;

/** Job queue on the jobs table; no broker needed. */
final class Queue
{
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
        $claim = $pdo->prepare("UPDATE jobs SET status = 'running', attempt = attempt + 1 WHERE id = ? AND status = 'queued'");
        $claim->execute([$job['id']]);
        return $claim->rowCount() === 1 ? $job : null;
    }

    public static function finish(int $id, ?string $error = null): void
    {
        Db::pdo()->prepare('UPDATE jobs SET status = ?, error = ? WHERE id = ?')
            ->execute([$error === null ? 'done' : 'failed', $error, $id]);
    }
}
