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
            'just over the borderline, walker' => [5, 'walker', 'red'],
        ];
    }

    #[DataProvider('thresholds')]
    public function testThresholdVerdictDependsOnHeightAndMobility(int $cm, string $mobility, string $level): void
    {
        $hazards = $this->evaluate(['threshold' => [$this->object($cm)]], self::SCALE, $mobility);
        self::assertSame($level, $hazards[0]['level']);
        self::assertSame((float) $cm, $hazards[0]['value_cm']);
    }

    private function thresholdOfCm(float $cm, string $mobility = 'walker', ?array $size = null): array
    {
        $object = ['bounds' => [100, 100, 300, 100 + $cm * self::SCALE], 'label' => 't', 'confidence' => 0.9];
        return (new RuleEngine())->evaluate(['threshold' => [$object]], self::SCALE, $mobility, $size)[0];
    }

    public static function borderline(): array
    {
        // Real-photo validation: 3.0 cm measured as 3.8 cm, so ±1 cm around each limit is not decided by the app.
        return [[2.0], [2.5], [3.0], [3.8], [4.0], [0.0], [0.5], [1.5]];
    }

    #[DataProvider('borderline')]
    public function testThresholdNearALimitAsksForAManualMeasurement(float $cm): void
    {
        $hazard = $this->thresholdOfCm($cm, 'wheelchair');
        self::assertSame('unknown', $hazard['level']);
        self::assertSame([], $hazard['codes']);
        self::assertSame(1.0, $hazard['error_cm']);
        self::assertStringContainsString('需人工量測', $hazard['note']);
        self::assertStringContainsString('borderline', $hazard['note_en']);
    }

    public function testJustOutsideTheMarginIsDecidedAgain(): void
    {
        self::assertSame('red', $this->thresholdOfCm(4.1)['level']);
        self::assertSame('yellow', $this->thresholdOfCm(1.8)['level']);
        self::assertSame('red', $this->thresholdOfCm(1.8, 'wheelchair')['level']);
    }

    public function testEveryMeasuredHeightIsLabelledAReferenceWithItsError(): void
    {
        $hazard = $this->thresholdOfCm(8);
        self::assertSame(1.0, $hazard['error_cm']);
        self::assertStringContainsString('參考值', $hazard['note']);
        self::assertStringContainsString('± 1.0', $hazard['note_en']);
    }

    public function testThresholdCutOffByThePhotoEdgeHasNoHeight(): void
    {
        $hazard = $this->thresholdOfCm(8, 'walker', [300, 180]); // its bottom edge is the photo's bottom edge
        self::assertSame('unknown', $hazard['level']);
        self::assertNull($hazard['value_cm']);
        self::assertSame('red', $this->thresholdOfCm(8, 'walker', [1000, 1000])['level']);
    }

    public function testDoorwayCutOffByThePhotoEdgeIsNotMeasured(): void
    {
        $door = ['bounds' => [0, 0, 700, 2000], 'label' => 'door', 'confidence' => 0.9];
        self::assertSame([], (new RuleEngine())->evaluate(['doorway' => [$door]], self::SCALE, 'walker', [1440, 1920]));
        self::assertNotSame([], (new RuleEngine())->evaluate(['doorway' => [$door]], self::SCALE, 'walker'));
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
