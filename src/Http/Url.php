<?php

declare(strict_types=1);

namespace SafePath\Http;

use SafePath\Infra\Env;

/** Lets the app live under a sub-path, for example https://example.com/safepath. */
final class Url
{
    public static function base(): string
    {
        return rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
    }

    public static function to(string $path): string
    {
        return self::base() . $path;
    }

    public static function strip(string $path): string
    {
        $base = self::base();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return $path === '' ? '/' : $path;
    }
}
