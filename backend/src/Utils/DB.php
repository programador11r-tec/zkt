<?php
namespace App\Utils;

use PDO;
use Config\Config;

class DB {
    public static function pdo(Config $config): PDO {
        $driver = strtolower((string) $config->get('DB_CONNECTION', 'sqlite'));

        if ($driver === 'sqlite') {
            $dbPath = (string) $config->get('DB_DATABASE', __DIR__ . '/../../storage/sqlite/app.sqlite');
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = __DIR__ . '/../../' . ltrim($dbPath, '/');
            }
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            return $pdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $driver,
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_DATABASE', 'zkt')
        );

        $pdo = new PDO(
            $dsn,
            (string) $config->get('DB_USERNAME', 'root'),
            (string) $config->get('DB_PASSWORD', '')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
