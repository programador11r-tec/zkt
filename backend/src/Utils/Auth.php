<?php
declare(strict_types=1);

namespace App\Utils;

final class Auth
{
    /** Tiempo máximo inactivo (segundos) */
    private const DEFAULT_IDLE_TTL = 600; // 10 min

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $httponly = true;

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ]);
            session_name('ZKT_SESSID');
            @session_start();
        }
    }

    public static function currentUser(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function destroy(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        @session_destroy();
    }

    /**
     * Exige sesión (y opcionalmente rol). Expira si supera el idle TTL.
     * Devuelve el usuario (array) si OK.
     */
    public static function requireAuth(?string $role = null, int $idleTtl = self::DEFAULT_IDLE_TTL): array
    {
        self::startSession();

        // ¿hay usuario?
        $u = $_SESSION['user'] ?? null;
        if (!$u) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ¿expiró por inactividad?
        $now  = time();
        $last = (int)($_SESSION['last_activity'] ?? 0);
        if ($last && ($now - $last) > $idleTtl) {
            self::destroy();
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sesión expirada por inactividad'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // “Sliding expiration”: si pasa, refrescamos el last_activity
        $_SESSION['last_activity'] = $now;

        // ¿rol requerido?
        if ($role && (($u['role'] ?? null) !== $role)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $u;
    }

    /**
     * Ping para mantener viva la sesión SOLO si ya está autenticado.
     * No crea sesión si no existe.
     */
    public static function touch(int $idleTtl = self::DEFAULT_IDLE_TTL): bool
    {
        self::startSession();
        if (!isset($_SESSION['user'])) {
            // no autenticado: no “creamos” sesión
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }
}
