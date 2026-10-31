<?php

declare(strict_types=1);

namespace SafePath\Rules;

/**
 * Deterministic verdicts: every level and number comes from here, never from the LLM.
 * Input: detections of one photo. Output: hazards with level, reason and subsidy candidates.
 */
final class RuleEngine
{
    private array $rules;

    public function __construct()
    {
        $this->rules = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/rules/rules.json'), true);
    }

    /**
     * @param array<string, array> $d       objects by class (each with bounds, confidence, mask, on_path_ratio?)
     */
    public function evaluate(array $d, ?float $pxPerCm, string $mobility): array
    {
        $hazards = [];
        foreach ($d['threshold'] ?? [] as $o) {
            $hazards[] = $this->threshold($o, $pxPerCm, $mobility);
        }
        foreach ($d['doorway'] ?? [] as $o) {
            $h = $this->doorway($o, $pxPerCm, $mobility);
            if ($h !== null) {
                $hazards[] = $h;
            }
        }
        foreach (['rug', 'cable', 'clutter'] as $class) {
            foreach ($d[$class] ?? [] as $o) {
                $hazards[] = $this->floorObject($class, $o);
            }
        }
        foreach ($d['wet_floor'] ?? [] as $o) {
            $hazards[] = $this->base('wet_floor', 'Wet floor', 'construct', $o, 'red', ['地面積水／濕滑，建議防滑處理。', 'Standing water on the floor; anti-slip treatment suggested.'], ['FA35', 'FA34']);
        }
        if (($d['toilet'] ?? []) !== [] && ($d['grab_bar'] ?? []) === []) {
            $hazards[] = $this->missingGrabBar($d['toilet'][0]);
        }
        return $hazards;
    }

    private function threshold(array $o, ?float $pxPerCm, string $mobility): array
    {
        $name = 'Door threshold';
        if ($pxPerCm === null) {
            return $this->base('threshold', $name, 'construct', $o, 'unknown', ['沒有可用的比例尺，無法量高度；請把 A4 直貼門框後補拍。', 'No usable scale, so the height is unknown. Tape an A4 sheet upright on the door frame and re-shoot.'], []);
        }
        $cm = round(($o['bounds'][3] - $o['bounds'][1]) / $pxPerCm, 1);
        $r = $this->rules['threshold'];
        $mobile = in_array($mobility, ['walker', 'wheelchair'], true);
        if ($cm > $r['ramp_over_cm']) {
            $level = $mobile ? 'red' : 'yellow';
            $reason = [sprintf('門檻高約 %.1f 公分，超過 %.0f 公分（參考值應設坡道）。', $cm, $r['ramp_over_cm']), sprintf('Threshold about %.1f cm, above the %.0f cm reference for a ramp.', $cm, $r['ramp_over_cm'])];
        } elseif ($cm > $r['bevel_over_cm']) {
            $level = $mobility === 'wheelchair' ? 'red' : 'yellow';
            $reason = [sprintf('門檻高約 %.1f 公分，超過 %.1f 公分（參考值應做斜角）。', $cm, $r['bevel_over_cm']), sprintf('Threshold about %.1f cm, above the %.1f cm reference for a bevel.', $cm, $r['bevel_over_cm'])];
        } else {
            $level = 'green';
            $reason = [sprintf('門檻高約 %.1f 公分，在參考值內。', $cm), sprintf('Threshold about %.1f cm, within the reference.', $cm)];
        }
        $h = $this->base('threshold', $name, 'construct', $o, $level, $reason, $level === 'green' ? [] : $this->thresholdCodes($cm, $mobility));
        $h['value_cm'] = $cm;
        return $h;
    }

    /** Official FA codes by height band; the agent may only choose among these. */
    private function thresholdCodes(float $cm, string $mobility): array
    {
        $build = match (true) {
            $cm <= 10 => 'FA30',
            $cm <= 20 => 'FA31',
            $cm <= 30 => 'FA32',
            default => 'FA33',
        };
        $codes = [$build];
        if ($cm <= 10) {
            $codes[] = 'FA25';
        }
        return array_merge($codes, $mobility === 'wheelchair' ? ['FA27', 'FA28'] : ['FA26', 'FA27']);
    }

    private function doorway(array $o, ?float $pxPerCm, string $mobility): ?array
    {
        if ($pxPerCm === null) {
            return null;
        }
        $cm = round(($o['bounds'][2] - $o['bounds'][0]) / $pxPerCm, 1);
        $r = $this->rules['doorway'];
        if ($cm < $r['sanity_min_cm'] || $cm >= $r['min_clear_cm']) {
            return null; // too small to be a real full doorway measure, or already wide enough
        }
        $level = $mobility === 'wheelchair' ? 'red' : 'yellow';
        $h = $this->base('doorway', 'Narrow doorway', 'construct', $o, $level,
            [sprintf('門口淨寬約 %.1f 公分，低於參考值 %d 公分。', $cm, $r['min_clear_cm']), sprintf('Doorway about %.1f cm wide, under the %d cm reference.', $cm, $r['min_clear_cm'])], ['FA38']);
        $h['value_cm'] = $cm;
        return $h;
    }

    private function floorObject(string $class, array $o): array
    {
        $name = ['rug' => 'Rug', 'cable' => 'Cable', 'clutter' => ucfirst(str_replace(['_on_the_floor', '_'], ['', ' '], $o['label']))][$class];
        $ratio = (float) ($o['on_path_ratio'] ?? 0);
        $onPath = $ratio >= $this->rules['path']['min_overlap'];
        if ($onPath) {
            $pct = (int) round($ratio * 100);
            $h = $this->base($class, $name, 'clear', $o, 'red', [sprintf('有 %d%% 壓在行走動線上，請清除或移位。', $pct), sprintf('%d%% of it lies on the walking path; clear or move it.', $pct)], []);
        } elseif ($class === 'rug') {
            $h = $this->base($class, $name, 'clear', $o, 'yellow', ['不在動線上，但邊緣可能絆腳，建議固定或移除。', 'Off the walking path, but its edge can catch a foot; secure or remove it.'], []);
        } else {
            $h = $this->base($class, $name, 'noted', $o, 'green', ['靠邊，不在行走動線上，僅記錄。', 'Off the walking path; noted only.'], []);
        }
        $h['on_path_ratio'] = $ratio;
        return $h;
    }

    private function missingGrabBar(array $toilet): array
    {
        $h = $this->base('grab_bar', 'Missing grab bar', 'construct', $toilet, 'red', ['馬桶旁未偵測到扶手。', 'No grab bar detected near the toilet.'], ['FA22', 'FA23']);
        $h['mask'] = null; // the hazard is an absence; show the toilet's box, not its mask
        return $h;
    }

    /** @param array{string, string} $reason Traditional Chinese, then English */
    private function base(string $type, string $name, string $action, array $o, string $level, array $reason, array $codes): array
    {
        return [
            'type' => $type,
            'name' => $name,
            'action' => $action,
            'level' => $level,
            'note' => $reason[0],
            'note_en' => $reason[1],
            'codes' => $codes,
            'value_cm' => null,
            'bounds' => $o['bounds'],
            'mask' => $o['mask'] ?? null,
            'confidence' => $o['confidence'] ?? null,
        ];
    }

}
