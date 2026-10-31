<?php

declare(strict_types=1);

namespace SafePath\Agent;

use SafePath\Job\Pipeline;
use SafePath\Model\MetaClient;
use SafePath\Scans;

/** Muse Spark compares before/after photos to confirm a construction fix actually exists. */
final class VerifyAgent
{
    private const SYSTEM = <<<'TXT'
You check whether a home-accessibility fix was really done, by comparing a BEFORE photo (first image) with an AFTER photo (second image) of the same spot.
Judge only what is visible. Answer "unsure" if the spot is hidden or the photos do not match. Do not give health advice. Do not estimate measurements.
TXT;

    private const FIX = [
        'threshold' => 'Is the raised door threshold now handled, for example covered by a ramp or levelled?',
        'wet_floor' => 'Is the wet floor now dry or covered by a non-slip mat or non-slip treatment?',
        'grab_bar' => 'Is there now a grab bar fixed to the wall near the toilet?',
        'doorway' => 'Has the doorway been visibly widened?',
    ];

    public function __construct(private MetaClient $meta)
    {
    }

    /** @return array{verdict: string, evidence: string} */
    public function check(array $hazard, int $beforeScan, int $afterScan): array
    {
        $question = self::FIX[$hazard['type']] ?? ('Has this been fixed: ' . $hazard['name'] . '?');
        $before = $this->photo($beforeScan, (int) $hazard['frame_idx']);
        $after = $this->photo($afterScan, (int) $hazard['frame_idx']);
        return $this->meta->sparkVisionJson(self::SYSTEM, $question, [$before, $after], 'fix_check', [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['fixed', 'not_fixed', 'unsure']],
                'evidence' => ['type' => 'string'],
            ],
            'required' => ['verdict', 'evidence'],
            'additionalProperties' => false,
        ]);
    }

    private function photo(int $scanId, int $idx): string
    {
        $rows = Scans::rows('SELECT blurred_path FROM frames WHERE scan_id = ? AND idx = ?', [$scanId, $idx]);
        return Pipeline::root() . '/' . $rows[0]['blurred_path'];
    }
}
