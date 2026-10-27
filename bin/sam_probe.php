<?php

declare(strict_types=1);

// Usage: php bin/sam_probe.php <image> "<prompt>" [more prompts...]
// Writes raw SAM output to storage/probe/<image>.<prompt>.txt

use SafePath\Infra\Env;
use SafePath\Model\MetaClient;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

[$_, $image] = $argv + [null, null];
$prompts = array_slice($argv, 2);
if ($image === null || $prompts === []) {
    fwrite(STDERR, "usage: php bin/sam_probe.php <image> <prompt>...\n");
    exit(1);
}

$client = new MetaClient();
@mkdir(__DIR__ . '/../storage/probe', 0775, true);
foreach ($prompts as $prompt) {
    $t = microtime(true);
    $out = $client->samImage($image, $prompt);
    $file = sprintf('%s/../storage/probe/%s.%s.txt', __DIR__, basename($image), preg_replace('/\W+/', '_', $prompt));
    file_put_contents($file, $out);
    printf("%-16s %5.2fs %3d matches\n", $prompt, microtime(true) - $t, substr_count($out, '<|box'));
}
