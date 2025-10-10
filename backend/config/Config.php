<?php
declare(strict_types=1);

namespace Config;

class Config {
    private array $env = [];

    public function __construct(string $envPath = __DIR__ . '/../.env') {
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $this->env[trim($k)] = trim($v);
            }
        }
    }

    public function get(string $key, $default = null) {
        if (isset($this->env[$key])) return $this->env[$key];
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }
}
