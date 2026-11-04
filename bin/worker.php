<?php

declare(strict_types=1);

use SafePath\Infra\Env;
use SafePath\Job\Queue;
use SafePath\Job\Worker;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$worker = new Worker();
// Jobs left 'running' by a worker that died are picked up again, at start and then every minute.
$checked = 0;
while (true) {
    if (time() - $checked >= 60) {
        Queue::recover();
        $checked = time();
    }
    if (!$worker->runOne()) {
        sleep(1);
    }
}
