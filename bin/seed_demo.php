<?php

declare(strict_types=1);

// Builds the pre-processed demo scans from samples/scenes (SIMULATED) through the real pipeline.
// SAM and Muse Spark answers are cached in samples/cache, so reruns cost nothing.

use SafePath\Infra\Db;
use SafePath\Infra\Env;
use SafePath\Job\Worker;
use SafePath\Scans;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$dir = __DIR__ . '/../samples/scenes';
$manifest = json_decode((string) file_get_contents("$dir/manifest.json"), true)['scenes'];
$photos = static function (string $phase) use ($manifest, $dir): array {
    $out = [];
    foreach ($manifest as $key => $scene) {
        $out[] = ['path' => "$dir/{$key}_{$phase}.jpg", 'room' => $scene['room'], 'px_per_cm' => $scene['px_per_cm']];
    }
    return $out;
};

// Remove old demo scans with everything that hangs off them, so no orphan rows are left behind.
$old = 'SELECT id FROM scans WHERE is_demo = 1';
foreach (['hazard_overrides' => 'hazard_id IN (SELECT id FROM hazards WHERE scan_id IN (%s))', 'hazards' => 'scan_id IN (%s)', 'work_orders' => 'scan_id IN (%s)',
    'subsidy_packages' => 'scan_id IN (%s)', 'frames' => 'scan_id IN (%s)', 'jobs' => 'scan_id IN (%s)', 'scan_steps' => 'scan_id IN (%s)'] as $table => $where) {
    Db::pdo()->exec("DELETE FROM $table WHERE " . sprintf($where, $old));
}
Db::pdo()->exec('DELETE FROM households WHERE id IN (SELECT household_id FROM scans WHERE is_demo = 1)');
Db::pdo()->exec('DELETE FROM scans WHERE is_demo = 1');
$worker = new Worker();
foreach ([['Demo home A (walker)', 'walker', 4, 3], ['Demo home B (wheelchair)', 'wheelchair', 6, 2]] as [$name, $mobility, $level, $category]) {
    $home = Scans::createHousehold($name, $mobility, $level, $category);
    $before = Scans::create($home, $photos('before'), null, true, true);
    $worker->drain();
    if (Scans::get($before)['status'] !== 'report_ready') {
        fwrite(STDERR, 'demo scan failed: ' . Scans::get($before)['error'] . PHP_EOL);
        exit(1);
    }
    $after = Scans::create($home, $photos('after'), $before, true, true);
    $worker->drain();
    foreach ([$before, $after] as $id) {
        $scan = Scans::get($id);
        echo sprintf("scan %d %s %s%s\n", $id, $name, $scan['status'], $scan['error'] ? ' ERROR: ' . $scan['error'] : '');
    }
}

// Walkthrough-video demo. SIMULATED: a slow zoom over the generated scenes, not real camera motion.
$videoDir = __DIR__ . '/../samples/demo-video';
$prepared = static fn (string $phase): array => json_decode((string) file_get_contents("$videoDir/$phase/manifest.json"), true);
$home = Scans::createHousehold('Demo home C (video, walker)', 'walker', 4, 3);
$before = Scans::createFromPreparedVideo($home, "$videoDir/before", $prepared('before'), null, 'Walkthrough');
$worker->drain();
if (Scans::get($before)['status'] !== 'report_ready') {
    fwrite(STDERR, 'video demo failed: ' . Scans::get($before)['error'] . PHP_EOL);
    exit(1);
}
$after = Scans::createFromPreparedVideo($home, "$videoDir/after", $prepared('after'), $before, 'Walkthrough');
$worker->drain();
foreach ([$before, $after] as $id) {
    $scan = Scans::get($id);
    echo sprintf("scan %d video demo %s%s\n", $id, $scan['status'], $scan['error'] ? ' ERROR: ' . $scan['error'] : '');
}
