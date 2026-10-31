<?php

declare(strict_types=1);

use SafePath\Infra\Env;
use SafePath\Job\Worker;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$worker = new Worker();
while (true) {
    if (!$worker->runOne()) {
        sleep(1);
    }
}
