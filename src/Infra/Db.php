<?php

declare(strict_types=1);

namespace SafePath\Infra;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /** mysql in production; sqlite is the zero-setup local fallback. */
    public static function driver(): string
    {
        return Env::get('DB_DRIVER', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null && self::driver() === 'sqlite') {
            $path = dirname(__DIR__, 2) . '/storage/safepath.sqlite';
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
        }
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME', 'safepath')
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }
}
