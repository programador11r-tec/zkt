<?php
namespace App\Utils;

class Logger
{
    /**
     * Escribe una línea en el archivo de log.
     * Crea la carpeta automáticamente si no existe.
     */
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
}
