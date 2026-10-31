<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SafePath\Rules\RuleEngine;

final class RuleEngineTest extends TestCase
{
    private const SCALE = 10.0; // 10 pixels per cm

    private function object(int $heightCm = 5, int $widthCm = 100, array $extra = []): array
    {
        $bounds = [0, 0, $widthCm * self::SCALE, $heightCm * self::SCALE];
        return ['bounds' => $bounds, 'label' => 'thing', 'mask' => 'm.png', 'confidence' => 0.9] + $extra;
    }

    private function evaluate(array $detections, ?float $scale = self::SCALE, string $mobility = 'walker'): array
    {
        return (new RuleEngine())->evaluate($detections, $scale, $mobility);
    }

    public static function thresholds(): array
    {
        return [
            'tall, walker' => [18, 'walker', 'red'],
            'tall, unaided' => [18, 'independent', 'yellow'],
            'bevel band, wheelchair' => [2, 'wheelchair', 'red'],
            'bevel band, walker' => [2, 'walker', 'yellow'],
        ];
    }

    #[DataProvider('thresholds')]
    public function testThresholdVerdictDependsOnHeightAndMobility(int $cm, string $mobility, string $level): void
    {
        $hazards = $this->evaluate(['threshold' => [$this->object($cm)]], self::SCALE, $mobility);
        self::assertSame($level, $hazards[0]['level']);
        self::assertSame((float) $cm, $hazards[0]['value_cm']);
    }

    public function testThresholdAtTheBevelLimitIsGreenAndClaimsNoSubsidy(): void
    {
        $hazard = (new RuleEngine())->evaluate(['threshold' => [['bounds' => [0, 0, 100, 5], 'label' => 't']]], self::SCALE, 'wheelchair')[0];
        self::assertSame('green', $hazard['level']);
        self::assertSame([], $hazard['codes']);
    }

    public function testNoScaleMeansUnknownAndNeverAGuessedNumber(): void
    {
        $hazard = $this->evaluate(['threshold' => [$this->object()]], null)[0];
        self::assertSame('unknown', $hazard['level']);
        self::assertNull($hazard['value_cm']);
    }

    public static function subsidyBands(): array
    {
        return [[8, 'FA30'], [10, 'FA30'], [15, 'FA31'], [25, 'FA32'], [35, 'FA33']];
    }

    #[DataProvider('subsidyBands')]
    public function testSubsidyCodeFollowsTheOfficialHeightBands(int $cm, string $code): void
    {
        $hazard = $this->evaluate(['threshold' => [$this->object($cm)]])[0];
        self::assertSame($code, $hazard['codes'][0]);
    }

    public function testBevelIsOfferedOnlyUpToTenCentimetres(): void
    {
        self::assertContains('FA25', $this->evaluate(['threshold' => [$this->object(8)]])[0]['codes']);
        self::assertNotContains('FA25', $this->evaluate(['threshold' => [$this->object(15)]])[0]['codes']);
    }

    public function testEveryCodeExistsInTheOfficialTable(): void
    {
        $table = json_decode((string) file_get_contents(__DIR__ . '/../../config/subsidy/tw-ltc2-2026.json'), true);
        $hazards = $this->evaluate([
            'threshold' => [$this->object(8), $this->object(35)],
            'doorway' => [$this->object(10, 70)],
            'wet_floor' => [$this->object()],
            'toilet' => [$this->object()],
        ], self::SCALE, 'wheelchair');
        foreach ($hazards as $hazard) {
            foreach ($hazard['codes'] as $code) {
                self::assertArrayHasKey($code, $table['items']);
            }
        }
    }

    public function testFloorObjectOnThePathMustBeCleared(): void
    {
        $hazard = $this->evaluate(['cable' => [$this->object(extra: ['on_path_ratio' => 0.4])]])[0];
        self::assertSame(['red', 'clear'], [$hazard['level'], $hazard['action']]);
    }

    public function testFloorObjectOffThePathIsOnlyNoted(): void
    {
        $hazard = $this->evaluate(['clutter' => [$this->object(extra: ['on_path_ratio' => 0.0])]])[0];
        self::assertSame(['green', 'noted'], [$hazard['level'], $hazard['action']]);
    }

    public function testRugOffThePathStillWarns(): void
    {
        $hazard = $this->evaluate(['rug' => [$this->object(extra: ['on_path_ratio' => 0.0])]])[0];
        self::assertSame('yellow', $hazard['level']);
    }

    public function testToiletWithoutGrabBarIsReportedAndABarClearsIt(): void
    {
        $toilet = $this->object();
        self::assertSame('grab_bar', $this->evaluate(['toilet' => [$toilet]])[0]['type']);
        self::assertSame([], $this->evaluate(['toilet' => [$toilet], 'grab_bar' => [$this->object()]]));
    }

    public function testNarrowDoorwayIsFlaggedAndImplausibleWidthIsIgnored(): void
    {
        self::assertSame(['FA38'], $this->evaluate(['doorway' => [$this->object(200, 70)]])[0]['codes']);
        self::assertSame([], $this->evaluate(['doorway' => [$this->object(200, 90)]]));
        self::assertSame([], $this->evaluate(['doorway' => [$this->object(200, 20)]]));
    }

    public function testDoorwayReasonIsAReferenceWithItsCitations(): void
    {
        $hazard = $this->evaluate(['doorway' => [$this->object(200, 70)]])[0];
        self::assertStringContainsString('205.2.3', $hazard['note']);
        self::assertStringContainsString('504.2', $hazard['note_en']);
        self::assertStringContainsString('參考值', $hazard['note']);
        self::assertStringNotContainsString('違規', $hazard['note']);
    }

    public function testEveryReasonIsBilingual(): void
    {
        $hazards = $this->evaluate(['threshold' => [$this->object()], 'wet_floor' => [$this->object()], 'toilet' => [$this->object()]]);
        foreach ($hazards as $hazard) {
            self::assertNotSame('', $hazard['note']);
            self::assertNotSame('', $hazard['note_en']);
            self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $hazard['note_en']);
        }
    }
}
