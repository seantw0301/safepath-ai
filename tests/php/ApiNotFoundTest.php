<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SafePath\Http\Api;
use SafePath\Http\Response;
use SafePath\Infra\Db;

/** Missing scans and hazards answer 404, in normal and judge mode; nothing throws. */
final class ApiNotFoundTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        $this->db = tempnam(sys_get_temp_dir(), 'safepath') . '.sqlite';
        putenv('DB_DRIVER=sqlite');
        putenv('DB_SQLITE_PATH=' . $this->db);
        Db::reset();
        Db::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/sqlite/001_init.sql'));
        Db::pdo()->exec("INSERT INTO households (id, name) VALUES (1, 'h')");
        Db::pdo()->exec("INSERT INTO scans (id, household_id, status, is_demo) VALUES (1, 1, 'report_ready', 1)");
        Db::pdo()->exec("INSERT INTO hazards (id, scan_id, type, level) VALUES (1, 1, 'cable', 'red')");
        Db::pdo()->exec("INSERT INTO hazards (id, scan_id, type, level) VALUES (2, 99, 'cable', 'red')"); // orphan: its scan is gone
    }

    protected function tearDown(): void
    {
        Db::reset();
        @unlink($this->db);
        foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'JUDGE_MODE'] as $key) {
            putenv($key);
        }
    }

    private function statusOf(callable $call): int
    {
        Response::$lastStatus = 0;
        ob_start();
        $call();
        ob_end_clean();
        return Response::$lastStatus;
    }

    public static function modes(): array
    {
        return ['normal' => ['0'], 'judge' => ['1']];
    }

    #[DataProvider('modes')]
    #[RunInSeparateProcess]
    public function testMissingScanIs404Everywhere(string $judge): void
    {
        putenv("JUDGE_MODE=$judge");
        $missing = ['id' => '999'];
        self::assertSame(404, $this->statusOf(static fn () => Api::status($missing)));
        self::assertSame(404, $this->statusOf(static fn () => Api::report($missing)));
        self::assertSame(404, $this->statusOf(static fn () => Api::workOrders($missing)));
        self::assertSame(404, $this->statusOf(static fn () => Api::packPdf($missing)));
    }

    #[DataProvider('modes')]
    #[RunInSeparateProcess]
    public function testMissingOrOrphanHazardIs404(string $judge): void
    {
        putenv("JUDGE_MODE=$judge");
        $body = '{"level":"green","reason":"x"}';
        self::assertSame(404, $this->statusOf(static fn () => Api::overrideHazard(['id' => '999'], $body)));
        self::assertSame(404, $this->statusOf(static fn () => Api::overrideHazard(['id' => '2'], $body)));
    }

    #[DataProvider('modes')]
    #[RunInSeparateProcess]
    public function testExistingDemoHazardIsStillReadOnly(string $judge): void
    {
        putenv("JUDGE_MODE=$judge");
        self::assertSame(403, $this->statusOf(static fn () => Api::overrideHazard(['id' => '1'], '{"level":"green","reason":"x"}')));
    }
}
