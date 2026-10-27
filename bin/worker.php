<?php

declare(strict_types=1);

use SafePath\Infra\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

// TODO(M0): poll jobs table, dispatch to SafePath\Job\Handler\*
fwrite(STDERR, "worker: not implemented yet\n");
