<?php
namespace App\Utils;

final class Logger
{
    private static string $dir = __DIR__ . '/../../storage/logs';

    private static function ensureDir(): void {
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0775, true);
        }
    }

    private static function write(string $level, string $message, array $ctx = []): void {
        self::ensureDir();
        $file = self::$dir . '/app-' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND);
    }
    public static function log(string $message, string $file = null): void
    {
        try {
            if (!$file) {
                $file = __DIR__ . '/../../storage/app_debug.log';
            }

            // Crear directorio si no existe
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            // Fecha y hora local
            $date = (new \DateTime('now', new \DateTimeZone('America/Guatemala')))
                ->format('Y-m-d H:i:s');

            // Formato de log
            $line = sprintf("[%s] %s\n", $date, $message);

            // Escribir
            file_put_contents($file, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // Fallback silencioso en caso de error
            error_log("Logger fallback: " . $e->getMessage());
        }
    }

    public static function info(string $message, array $ctx = []): void  { self::write('info',  $message, $ctx); }
    public static function warn(string $message, array $ctx = []): void  { self::write('warn',  $message, $ctx); }
    public static function debug(string $message, array $ctx = []): void { self::write('debug', $message, $ctx); }

    // 👇 NUEVO: el que te falta
    public static function error(string $message, array $ctx = []): void { self::write('error', $message, $ctx); }
}
