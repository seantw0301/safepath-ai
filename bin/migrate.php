<?php

declare(strict_types=1);

use SafePath\Infra\Db;
use SafePath\Infra\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$files = glob(__DIR__ . '/../database/migrations/*.sql');
sort($files);
foreach ($files as $file) {
    Db::pdo()->exec(file_get_contents($file));
    echo 'migrated ' . basename($file) . PHP_EOL;
}
