<?php
declare(strict_types=1);

namespace App\Utils;

class Logger {
    public static function info(string $msg, array $ctx = []) {
        self::write('INFO', $msg, $ctx);
    }
    public static function error(string $msg, array $ctx = []) {
        self::write('ERROR', $msg, $ctx);
    }
    private static function write(string $level, string $msg, array $ctx) {
        $line = sprintf("%s [%s] %s %s\n", date('c'), $level, $msg, $ctx ? json_encode($ctx) : '');
        $logFile = __DIR__ . '/../../storage/logs/app.log';
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
