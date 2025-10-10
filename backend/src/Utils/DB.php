<?php
namespace App\Utils;

use PDO;
use Config\Config;

class DB {
    public static function pdo(Config $config): PDO {
        $dsn = sprintf(
            "%s:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            $config->get('DB_CONNECTION', 'mysql'),
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_DATABASE', 'zkt')
        );
        $pdo = new PDO(
            $dsn,
            $config->get('DB_USERNAME', 'root'),
            $config->get('DB_PASSWORD', '')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
