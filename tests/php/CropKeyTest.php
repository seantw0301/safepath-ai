<?php

declare(strict_types=1);

namespace SafePath\Tests;

use PHPUnit\Framework\TestCase;
use SafePath\Agent\CaseAgent;

final class CropKeyTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = tempnam(sys_get_temp_dir(), 'src') . '.jpg';
        $image = imagecreatetruecolor(400, 300);
        imagefilledrectangle($image, 50, 50, 200, 150, imagecolorallocate($image, 200, 30, 40));
        imagejpeg($image, $this->source, 90);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
    }

    public function testKeyDependsOnTheSourceAndBoxNotOnTheCropBytes(): void
    {
        $bounds = [50.4, 50.0, 200.0, 149.6];
        $key = CaseAgent::cropKey($this->source, $bounds);

        // Two hosts can encode the same crop into different bytes; the key must not notice.
        $image = imagecreatefromjpeg($this->source);
        $a = tempnam(sys_get_temp_dir(), 'a');
        $b = tempnam(sys_get_temp_dir(), 'b');
        imagejpeg(imagecrop($image, ['x' => 50, 'y' => 50, 'width' => 150, 'height' => 100]), $a, 90);
        imagejpeg(imagecrop($image, ['x' => 50, 'y' => 50, 'width' => 150, 'height' => 100]), $b, 60);
        self::assertNotSame(sha1_file($a), sha1_file($b));
        unlink($a);
        unlink($b);

        self::assertSame($key, CaseAgent::cropKey($this->source, $bounds));
        self::assertSame($key, CaseAgent::cropKey($this->source, ['50', '50', '200', '150']));
    }

    public function testADifferentBoxOrPhotoGivesADifferentKey(): void
    {
        $key = CaseAgent::cropKey($this->source, [50, 50, 200, 150]);
        self::assertNotSame($key, CaseAgent::cropKey($this->source, [60, 50, 200, 150]));

        $other = tempnam(sys_get_temp_dir(), 'o') . '.jpg';
        imagejpeg(imagecreatetruecolor(400, 300), $other, 90);
        self::assertNotSame($key, CaseAgent::cropKey($other, [50, 50, 200, 150]));
        unlink($other);
    }
}
