<?php

declare(strict_types=1);

namespace SafePath\Job;

use SafePath\Infra\Db;
use Throwable;

/** Runs one pipeline stage per job; each stage's success enqueues the next. */
final class Worker
{
    public function __construct(private Pipeline $pipeline = new Pipeline())
    {
    }

    public function runOne(): bool
    {
        $job = Queue::claim();
        if ($job === null) {
            return false;
        }
        try {
            $next = $this->pipeline->stage($job['type'], (int) $job['scan_id']);
            if ($next !== null) {
                Queue::push((int) $job['scan_id'], $next);
            }
            Queue::finish((int) $job['id']);
        } catch (Throwable $e) {
            Queue::finish((int) $job['id'], $e->getMessage());
            Db::pdo()->prepare("UPDATE scans SET status = 'failed', error = ? WHERE id = ?")
                ->execute([$e->getMessage(), $job['scan_id']]);
        }
        return true;
    }

    public function drain(): void
    {
        while ($this->runOne()) {
        }
    }
}
