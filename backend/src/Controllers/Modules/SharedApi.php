<?php
declare(strict_types=1);

namespace App\Controllers\Modules;

use App\Services\G4SClient;
use App\Services\ZKTecoClient;
use App\Utils\DB;
use App\Utils\Http;
use App\Utils\Logger;
use App\Utils\Schema;
use Config\Config;
use PDO;
trait SharedApi
{
    private function ensureSettingsTable(PDO $pdo): void {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (
                `key` VARCHAR(64) PRIMARY KEY,
                `value` TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )'
        );
    }

    private function getAppSetting(PDO $pdo, string $key, mixed $default = null): mixed {
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    private function setAppSetting(PDO $pdo, string $key, ?string $value): void {
        $this->ensureSettingsTable($pdo);

        if ($value === null) {
            $stmt = $pdo->prepare('DELETE FROM app_settings WHERE `key` = :key');
            $stmt->execute([':key' => $key]);
            return;
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO app_settings (`key`, `value`, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
                    ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`, updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO app_settings (`key`, `value`, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    private function appTimezone(): \DateTimeZone {
        $tz = (string) ($this->config->get('APP_TIMEZONE') ?? $this->config->get('APP_TZ') ?? '');
        if ($tz === '') { $tz = 'America/Guatemala'; }
        return new \DateTimeZone($tz);
    }

    private function parseAppDateTime(?string $s): ?\DateTimeImmutable {
        if ($s === null || trim($s) === '') return null;
        $raw = trim($s);

        $tzApp = $this->appTimezone();
        $asUtc = strtolower((string) $this->config->get('APP_DATETIME_IS_UTC', 'false')) === 'true';

        $baseTz = $asUtc ? new \DateTimeZone('UTC') : $tzApp;

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $baseTz);
        if ($dt === false) {
            try { $dt = new \DateTimeImmutable($raw, $baseTz); }
            catch (\Throwable) { return null; }
        }
        return $asUtc ? $dt->setTimezone($tzApp) : $dt;
    }

    private function getTableColumns(PDO $pdo, string $table): array {
        $cacheKey = strtolower($table);
        if (isset($this->tableColumnCache[$cacheKey])) {
            return $this->tableColumnCache[$cacheKey];
        }

        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        $columns = [];
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $name = $row['name'] ?? null;
                        if ($name !== null) {
                            $columns[strtolower((string) $name)] = true;
                        }
                    }
                }
            } else {
                $safeTable = str_replace('`', '``', $table);
                $stmt = $pdo->query('SHOW COLUMNS FROM `' . $safeTable . '`');
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $field = $row['Field'] ?? null;
                        if ($field !== null) {
                            $columns[strtolower((string) $field)] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::error('schema.describe_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->tableColumnCache[$cacheKey] = $columns;
    }

    private function getHourlyRate(PDO $pdo): ?float {
        $raw = $this->getAppSetting($pdo, 'billing.hourly_rate');
        if ($raw === null || $raw === '') {
            return null;
        }
        $rate = (float) $raw;
        return $rate > 0 ? $rate : null;
    }

    private function getMonthlyRate(PDO $pdo): ?float {
        $raw = $this->getAppSetting($pdo, 'billing.monthly_rate');
        if ($raw === null || $raw === '') {
            return null;
        }
        $rate = (float) $raw;
        return $rate > 0 ? $rate : null;
    }

    private function newCorrelationId(string $prefix = 'cid'): string {
        return $prefix . '-' . bin2hex(random_bytes(4)) . '-' . dechex(time());
    }

    private function msSince(float $t0): int {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    private function normalizeDateTime($value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) && strlen($value) >= 10) {
            $timestamp = (int) substr($value, 0, 10);
            return date('Y-m-d H:i:s', $timestamp);
        }

        $value = str_replace('T', ' ', $value);
        $value = str_replace('/', '-', $value);
        $value = preg_replace('/\.(\d{1,6})/', '', $value, 1);

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function parseDurationMinutes($value): ?int {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $minutes = (int) round((float) $value);
            return $minutes >= 0 ? $minutes : null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $seconds = isset($m[3]) ? (int) $m[3] : 0;
            $total = ($hours * 60) + $minutes + ($seconds > 0 ? 1 : 0);
            return $total >= 0 ? $total : null;
        }

        if (preg_match('/(\d+)(?=\s*min)/i', $value, $m)) {
            $minutes = (int) $m[1];
            return $minutes >= 0 ? $minutes : null;
        }

        return null;
    }

    private function isListArray(array $array): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }
        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private function mask($value, int $visible = 4): ?string {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        $length = strlen($value);
        if ($length <= $visible * 2) {
            return str_repeat('ÔÇó', max($length, 4));
        }
        return substr($value, 0, $visible)
            . str_repeat('ÔÇó', max(3, $length - ($visible * 2)))
            . substr($value, -$visible);
    }

    private function isConfigured(array $keys): bool {
        foreach ($keys as $key) {
            $value = $this->config->get($key);
            if ($value === null || trim((string) $value) === '') {
                return false;
            }
        }
        return true;
    }

}
