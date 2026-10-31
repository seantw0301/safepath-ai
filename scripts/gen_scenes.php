<?php

declare(strict_types=1);

// Generates the simulated before/after home scenes with Muse Image. Skips files that already exist.
// Usage: php scripts/gen_scenes.php

use SafePath\Infra\Env;
use SafePath\Model\MetaClient;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$dir = __DIR__ . '/../samples/scenes';
$client = new MetaClient();

$save = static function (string $bytes, string $path): void {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $bytes);
    exec('sips -s format jpeg ' . escapeshellarg($tmp) . ' --out ' . escapeshellarg($path) . ' >/dev/null');
    unlink($tmp);
    echo 'wrote ' . basename($path) . PHP_EOL;
};

$copies = ['door_before.jpg' => 'gen_hallway.jpg', 'hall_before.jpg' => 'gen_clutter.jpg'];
foreach ($copies as $to => $from) {
    if (!is_file("$dir/$to")) {
        copy(__DIR__ . "/../samples/$from", "$dir/$to");
    }
}

if (!is_file("$dir/bath_before.jpg")) {
    $save($client->imageGenerate(
        'Realistic smartphone photo, eye level, of a small older Taiwanese apartment bathroom seen from the doorway: '
        . 'a white sit-down toilet against a tiled wall, bare tile walls with no handrails or bars anywhere, '
        . 'and shiny wet floor tiles with a visible puddle of water in front of the toilet. Natural indoor light, no people.'
    ), "$dir/bath_before.jpg");
}

$after = [
    'door_after.jpg' => ['door_before.jpg', 'Remove the red rug and the black cable and power strip from the floor completely. '
        . 'Add a sturdy wooden wedge ramp covering the raised door threshold. Keep the same camera angle, walls, door, lighting and floor.'],
    'hall_after.jpg' => ['hall_before.jpg', 'Remove the slippers, the white sneakers and the black power cord from the floor completely, leaving clean tiles. '
        . 'Keep the cardboard box, yellow bag, flower pot, walls, door, lighting and camera angle exactly the same.'],
    'bath_after.jpg' => ['bath_before.jpg', 'Dry the floor and remove the puddle. Add a stainless steel grab bar mounted on the wall next to the toilet, '
        . 'and a grey non-slip rubber bath mat on the floor in front of the toilet. Keep the same camera angle, toilet, tiles and lighting.'],
];
foreach ($after as $name => [$from, $prompt]) {
    if (!is_file("$dir/$name")) {
        $save($client->imageEdit("$dir/$from", $prompt), "$dir/$name");
    }
}
