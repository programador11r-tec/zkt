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
trait SettingsModule
{
    public function health() {
        Http::json(['ok' => true, 'time' => date('c')]);
    }

    public function settingsOverview() {
        $environment = strtolower((string) $this->config->get('APP_ENV', 'local'));
        $label = match ($environment) {
            'production', 'prod' => 'Producci├│n',
            'staging', 'pre', 'preprod', 'qa' => 'Preproducci├│n',
            'testing', 'test' => 'Pruebas',
            'development', 'dev' => 'Desarrollo',
            'local' => 'Local',
            default => $environment ? ucfirst($environment) : 'Desconocido',
        };

        $settings = [
            'generated_at' => date('c'),
            'app' => [
                'name' => $this->config->get('APP_NAME', 'Integraci├│n FEL'),
                'environment' => $environment,
                'environment_label' => $label,
                'timezone' => date_default_timezone_get(),
                'php_version' => PHP_VERSION,
                'server' => php_uname('n') ?: php_uname('s'),
                'generated_at' => date('c'),
            ],
            'integrations' => [
                'zkteco' => [
                    'label' => 'ZKTeco BioTime',
                    'configured' => $this->isConfigured(['ZKTECO_BASE_URL', 'ZKTECO_APP_KEY', 'ZKTECO_APP_SECRET']),
                    'base_url' => $this->config->get('ZKTECO_BASE_URL'),
                    'app_key' => $this->mask($this->config->get('ZKTECO_APP_KEY'), 3),
                ],
                'g4s' => [
                    'label' => 'G4S FEL',
                    'configured' => $this->isConfigured(['FEL_G4S_SOAP_URL', 'FEL_G4S_REQUESTOR', 'FEL_G4S_USER', 'FEL_G4S_PASS']),
                    'base_url' => $this->config->get('FEL_G4S_SOAP_URL'),
                    'mode' => $this->config->get('FEL_G4S_MODE', 'REQUEST'),
                    'requestor' => $this->mask($this->config->get('FEL_G4S_REQUESTOR'), 4),
                ],
            ],
            'security' => [
                'ingest_key' => $this->mask($this->config->get('INGEST_KEY'), 4),
            ],
            'billing' => [
                'hourly_rate' => null,
                'monthly_rate' => null,
            ],
        ];

        $settings['database'] = [
            'status' => 'offline',
            'driver' => strtolower((string) $this->config->get('DB_CONNECTION', 'mysql')),
            'host' => $this->config->get('DB_HOST', '127.0.0.1'),
            'name' => $this->config->get('DB_DATABASE', 'zkt'),
            'user' => $this->config->get('DB_USERNAME', 'root'),
        ];

        $activity = [];
        try {
            $pdo = DB::pdo($this->config);
            $settings['database']['status'] = 'online';
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver) {
                $settings['database']['driver'] = $driver;
            }

            $this->ensureSettingsTable($pdo);
            $settings['billing']['hourly_rate'] = $this->getHourlyRate($pdo);
            $settings['billing']['monthly_rate'] = $this->getMonthlyRate($pdo);

            $fetchColumn = static function (PDO $pdo, string $sql) {
                try {
                    $stmt = $pdo->query($sql);
                    return $stmt ? $stmt->fetchColumn() : null;
                } catch (\Throwable $e) {
                    return null;
                }
            };

            $metrics = [
                'tickets_total' => (int) ($fetchColumn($pdo, 'SELECT COUNT(*) FROM tickets') ?? 0),
                'tickets_last_sync' => $fetchColumn($pdo, 'SELECT MAX(created_at) FROM tickets'),
                'payments_total' => (int) ($fetchColumn($pdo, 'SELECT COUNT(*) FROM payments') ?? 0),
                'payments_last_sync' => $fetchColumn($pdo, 'SELECT MAX(created_at) FROM payments'),
                'invoices_total' => (int) ($fetchColumn($pdo, 'SELECT COUNT(*) FROM invoices') ?? 0),
                'invoices_last_sync' => $fetchColumn($pdo, 'SELECT MAX(created_at) FROM invoices'),
            ];

            $pendingSql = "
                SELECT COUNT(1)
                FROM tickets t
                WHERE t.status = 'OPEN'
                  AND EXISTS (SELECT 1 FROM payments p WHERE p.plate = t.plate)
                  AND NOT EXISTS (
                    SELECT 1 FROM invoices i2
                    WHERE i2.ticket_no = t.plate
                      AND i2.status IN ('PENDING','OK')
                  )
            ";
            $metrics['pending_invoices'] = (int) ($fetchColumn($pdo, $pendingSql) ?? 0);

            $settings['database']['metrics'] = $metrics;

            if (!empty($metrics['tickets_last_sync'])) {
                $activity[] = [
                    'title' => 'Ticket sincronizado',
                    'subtitle' => 'Registro m├ís reciente almacenado en BD',
                    'timestamp' => $metrics['tickets_last_sync'],
                    'status' => 'online',
                ];
            }
            if (!empty($metrics['payments_last_sync'])) {
                $activity[] = [
                    'title' => 'Pago registrado',
                    'subtitle' => '├Ültimo pago recibido desde ZKTeco',
                    'timestamp' => $metrics['payments_last_sync'],
                    'status' => 'online',
                ];
            }
            if (!empty($metrics['invoices_last_sync'])) {
                $activity[] = [
                    'title' => 'Factura emitida',
                    'subtitle' => '├Ültimo certificaci├│n FEL registrada',
                    'timestamp' => $metrics['invoices_last_sync'],
                    'status' => 'online',
                ];
            }
            if (($metrics['pending_invoices'] ?? 0) > 0) {
                $activity[] = [
                    'title' => 'Pendientes por facturar',
                    'subtitle' => sprintf('%d tickets listos para certificarse', (int) $metrics['pending_invoices']),
                    'timestamp' => date('c'),
                    'status' => 'warning',
                ];
            }
        } catch (\Throwable $e) {
            $settings['database']['status'] = 'offline';
            $settings['database']['error'] = $e->getMessage();
        }

