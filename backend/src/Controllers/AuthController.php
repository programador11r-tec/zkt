<?php
declare(strict_types=1);

namespace App\Controllers;

use Config\Config;
use PDO;

class AuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->makePdo();   // ← conexión similar a ApiController
        $this->initSession();
    }

    /* ===================== Infra base ===================== */

    private function makePdo(): PDO
    {
        try {
            // Carga .env como en ApiController
            $cfg = new Config(__DIR__ . '/../../.env');

            $driver = strtolower((string)$cfg->get('DB_CONNECTION', 'mysql'));
            if ($driver === 'sqlite') {
                // DB_DATABASE debe ser la ruta del archivo .sqlite
                $dsn = 'sqlite:' . $cfg->get('DB_DATABASE', __DIR__ . '/../../storage/app.sqlite');
                $pdo = new PDO($dsn);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $pdo;
            }

            // MySQL por defecto
            $host = $cfg->get('DB_HOST', '127.0.0.1');
            $port = (int)$cfg->get('DB_PORT', 3306);
            $db   = $cfg->get('DB_DATABASE', 'zkt');
            $user = $cfg->get('DB_USERNAME', 'root');
            $pass = $cfg->get('DB_PASSWORD', '');

            $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            $this->jsonError('Error de servidor (DB)', 500, $e);
        }
    }

    private function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $httponly = true;
            $samesite = 'Lax';

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
            session_name('ZKT_SESSID');
            @session_start();
        }
    }

    private function dbDriver(PDO $pdo): string
    {
        try {
            return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    private function jsonError(string $msg, int $status = 500, \Throwable $e = null): void
    {
        if ($e) {
            error_log('[AUTH] '.$msg.' :: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ===================== Endpoints ===================== */

public function login(): void
{
    try {
        header('Content-Type: application/json; charset=utf-8');

        $raw  = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];

        $username = strtolower(trim((string)($body['username'] ?? '')));
        $password = (string)($body['password'] ?? '');

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Usuario y contraseña requeridos'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Consulta segura
        $stmt = $this->pdo->prepare(
            "SELECT id, username, password_hash, role, active
             FROM users
             WHERE username = :u
             LIMIT 1"
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        // ✅ Validación ANTES de tocar la sesión
        if (!$user || !(bool)$user['active'] || !password_verify($password, (string)$user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Credenciales inválidas'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ✅ Sesión solo si las credenciales son correctas
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'       => (int)$user['id'],
            'username' => (string)$user['username'],
            'role'     => (string)$user['role'],
        ];
        $_SESSION['last_activity'] = time(); // para idle-timeout

        echo json_encode([
            'ok'   => true,
            'user' => [
                'username' => (string)$user['username'],
                'role'     => (string)$user['role'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $this->jsonError('Error de servidor (login)', 500, $e);
    }
}


    public function me(): void
    {
        try {
            header('Content-Type: application/json; charset=utf-8');
            if (session_status() === PHP_SESSION_NONE) { @session_start(); }
            if (!isset($_SESSION['user'])) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode(['ok' => true, 'user' => $_SESSION['user']], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->jsonError('Error de servidor (me)', 500, $e);
        }
    }

    public function logout(): void
    {
        try {
            header('Content-Type: application/json; charset=utf-8');
            if (isset($_SESSION['user'])) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
                }
                @session_destroy();
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->jsonError('Error de servidor (logout)', 500, $e);
        }
    }
}
