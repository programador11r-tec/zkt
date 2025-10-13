<?php
declare(strict_types=1);

namespace App\Utils;

class Http {
    private static ?string $raw = null;

    public static function json($data, int $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function body(): array {
        if (self::$raw === null) {
            self::$raw = file_get_contents('php://input') ?: '';
        }
        $json = json_decode(self::$raw, true);
        return is_array($json) ? $json : [];
    }

    public static function rawBody(): string {
        if (self::$raw === null) {
            self::$raw = file_get_contents('php://input') ?: '';
        }
        return self::$raw;
    }
}