        $settings['activity'] = $activity;

        Http::json(['ok' => true, 'settings' => $settings]);
    }

    public function updateHourlyRate() {
        try {
            $body = Http::body();
            $rawRate = $body['hourly_rate'] ?? null;
            $value = null;
            if ($rawRate !== null && $rawRate !== '') {
                if (!is_numeric($rawRate)) {
                    throw new \InvalidArgumentException('La tarifa por hora debe ser un n├║mero.');
                }
                $value = (float) $rawRate;
                if ($value <= 0) {
                    throw new \InvalidArgumentException('La tarifa por hora debe ser mayor a cero.');
                }
            }

            $monthlyProvided = array_key_exists('monthly_rate', $body);
            $rawMonthly = $body['monthly_rate'] ?? null;
            $monthlyValue = null;
            if ($monthlyProvided && $rawMonthly !== null && $rawMonthly !== '') {
                if (!is_numeric($rawMonthly)) {
                    throw new \InvalidArgumentException('La tarifa mensual debe ser un n├║mero.');
                }
                $monthlyValue = (float) $rawMonthly;
                if ($monthlyValue <= 0) {
                    throw new \InvalidArgumentException('La tarifa mensual debe ser mayor a cero.');
                }
            }

            $pdo = DB::pdo($this->config);
            $formatted = $value === null ? null : number_format($value, 2, '.', '');
            $this->setAppSetting($pdo, 'billing.hourly_rate', $formatted);

            if ($monthlyProvided) {
                $formattedMonthly = $monthlyValue === null ? null : number_format($monthlyValue, 2, '.', '');
                $this->setAppSetting($pdo, 'billing.monthly_rate', $formattedMonthly);
            }

            Http::json([
                'ok' => true,
                'hourly_rate' => $this->getHourlyRate($pdo),
                'monthly_rate' => $this->getMonthlyRate($pdo),
            ]);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

}
