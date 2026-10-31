<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SafePath\Model\UsageGuard;

final class UsageGuardTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'usage');
        unlink($this->file);
        putenv('USAGE_BUDGET_USD=1');
        putenv('USAGE_MAX_REQUESTS=5');
        putenv('COST_SAM_IMAGE=0.40');
        putenv('COST_SAM_FRAME=0.001');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        foreach (['USAGE_BUDGET_USD', 'USAGE_MAX_REQUESTS', 'COST_SAM_IMAGE', 'COST_SAM_FRAME'] as $key) {
            putenv($key);
        }
    }

    public function testCountsSpendAndRequests(): void
    {
        $guard = new UsageGuard($this->file);
        $guard->reserve('sam_frame', 100);
        $state = $guard->summary();
        self::assertEqualsWithDelta(0.1, $state['usd'], 0.0001);
        self::assertSame(1, $state['requests']);
        self::assertSame(100, $state['by_kind']['sam_frame']);
    }

    public function testBudgetIsAHardStopAndARefusedCallIsNotCharged(): void
    {
        $guard = new UsageGuard($this->file);
        $guard->reserve('sam_image');
        $guard->reserve('sam_image');
        try {
            $guard->reserve('sam_image');
            self::fail('expected the cap to stop this call');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('usage cap', $e->getMessage());
        }
        self::assertSame(2, $guard->summary()['requests']);
    }

    public function testRequestCountIsAlsoAHardStop(): void
    {
        $guard = new UsageGuard($this->file);
        for ($i = 0; $i < 5; $i++) {
            $guard->reserve('sam_frame');
        }
        $this->expectException(RuntimeException::class);
        $guard->reserve('sam_frame');
    }

    public function testStateSurvivesANewInstance(): void
    {
        (new UsageGuard($this->file))->reserve('sam_image');
        self::assertSame(1, (new UsageGuard($this->file))->summary()['requests']);
    }
}
