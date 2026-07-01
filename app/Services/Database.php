<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public static function connection(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $mysql = $config['mysql'] ?? [];
        $charset = (string)($mysql['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($mysql['host'] ?? '127.0.0.1'),
            (int)($mysql['port'] ?? 3306),
            (string)($mysql['database'] ?? ''),
            $charset
        );

        self::$pdo = new PDO($dsn, (string)($mysql['username'] ?? ''), (string)($mysql['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }
}
