<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SafePath\Http\Access;
use SafePath\Http\Url;

final class AccessTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['JUDGE_MODE', 'LIVE_UPLOADS', 'APP_BASE_PATH'] as $key) {
            putenv($key);
        }
    }

    public function testDemosAreReadOnlyEverywhere(): void
    {
        self::assertFalse(Access::canEdit(['id' => 1, 'is_demo' => 1]));
        self::assertTrue(Access::canEdit(['id' => 1, 'is_demo' => 0]));
    }

    public function testJudgeModeTurnsUploadsAndEditingOff(): void
    {
        putenv('JUDGE_MODE=1');
        self::assertFalse(Access::liveUploads());
        self::assertSame('live_uploads_disabled', Access::uploadRefusal());
        self::assertFalse(Access::canEdit(['id' => 1, 'is_demo' => 0]));
    }

    #[RunInSeparateProcess]
    public function testInJudgeModeAnUploadedScanIsPrivateToItsSession(): void
    {
        putenv('JUDGE_MODE=1');
        self::assertTrue(Access::canView(['id' => 7, 'is_demo' => 1]));
        self::assertFalse(Access::canView(['id' => 7, 'is_demo' => 0]));
        Access::remember(7);
        self::assertTrue(Access::canView(['id' => 7, 'is_demo' => 0]));
        self::assertFalse(Access::canView(['id' => 8, 'is_demo' => 0]));
    }

    public function testSubPathRoundTrip(): void
    {
        putenv('APP_BASE_PATH=/safepath/');
        self::assertSame('/safepath/scans/1', Url::to('/scans/1'));
        self::assertSame('/scans/1', Url::strip('/safepath/scans/1'));
        self::assertSame('/', Url::strip('/safepath'));
    }
}
