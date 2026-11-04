<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\TestCase;
use SafePath\Infra\Db;
use SafePath\Job\Queue;

/** A worker that died must not leave a job 'running' forever. */
final class QueueRecoveryTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        $this->db = tempnam(sys_get_temp_dir(), 'queue') . '.sqlite';
        putenv('DB_DRIVER=sqlite');
        putenv('DB_SQLITE_PATH=' . $this->db);
        Db::reset();
        Db::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/sqlite/001_init.sql'));
        Db::pdo()->exec("INSERT INTO households (id, name) VALUES (1, 'h')");
        Db::pdo()->exec("INSERT INTO scans (id, household_id, status) VALUES (7, 1, 'segmenting')");
    }

    protected function tearDown(): void
    {
        Db::reset();
        @unlink($this->db);
        putenv('DB_DRIVER');
        putenv('DB_SQLITE_PATH');
    }

    private function job(int $id, int $attempt, ?int $secondsAgo): void
    {
        $beat = $secondsAgo === null ? null : gmdate('Y-m-d H:i:s', time() - $secondsAgo);
        Db::pdo()->prepare("INSERT INTO jobs (id, scan_id, type, status, attempt, heartbeat_at) VALUES (?, 7, 'segment', 'running', ?, ?)")
            ->execute([$id, $attempt, $beat]);
    }

    private function row(string $table, int $id): array
    {
        $q = Db::pdo()->prepare("SELECT * FROM $table WHERE id = ?");
        $q->execute([$id]);
        return $q->fetch();
    }

    public function testStaleJobGoesBackToTheQueue(): void
    {
        $this->job(23, 1, 900);
        self::assertSame(['requeued' => 1, 'failed' => 0], Queue::recover(300, 3));
        self::assertSame('queued', $this->row('jobs', 23)['status']);
        self::assertNotNull(Queue::claim(), 'a recovered job can be claimed again');
    }

    public function testJobWithNoHeartbeatAtAllIsRecovered(): void
    {
        $this->job(24, 1, null);
        self::assertSame(1, Queue::recover(300, 3)['requeued']);
    }

    public function testJobThatKeepsDyingIsFailedWithAReason(): void
    {
        $this->job(25, 3, 900);
        self::assertSame(['requeued' => 0, 'failed' => 1], Queue::recover(300, 3));
        $job = $this->row('jobs', 25);
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('stopped responding', $job['error']);
        self::assertSame('failed', $this->row('scans', 7)['status']);
    }

    public function testHealthyRunningJobIsLeftAlone(): void
    {
        $this->job(26, 1, 30);
        self::assertSame(['requeued' => 0, 'failed' => 0], Queue::recover(300, 3));
        self::assertSame('running', $this->row('jobs', 26)['status']);
    }

    public function testClaimStartsTheHeartbeat(): void
    {
        Db::pdo()->exec("INSERT INTO jobs (id, scan_id, type) VALUES (27, 7, 'segment')");
        Queue::claim();
        self::assertNotNull($this->row('jobs', 27)['heartbeat_at']);
        self::assertSame(['requeued' => 0, 'failed' => 0], Queue::recover(300, 3));
        Queue::finish(27);
    }
}
