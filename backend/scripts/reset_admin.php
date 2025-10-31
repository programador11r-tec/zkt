<?php
declare(strict_types=1);

require __DIR__ . '/../config/autoload.php';

use Config\Config;

try {
    $cfg = new Config(__DIR__ . '/../.env');

    $driver = strtolower($cfg->get('DB_CONNECTION', 'mysql'));
    if ($driver === 'sqlite') {
        $dsn = 'sqlite:' . $cfg->get('DB_DATABASE', __DIR__ . '/../storage/app.sqlite');
        $pdo = new PDO($dsn);
    } else {
        $host = $cfg->get('DB_HOST', '127.0.0.1');
        $port = (int)$cfg->get('DB_PORT', 3306);
        $db   = $cfg->get('DB_DATABASE', 'zkt');
        $user = $cfg->get('DB_USERNAME', 'root');
        $pass = $cfg->get('DB_PASSWORD', '');
        $pdo  = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Cambia aquí la clave que quieras dejar de forma temporal
    $newPlain = 'Admin$2025';
    $newHash  = password_hash($newPlain, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = :h, active = 1 WHERE username = 'admin'");
    $stmt->execute([':h' => $newHash]);

    echo "OK. Nueva clave de admin: {$newPlain}\nHash: {$newHash}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: ".$e->getMessage()."\n";
}
