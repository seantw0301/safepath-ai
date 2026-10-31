<?php

declare(strict_types=1);

// Regression check: SAM confidently calls a flat shoe sticker "shoes". The agent must look at the
// photo and hand it to a person, not send the family to clear it. Replays from samples/cache.

use SafePath\Infra\Db;
use SafePath\Infra\Env;
use SafePath\Job\Pipeline;
use SafePath\Job\Worker;
use SafePath\Scans;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$home = Scans::createHousehold('False positive check', 'walker', 4, 3);
$id = Scans::create($home, [['path' => __DIR__ . '/../samples/scenes/false_positive.jpg', 'room' => 'Hall', 'px_per_cm' => null]]);
(new Worker())->drain();
$report = Scans::report($id);

$failed = $report['scan']['status'] !== 'report_ready';
foreach ($report['hazards'] as $hazard) {
    printf("%-8s %-8s %s\n", $hazard['name'], $hazard['level'], $hazard['note_en']);
    $failed = $failed || !in_array($hazard['level'], ['unknown', 'green'], true);
}
$failed = $failed || $report['hazards'] === [] || $report['work_orders'] !== [];
printf("work orders: %d\n", count($report['work_orders']));

foreach (['hazards', 'work_orders', 'subsidy_packages', 'frames', 'jobs', 'scan_steps'] as $table) {
    Db::pdo()->prepare("DELETE FROM $table WHERE scan_id = ?")->execute([$id]);
}
Db::pdo()->prepare('DELETE FROM scans WHERE id = ?')->execute([$id]);
Db::pdo()->prepare('DELETE FROM households WHERE id = ?')->execute([$home]);
exec('rm -rf ' . escapeshellarg(Pipeline::scanDir($id)));

echo $failed ? "FAILED\n" : "ok\n";
exit($failed ? 1 : 0);
