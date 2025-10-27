<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Http;
use App\Utils\Logger;
use App\Services\ZKTecoClient;
use App\Services\G4SClient;
use Config\Config;
use App\Utils\DB;
use App\Utils\Schema;
use PDO;

class ApiController {
    private Config $config;
    /** @var array<string, array<string, bool>> */
    private array $tableColumnCache = [];

    public function __construct() {
        $this->config = new Config(__DIR__ . '/../../.env');
    }

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

    /**
     * @return array<string, bool>
     */
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

    public function health() {
        Http::json(['ok' => true, 'time' => date('c')]);
    }

    public function settingsOverview() {
        $environment = strtolower((string) $this->config->get('APP_ENV', 'local'));
        $label = match ($environment) {
            'production', 'prod' => 'Producción',
            'staging', 'pre', 'preprod', 'qa' => 'Preproducción',
            'testing', 'test' => 'Pruebas',
            'development', 'dev' => 'Desarrollo',
            'local' => 'Local',
            default => $environment ? ucfirst($environment) : 'Desconocido',
        };

        $settings = [
            'generated_at' => date('c'),
            'app' => [
                'name' => $this->config->get('APP_NAME', 'Integración FEL'),
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
                    'subtitle' => 'Registro más reciente almacenado en BD',
                    'timestamp' => $metrics['tickets_last_sync'],
                    'status' => 'online',
                ];
            }
            if (!empty($metrics['payments_last_sync'])) {
                $activity[] = [
                    'title' => 'Pago registrado',
                    'subtitle' => 'Último pago recibido desde ZKTeco',
                    'timestamp' => $metrics['payments_last_sync'],
                    'status' => 'online',
                ];
            }
            if (!empty($metrics['invoices_last_sync'])) {
                $activity[] = [
                    'title' => 'Factura emitida',
                    'subtitle' => 'Último certificación FEL registrada',
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
                    throw new \InvalidArgumentException('La tarifa por hora debe ser un número.');
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
                    throw new \InvalidArgumentException('La tarifa mensual debe ser un número.');
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

    /////////////////////////////////////////////
    // Helpers de correlación y medición de tiempo
    /////////////////////////////////////////////
    private function newCorrelationId(string $prefix = 'cid'): string {
        return $prefix . '-' . bin2hex(random_bytes(4)) . '-' . dechex(time());
    }
    private function msSince(float $t0): int {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /////////////////////////////////////////////////////////
    // Sincronización desde API remota (con logs detallados)
    /////////////////////////////////////////////////////////
    public function syncRemoteParkRecords() {
        $cid = $this->newCorrelationId('sync');
        $t0  = microtime(true);

        try {
            Logger::info('park.sync.start', ['cid' => $cid]);

            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') {
                Logger::error('park.sync.no_base_url', ['cid' => $cid]);
                Http::json(['ok' => false, 'error' => 'HAMACHI_PARK_BASE_URL no está configurado.'], 400);
                return;
            }

            $accessToken = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            if ($accessToken === '') {
                Logger::warning('park.sync.no_token', ['cid' => $cid]);
                // sigue si tu API no exige token
            }

            $pageNo   = (int) ($_GET['pageNo'] ?? $_GET['page'] ?? 1);
            if ($pageNo < 1) $pageNo = 1;
            $pageSize = (int) ($_GET['pageSize'] ?? $_GET['limit'] ?? 5);
            if ($pageSize <= 0) $pageSize = 5;
            $pageSize = min($pageSize, 1000);

            $query = [
                'pageNo'       => $pageNo,
                'pageSize'     => $pageSize,
                'access_token' => $accessToken,
            ];
            $endpoint = $baseUrl . '/api/v2/parkTransaction/listParkRecordin?' . http_build_query($query);

            $headers = ['Accept: application/json'];
            $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
            if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader;

            $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
            Logger::debug('park.sync.req', [
                'cid'        => $cid,
                'endpoint'   => $endpoint,
                'headers'    => $headers,
                'verify_ssl' => $verifySsl,
                'pageNo'     => $pageNo,
                'pageSize'   => $pageSize,
            ]);

            // cURL request con logs finos y ajustes de protocolo/timeout
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 35,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 30,
                CURLOPT_TCP_KEEPINTVL  => 10,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            if (!$verifySsl) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            // Opcional: enrutar SNI/CONNECT_TO si se configura
            $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', '')); // ej: "localhost:8098:25.21.54.208:8098"
            if ($connectTo !== '') {
                curl_setopt($ch, CURLOPT_CONNECT_TO, [$connectTo]);
                Logger::debug('http.connect_to', ['cid' => $cid, 'connect_to' => $connectTo]);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err  = curl_error($ch) ?: 'Error desconocido';
                $info = curl_getinfo($ch) ?: [];
                curl_close($ch);

                Logger::error('park.sync.http_failed', [
                    'cid'               => $cid,
                    'endpoint'          => $endpoint,
                    'error'             => $err,
                    'curl_info'         => $info,
                    'duration_ms'       => $this->msSince($t0),
                    'hint'              => 'Verifica IP/puerto, firewall, y que BASE_URL/CONNECT_TO sean correctos',
                ]);
                throw new \RuntimeException('Error al contactar API remota: ' . $err);
            }

            $info   = curl_getinfo($ch) ?: [];
            $status = (int)($info['http_code'] ?? 0);
            curl_close($ch);

            $len = strlen($raw);
            Logger::info('park.sync.http_ok', [
                'cid'               => $cid,
                'status'            => $status,
                'resp_bytes'        => $len,
                'namelookup_time'   => $info['namelookup_time'] ?? null,
                'connect_time'      => $info['connect_time'] ?? null,
                'pretransfer_time'  => $info['pretransfer_time'] ?? null,
                'starttransfer_time'=> $info['starttransfer_time'] ?? null,
                'total_time'        => $info['total_time'] ?? null,
                'primary_ip'        => $info['primary_ip'] ?? null,
                'local_ip'          => $info['local_ip'] ?? null,
            ]);

            if ($status < 200 || $status >= 300) {
                $preview = substr($raw, 0, 500);
                Logger::error('park.sync.bad_status', ['cid' => $cid, 'status' => $status, 'preview' => $preview]);
                throw new \RuntimeException('API remota respondió ' . $status . ': ' . $preview);
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                Logger::error('park.sync.non_json', ['cid' => $cid, 'preview' => substr($raw, 0, 300)]);
                throw new \RuntimeException('Respuesta remota inválida, no es JSON.');
            }

            $records = $this->extractParkRecords($payload);
            if (!$records) {
                Logger::info('park.sync.no_records', ['cid' => $cid, 'endpoint' => $endpoint]);
                Http::json([
                    'ok'       => true,
                    'endpoint' => $endpoint,
                    'fetched'  => 0,
                    'upserted' => 0,
                    'skipped'  => 0,
                    'message'  => 'La API remota no devolvió registros.',
                ]);
                return;
            }

            $pdo = DB::pdo($this->config);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $upserted = $this->persistTickets($pdo, $records); // persistTickets normaliza adentro si lo prefieres

            Logger::info('remote.park.sync_success', [
                'cid'        => $cid,
                'endpoint'   => $endpoint,
                'fetched'    => count($records),
                'upserted'   => $upserted,
                'skipped'    => 0, // si normalizas fuera, ajusta
                'total_ms'   => $this->msSince($t0),
            ]);

            Http::json([
                'ok'       => true,
                'endpoint' => $endpoint,
                'fetched'  => count($records),
                'upserted' => $upserted,
                'skipped'  => 0,
            ]);
        } catch (\Throwable $e) {
            Logger::error('remote.park.sync_failed', [
                'cid'       => $cid,
                'error'     => $e->getMessage(),
                'total_ms'  => $this->msSince($t0),
            ]);
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    //////////////////////////////////////////////////////////////////
    // Upsert de tickets + llamada a billing (logs separados por cid)
    //////////////////////////////////////////////////////////////////
    private function persistTickets(PDO $pdo, array $rows): int {
        if (!$rows) return 0;

        // Detecta driver
        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        // Columnas disponibles
        $columns      = $this->getTableColumns($pdo, 'tickets');
        $hasSource    = isset($columns['source']);
        $hasRawJson   = isset($columns['raw_json']);
        $hasUpdatedAt = isset($columns['updated_at']);

        // Construye SQL upsert para tickets
        $insertColumns = ['ticket_no','plate','status','entry_at','exit_at','duration_min','amount'];
        $valuePlaceholders = [':ticket_no',':plate',':status',':entry_at',':exit_at',':duration_min',':amount'];
        if ($hasSource)  { $insertColumns[] = 'source';   $valuePlaceholders[] = ':source'; }
        if ($hasRawJson) { $insertColumns[] = 'raw_json'; $valuePlaceholders[] = ':raw_json'; }

        if ($driver === 'sqlite') {
            $updates = [
                'plate=excluded.plate',
                'status=excluded.status',
                'entry_at=excluded.entry_at',
                'exit_at=excluded.exit_at',
                'duration_min=excluded.duration_min',
                'amount=excluded.amount',
            ];
            if ($hasSource)    { $updates[] = 'source=excluded.source'; }
            if ($hasRawJson)   { $updates[] = 'raw_json=excluded.raw_json'; }
            if ($hasUpdatedAt) { $updates[] = "updated_at=datetime('now')"; }

            $sql = sprintf(
                'INSERT INTO tickets (%s) VALUES (%s) ON CONFLICT(ticket_no) DO UPDATE SET %s',
                implode(',', $insertColumns),
                implode(',', $valuePlaceholders),
                implode(',', $updates)
            );
        } else {
            $updates = [
                'plate=VALUES(plate)',
                'status=VALUES(status)',
                'entry_at=VALUES(entry_at)',
                'exit_at=VALUES(exit_at)',
                'duration_min=VALUES(duration_min)',
                'amount=VALUES(amount)',
            ];
            if ($hasSource)    { $updates[] = 'source=VALUES(source)'; }
            if ($hasRawJson)   { $updates[] = 'raw_json=VALUES(raw_json)'; }
            if ($hasUpdatedAt) { $updates[] = 'updated_at=NOW()'; }

            $sql = sprintf(
                'INSERT INTO tickets (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                implode(',', $insertColumns),
                implode(',', $valuePlaceholders),
                implode(',', $updates)
            );
        }

        $stmt = $pdo->prepare($sql);
        $touched = 0;
        $idx = 0;

        foreach ($rows as $rowRaw) {
            $idx++;
            // Si te llegan crudos, normaliza aquí:
            $row = is_array($rowRaw) ? $this->normalizeParkRecordRow($rowRaw) : null;
            if ($row === null) {
                Logger::warning('tickets.normalize.skip', ['i' => $idx]);
                continue;
            }

            $cid = $this->newCorrelationId('tkt');
            $t0  = microtime(true);
            Logger::info('tickets.upsert.begin', [
                'cid' => $cid,
                'i'   => $idx,
                'ticket_no' => $row['ticket_no'] ?? null,
                'plate'     => $row['plate'] ?? null,
            ]);

            $params = [
                ':ticket_no'    => $row['ticket_no'],
                ':plate'        => $row['plate'] ?? null,
                ':status'       => $row['status'] ?? 'CLOSED',
                ':entry_at'     => $row['entry_at'] ?? null,
                ':exit_at'      => $row['exit_at'] ?? null,
                ':duration_min' => $row['duration_min'] ?? null,
                ':amount'       => $row['amount'] ?? null,
            ];
            if ($hasSource)  { $params[':source']   = $row['source'] ?? null; }
            if ($hasRawJson) { $params[':raw_json'] = $row['raw_json'] ?? null; }

            $stmt->execute($params);
            $touched++;

            Logger::info('tickets.upsert.ok', [
                'cid' => $cid,
                'i'   => $idx,
                'ticket_no' => $row['ticket_no'] ?? null,
                'elapsed_ms' => $this->msSince($t0),
            ]);

            // BILLING (aislado y con logs separados)
            $b0 = microtime(true);
            try {
                $billingEnabled = strtolower((string)$this->config->get('BILLING_ENABLED','true')) === 'true';
                if (!$billingEnabled) {
                    Logger::info('billing.skip.disabled', ['cid' => $cid, 'ticket_no' => $row['ticket_no'] ?? null]);
                } else {
                    Logger::info('billing.ensure.begin', [
                        'cid' => $cid,
                        'ticket_no' => $row['ticket_no'] ?? null,
                        'plate'     => $row['plate'] ?? null,
                    ]);
                    $this->ensurePaymentStub($pdo, $row);
                    Logger::info('billing.ensure.done', [
                        'cid' => $cid,
                        'ticket_no' => $row['ticket_no'] ?? null,
                        'elapsed_ms' => $this->msSince($b0),
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::error('billing.ensure.failed', [
                    'cid' => $cid,
                    'ticket_no' => $row['ticket_no'] ?? null,
                    'error' => $e->getMessage(),
                    'elapsed_ms' => $this->msSince($b0),
                ]);
                // no relanzar para no tumbar el ciclo
            }

            Logger::info('tickets.upsert.end', [
                'cid' => $cid,
                'i'   => $idx,
                'ticket_no' => $row['ticket_no'] ?? null,
                'total_ms'  => $this->msSince($t0),
            ]);
        }

        return $touched;
    }

    //////////////////////////////////////////////////////////
    // Stub/actualización de payment + llamada a vehicleBilling
    //////////////////////////////////////////////////////////
    private function ensurePaymentStub(PDO $pdo, array $t): void {
        $cid = $this->newCorrelationId('bill');
        $t0  = microtime(true);

        try {
            $ticketNo = trim((string)($t['ticket_no'] ?? ''));
            $plate    = isset($t['plate']) ? trim((string)$t['plate']) : '';

            if ($ticketNo === '') {
                Logger::warning('billing.stub.skip_empty_ticket', ['cid' => $cid, 'row' => $t]);
                return;
            }

            // ¿Existe ya payment?
            $chk = $pdo->prepare("SELECT 1 FROM payments WHERE ticket_no = :t LIMIT 1");
            $chk->execute([':t' => $ticketNo]);
            $exists = (bool)$chk->fetchColumn();

            $paidAt = $t['exit_at'] ?? $t['entry_at'] ?? null;

            try {
                $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable $e) {
                $driver = 'mysql';
            }
            $nowExpr = $driver === 'sqlite' ? "datetime('now')" : "NOW()";

            // Asegura columna `billin`
            $this->ensurePaymentsTableHasBillin($pdo);

            // Llama a billing y obtén valor numérico; si no hay, 0
            $resp      = $this->fetchVehicleBilling($plate, $cid);
            $billValue = $resp['bill_value'] ?? 0;
            if (!is_numeric($billValue)) $billValue = 0;
            $billValue = (float)$billValue;

            if (!$exists) {
                // INSERT
                $sql = "INSERT INTO payments (ticket_no, amount, method, paid_at, created_at, billin)
                        VALUES (:ticket_no, :amount, :method, :paid_at, {$nowExpr}, :billin)";
                $ins = $pdo->prepare($sql);
                $ins->execute([
                    ':ticket_no' => $ticketNo,
                    ':amount'    => 0.00,
                    ':method'    => 'pending',
                    ':paid_at'   => $paidAt,
                    ':billin'    => $billValue,
                ]);
                Logger::info('billing.insert.ok', [
                    'cid' => $cid, 'ticket_no' => $ticketNo, 'billin' => $billValue, 'row_count' => $ins->rowCount()
                ]);
            } else {
                // UPDATE (upsert branch)
                $sql = "UPDATE payments
                        SET billin = :billin,
                            method = COALESCE(method, 'pending'),
                            paid_at = COALESCE(paid_at, :paid_at)
                        WHERE ticket_no = :t
                        LIMIT 1";
                $up  = $pdo->prepare($sql);
                $up->execute([
                    ':billin'  => $billValue,
                    ':paid_at' => $paidAt,
                    ':t'       => $ticketNo,
                ]);
                Logger::info('billing.update.ok', [
                    'cid' => $cid, 'ticket_no' => $ticketNo, 'billin' => $billValue, 'row_count' => $up->rowCount()
                ]);
            }

            Logger::info('billing.stub.done', ['cid' => $cid, 'ticket_no' => $ticketNo, 'ms' => $this->msSince($t0)]);
        } catch (\PDOException $e) {
            Logger::error('billing.stub.sql_failed', [
                'cid' => $cid, 'ticket_no' => $t['ticket_no'] ?? null, 'sqlstate' => $e->getCode(), 'error' => $e->getMessage()
            ]);
            // no relanzar
        } catch (\Throwable $e) {
            Logger::error('billing.stub.failed', [
                'cid' => $cid, 'ticket_no' => $t['ticket_no'] ?? null, 'error' => $e->getMessage()
            ]);
            // no relanzar
        }
    }

    ////////////////////////////////////////////////////////
    // Asegura columna `billin` en la tabla payments (logs)
    ////////////////////////////////////////////////////////
    private function ensurePaymentsTableHasBillin(PDO $pdo): void {
        $cid = $this->newCorrelationId('schema');
        try {
            Logger::debug('payments.ensure_billin.check', ['cid' => $cid]);
            $columns = $this->getTableColumns($pdo, 'payments');

            if (!isset($columns['billin'])) {
                try {
                    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                } catch (\Throwable $e) {
                    $driver = 'mysql';
                }

                if ($driver === 'sqlite') {
                    $sql = "ALTER TABLE payments ADD COLUMN billin TEXT";
                    Logger::info('payments.ensure_billin.alter', ['cid' => $cid, 'driver' => $driver, 'sql' => $sql]);
                    $pdo->exec($sql);
                } else {
                    $sql = "ALTER TABLE payments ADD COLUMN billin LONGTEXT NULL";
                    Logger::info('payments.ensure_billin.alter', ['cid' => $cid, 'driver' => $driver, 'sql' => $sql]);
                    $pdo->exec($sql);
                }
                Logger::info('payments.ensure_billin.created', ['cid' => $cid, 'driver' => $driver]);
            } else {
                Logger::debug('payments.ensure_billin.exists', ['cid' => $cid]);
            }
        } catch (\Throwable $e) {
            Logger::error('payments.ensure_billin.failed', ['cid' => $cid, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    //////////////////////////////////////////////////////////////
    // Llamada a /api/v1/parkCost/vehicleBilling (con logs finos)
    //////////////////////////////////////////////////////////////
    private function fetchVehicleBilling(?string $plate, ?string $cid = null): array {
        $cid = $cid ?: $this->newCorrelationId('vb');

        $plate = trim((string)$plate);
        if ($plate === '') {
            Logger::warning('billing.http.skip_empty_plate', ['cid' => $cid]);
            return ['status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0];
        }

        $baseUrl = rtrim((string)$this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            Logger::error('billing.http.no_base_url', ['cid' => $cid]);
            return ['status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0];
        }

        $endpoint = $baseUrl . '/api/v1/parkCost/vehicleBilling';
        $accessToken = (string)($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
        if ($accessToken !== '') {
            $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . 'access_token=' . urlencode($accessToken);
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        $verifySsl = strtolower((string)$this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';

        $body = json_encode(['carNumber' => $plate], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);
        if (!$verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch) ?: 'Error desconocido';
            curl_close($ch);
            Logger::error('billing.http.failed', ['cid' => $cid, 'error' => $err]);
            return ['status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            Logger::warning('billing.http.non_json', ['cid' => $cid, 'status' => $status]);
            return ['status' => $status, 'body_raw' => $raw, 'json' => null, 'bill_value' => 0];
        }

        // Buscar candidato de monto en data
        $billValue = 0.0;
        if (isset($json['data']) && is_array($json['data'])) {
            foreach (['amount','total','totalCost','bill','cost'] as $k) {
                if (isset($json['data'][$k]) && is_numeric($json['data'][$k])) {
                    $billValue = (float)$json['data'][$k];
                    break;
                }
            }
        }

        Logger::info('billing.http.ok', ['cid' => $cid, 'status' => $status, 'bill_value' => $billValue]);
        return ['status' => $status, 'body_raw' => $raw, 'json' => $json, 'bill_value' => $billValue];
    }

    //////////////////////////////////////////////////////
    // Actualiza `payments.billin` (con logs y row count)
    //////////////////////////////////////////////////////
    private function upsertPaymentBillin(PDO $pdo, string $ticketNo, $billinValue, ?string $cid = null): void {
        $cid = $cid ?: $this->newCorrelationId('billin');
        $t0  = microtime(true);

        $this->ensurePaymentsTableHasBillin($pdo);

        // Si es texto vacío o nulo → 0
        if ($billinValue === null || $billinValue === '' || !is_numeric($billinValue)) {
            $billinValue = 0;
        }

        $sql = "UPDATE payments SET billin = :billin WHERE ticket_no = :t LIMIT 1";
        $st  = $pdo->prepare($sql);
        $st->execute([':billin' => $billinValue, ':t' => $ticketNo]);

        Logger::info('payments.billin.update.ok', [
            'cid' => $cid,
            'ticket_no' => $ticketNo,
            'billin' => $billinValue,
            'affected' => $st->rowCount(),
            'duration_ms' => $this->msSince($t0),
        ]);
    }

    private function extractParkRecords(array $payload): array {
        $candidates = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            if (isset($data['data']) && is_array($data['data'])) {
                $candidates[] = $data['data'];
            }
            if (isset($data['records']) && is_array($data['records'])) {
                $candidates[] = $data['records'];
            }
            if (isset($data['rows']) && is_array($data['rows'])) {
                $candidates[] = $data['rows'];
            }
            if (isset($data['list']) && is_array($data['list'])) {
                $candidates[] = $data['list'];
            }
            if ($this->isListArray($data)) {
                $candidates[] = $data;
            }
        }

        if (isset($payload['records']) && is_array($payload['records'])) {
            $candidates[] = $payload['records'];
        }
        if (isset($payload['rows']) && is_array($payload['rows'])) {
            $candidates[] = $payload['rows'];
        }
        if (isset($payload['list']) && is_array($payload['list'])) {
            $candidates[] = $payload['list'];
        }
        if ($this->isListArray($payload)) {
            $candidates[] = $payload;
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $this->isListArray($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeParkRecordRow(array $row): ?array {
        $ticketNo = $row['ticketNo']
            ?? $row['ticket_no']
            ?? $row['id']
            ?? $row['recordId']
            ?? $row['orderNo']
            ?? $row['parkRecordId']
            ?? $row['serialNo']
            ?? null;

        if ($ticketNo === null) {
            return null;
        }

        $ticketNo = trim((string) $ticketNo);
        if ($ticketNo === '') {
            return null;
        }

        $plate = $row['plate']
            ?? $row['plateNo']
            ?? $row['carNumber']
            ?? $row['carNo']
            ?? $row['vehicleNumber']
            ?? $row['vehicleNo']
            ?? null;
        $plate = $plate !== null ? trim((string) $plate) : null;
        if ($plate === '') {
            $plate = null;
        }

        $entry = $row['checkInTime']
            ?? $row['enterTime']
            ?? $row['entryTime']
            ?? $row['inTime']
            ?? $row['in_time']
            ?? $row['parkInTime']
            ?? null;
        $entry = $this->normalizeDateTime($entry);

        $exit = $row['checkOutTime']
            ?? $row['leaveTime']
            ?? $row['exitTime']
            ?? $row['outTime']
            ?? $row['out_time']
            ?? $row['parkOutTime']
            ?? null;
        $exit = $this->normalizeDateTime($exit);

        $duration = null;
        if (isset($row['parkingTime'])) {
            $duration = $this->parseDurationMinutes($row['parkingTime']);
        }
        if ($duration === null && isset($row['parkingDuration'])) {
            $duration = $this->parseDurationMinutes($row['parkingDuration']);
        }
        if ($duration === null && isset($row['duration'])) {
            $duration = $this->parseDurationMinutes($row['duration']);
        }
        if ($duration === null && isset($row['durationMin'])) {
            $duration = $this->parseDurationMinutes($row['durationMin']);
        }

        if ($duration === null && $entry !== null && $exit !== null) {
            $entryTs = strtotime($entry) ?: null;
            $exitTs = strtotime($exit) ?: null;
            if ($entryTs !== null && $exitTs !== null && $exitTs >= $entryTs) {
                $duration = (int) ceil(($exitTs - $entryTs) / 60);
            }
        }

        if ($duration !== null && $entry === null && $exit !== null) {
            $exitTs = strtotime($exit) ?: null;
            if ($exitTs !== null) {
                $entry = date('Y-m-d H:i:s', $exitTs - ($duration * 60));
            }
        }

        $amount = $row['amount']
            ?? $row['payAmount']
            ?? $row['payMoney']
            ?? $row['receivableMoney']
            ?? $row['chargeFee']
            ?? $row['shouldMoney']
            ?? null;
        if ($amount !== null && $amount !== '') {
            $amount = round((float) $amount, 2);
        } else {
            $amount = null;
        }

        $status = strtoupper((string) ($row['status'] ?? ''));
        if (!in_array($status, ['OPEN', 'CLOSED', 'PAID'], true)) {
            $status = $exit !== null ? 'CLOSED' : 'OPEN';
        }

        $rawJson = json_encode($row, JSON_UNESCAPED_UNICODE);
        if ($rawJson === false) {
            $rawJson = json_encode($row);
        }

        return [
            'ticket_no' => $ticketNo,
            'plate' => $plate,
            'status' => $status,
            'entry_at' => $entry,
            'exit_at' => $exit,
            'duration_min' => $duration,
            'amount' => $amount,
            'source' => 'hamachi_remote',
            'raw_json' => $rawJson,
        ];
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

    public function invoiceClosedTickets() {
        try {
            $pdo = DB::pdo($this->config);
            $g4s = new G4SClient($this->config);
            Schema::ensureInvoiceMetadataColumns($pdo);

            $hourlyRate = $this->getHourlyRate($pdo);
            try {
                $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable $e) {
                $driver = 'mysql';
            }
            $nowExpr = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';

            // Seleccionar tickets CERRADOS con pagos y sin factura
            $sql = "SELECT t.* FROM tickets t
                    WHERE t.status='CLOSED'
                    AND EXISTS (SELECT 1 FROM payments p WHERE p.ticket_no=t.ticket_no)
                    AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.ticket_no=t.ticket_no)";
            $tickets = $pdo->query($sql)->fetchAll();

            $results = [];
            foreach ($tickets as $t) {
                $ps = $pdo->prepare("SELECT amount, method, paid_at FROM payments WHERE ticket_no=? ORDER BY paid_at ASC");
                $ps->execute([$t['ticket_no']]);
                $payments = $ps->fetchAll();
                $paymentsTotal = 0.0;
                foreach ($payments as $p) {
                    $paymentsTotal += (float)($p['amount'] ?? 0);
                }

                $ticketContext = $t;
                $ticketContext['payments_total'] = $paymentsTotal;
                $billing = $this->calculateTicketBilling($ticketContext, $hourlyRate, true);
                if ($billing['total'] <= 0) {
                    continue;
                }

                $ticketContext['amount'] = $billing['total'];
                if ($billing['duration_minutes'] !== null) {
                    $ticketContext['duration_min'] = $billing['duration_minutes'];
                }

                $paidAt = $t['exit_at'] ?? $t['entry_at'] ?? date('Y-m-d H:i:s');
                $syntheticPayment = [
                    'ticket_no' => $t['ticket_no'],
                    'amount' => $billing['total'],
                    'method' => $billing['mode'] === 'hourly' ? 'hourly' : 'auto',
                    'paid_at' => $paidAt,
                ];

                $payload = $g4s->buildInvoiceFromTicket($ticketContext, [$syntheticPayment]);

                $resp = $g4s->submitInvoice($payload);

                $uuid = $resp['uuid'] ?? $resp['UUID'] ?? null;
                $status = $uuid ? 'OK' : 'ERROR';

                $insertSql = "INSERT INTO invoices (ticket_no, total, uuid, status, request_json, response_json, receptor_nit, entry_at, exit_at, duration_min, hours_billed, billing_mode, hourly_rate, monthly_rate, created_at)
                                VALUES (:ticket_no, :total, :uuid, :status, :request_json, :response_json, :receptor_nit, :entry_at, :exit_at, :duration_min, :hours_billed, :billing_mode, :hourly_rate, :monthly_rate, {$nowExpr})";
                $ins = $pdo->prepare($insertSql);
                $ins->execute([
                    ':ticket_no' => $t['ticket_no'],
                    ':total' => $billing['total'],
                    ':uuid' => $uuid,
                    ':status' => $status,
                    ':request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ':response_json' => json_encode($resp, JSON_UNESCAPED_UNICODE),
                    ':receptor_nit' => $t['receptor_nit'] ?? null,
                    ':entry_at' => $t['entry_at'] ?? null,
                    ':exit_at' => $t['exit_at'] ?? null,
                    ':duration_min' => $billing['duration_minutes'],
                    ':hours_billed' => $billing['hours'],
                    ':billing_mode' => $billing['mode'],
                    ':hourly_rate' => $billing['hourly_rate'],
                    ':monthly_rate' => null,
                ]);

                $results[] = [
                    'ticket_no' => $t['ticket_no'],
                    'total' => $billing['total'],
                    'status' => $status,
                    'uuid' => $uuid,
                    'billing_mode' => $billing['mode'],
                ];
            }

            Http::json(['ok'=>true, 'invoices'=> $results, 'count'=> count($results)]);
        } catch (\Throwable $e) {
            Logger::error('invoiceClosedTickets error', ['e'=>$e->getMessage()]);
            Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function felIssuedRT() {
        try {
            $g4s = new \App\Services\G4SClient($this->config);
            $filters = [
                'from'      => $_GET['from'] ?? date('Y-m-d'),
                'to'        => $_GET['to']   ?? date('Y-m-d'),
                'page'      => (int)($_GET['page'] ?? 1),
                'page_size' => (int)($_GET['page_size'] ?? 50),
            ];
            if (!empty($_GET['nitReceptor'])) $filters['nitReceptor'] = $_GET['nitReceptor'];
            if (!empty($_GET['uuid']))        $filters['uuid'] = $_GET['uuid'];

            $resp = $g4s->issuedListRT($filters);

            // NormalizaciÃ³n â†’ filas para tabla
            $rows = [];
            $candidates = [
                $resp['Response']['Identifier'] ?? null,
                $resp['ResponseData']['ResponseDataSet'] ?? null,
                $resp['items'] ?? null,
                $resp['data'] ?? null,
                $resp
            ];
            foreach ($candidates as $cand) {
                if (empty($cand)) continue;
                $iter = is_array($cand) && (array_keys($cand) !== range(0, count($cand) - 1)) ? [$cand] : (is_array($cand) ? $cand : []);
                foreach ($iter as $r) {
                    if (!is_array($r)) continue;
                    $rows[] = [
                        'ticket_no'=> $r['InternalID'] ?? $r['ANumber'] ?? null,
                        'fecha'    => $r['IssuedTimeStamp'] ?? $r['fecha'] ?? $resp['Response']['TimeStamp'] ?? null,
                        'serie'    => $r['Serial'] ?? $r['serie'] ?? null,
                        'numero'   => $r['ANumber'] ?? $r['numero'] ?? null,
                        'uuid'     => $r['DocumentGUID'] ?? $r['UUID'] ?? $r['uuid'] ?? null,
                        'receptor' => $r['ReceiverTaxID'] ?? $r['nitReceptor'] ?? $r['ReceiverName'] ?? null,
                        'total'    => $r['TotalAmount'] ?? $r['total'] ?? null,
                        'estado'   => $resp['Response']['LastResult'] ?? $r['estado'] ?? null,
                    ];
                }
                if ($rows) break;
            }

            \App\Utils\Http::json(['ok'=>true, 'rows'=>$rows, 'raw'=>$resp]);
        } catch (\Throwable $e) {
            \App\Utils\Logger::error('felIssuedRT error', ['e'=>$e->getMessage()]);
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function getTickets(){
        try {
            $g4s = new \App\Services\G4SClient($this->config);
            $filters = [
                'from'      => $_GET['from'] ?? date('Y-m-d'),
                'to'        => $_GET['to']   ?? date('Y-m-d'),
                'page'      => 1,
                'page_size' => 50,
            ];
            // Puedes mapear lo que venga de G4S a una tabla simple para el dashboard
            $resp = $g4s->issuedListRT($filters);

            $src  = $resp['Response']['Identifier'] ?? $resp['items'] ?? $resp['data'] ?? $resp;
            $iter = is_array($src) && (array_keys($src) !== range(0, count($src)-1)) ? [$src] : (is_array($src) ? $src : []);
            $rows = [];
            foreach ($iter as $r) {
                if (!is_array($r)) continue;
                $rows[] = [
                    'name'    => $r['ReceiverName'] ?? ($r['ReceiverTaxID'] ?? '—'),
                    'checkIn' => $r['IssuedTimeStamp'] ?? ($r['fecha'] ?? ''),
                    'checkOut'=> '',
                ];
            }
            \App\Utils\Http::json(['ok'=>true,'data'=>$rows]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }                   

    function is_assoc(array $arr): bool {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

   public function invoiceOne(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw  = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];

        $ticketNo    = trim((string)($body['ticket_no'] ?? ''));
        $receptorNit = strtoupper(trim((string)($body['receptor_nit'] ?? 'CF'))); // ← permite CF
        $mode        = (string)($body['mode'] ?? 'hourly'); // hourly | monthly | custom
        $customTotal = isset($body['custom_total']) ? (float)$body['custom_total'] : null;

        // Log de entrada
        $this->debugLog('fel_invoice_in.txt', [
            'body'   => $body,
            'server' => $_SERVER,
        ]);

        if ($ticketNo === '') {
            echo json_encode(['ok' => false, 'error' => 'ticket_no requerido']); return;
        }
        if ($mode === 'custom' && (!is_finite($customTotal) || $customTotal <= 0)) {
            echo json_encode(['ok' => false, 'error' => 'custom_total inválido']); return;
        }

        // Validación opcional: si NO es CF, entonces debe ser solo dígitos
        if ($receptorNit !== 'CF' && !ctype_digit($receptorNit)) {
            echo json_encode(['ok' => false, 'error' => 'NIT inválido (use CF o solo dígitos)']); return;
        }

        // 1) Calcula montos (puede que tu función devuelva 3 o 4 valores; manejamos ambos)
        $calc = $this->resolveTicketAmount($ticketNo, $mode, $customTotal);
        // Normaliza retorno
        $hours   = (float)($calc[0] ?? 0);
        $minutes = (int)($calc[1] ?? 0);
        $total   = (float)($calc[2] ?? 0);
        $extra   = is_array($calc[3] ?? null) ? $calc[3] : [];

        $durationMin = (int)($hours * 60 + $minutes);
        $hoursBilled = (int)ceil($durationMin / 60);

        // 2) Cliente G4S + payload
        $cfg    = new \Config\Config(__DIR__ . '/../../.env');
        $client = new \App\Services\G4SClient($cfg);

        $payload = [
            'ticket_no'    => $ticketNo,
            'receptor_nit' => $receptorNit,          // ← puede ser “CF”
            'total'        => $total,
            'hours'        => $hours,
            'minutes'      => $minutes,
            'mode'         => $mode,
            // 'receptor_nombre' => ($receptorNit === 'CF' ? 'CONSUMIDOR FINAL' : null), // si tu G4S lo admite
        ];

        // 3) Certificación
        $res = $client->submitInvoice($payload);

        $this->debugLog('fel_invoice_out.txt', [
            'request_payload' => $payload,
            'g4s_response'    => $res,
        ]);

        $ok    = (bool)($res['ok'] ?? false);
        $uuid  = $res['uuid']  ?? null;
        $error = $res['error'] ?? null;

        // 4) Persistir en BD: invoices + update tickets.status = OPEN
        $dsn  = $cfg->get('DB_DSN',  'mysql:host=127.0.0.1;dbname=zkt;charset=utf8mb4');
        $user = $cfg->get('DB_USER', 'root');
        $pass = $cfg->get('DB_PASS', '');

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->beginTransaction();

        // Intenta leer entry/exit si existen
        $entryAt = null;
        $exitAt  = null;
        try {
            $q = $pdo->prepare("SELECT entry_at, exit_at FROM tickets WHERE ticket_no = :t LIMIT 1");
            $q->execute([':t' => $ticketNo]);
            if ($row = $q->fetch()) {
                $entryAt = $row['entry_at'] ?? null;
                $exitAt  = $row['exit_at']  ?? null;
            }
        } catch (\Throwable $e) {
            // Si no existen esas columnas, continúa
        }

        $hourlyRate  = $extra['hourly_rate']  ?? null;
        $monthlyRate = $extra['monthly_rate'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO invoices
            (
                ticket_no, total, uuid, status,
                request_json, response_json, created_at,
                receptor_nit, entry_at, exit_at,
                duration_min, hours_billed, billing_mode,
                hourly_rate, monthly_rate
            )
            VALUES
            (
                :ticket_no, :total, :uuid, :status,
                :request_json, :response_json, NOW(),
                :receptor_nit, :entry_at, :exit_at,
                :duration_min, :hours_billed, :billing_mode,
                :hourly_rate, :monthly_rate
            )
        ");

        $stmt->execute([
            ':ticket_no'     => $ticketNo,
            ':total'         => $total,
            ':uuid'          => $uuid,
            ':status'        => $ok ? 'CERTIFIED' : 'FAILED',
            ':request_json'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':response_json' => json_encode($res,     JSON_UNESCAPED_UNICODE),
            ':receptor_nit'  => $receptorNit,              // ← puede ser CF
            ':entry_at'      => $entryAt,
            ':exit_at'       => $exitAt,
            ':duration_min'  => $durationMin,
            ':hours_billed'  => $hoursBilled,
            ':billing_mode'  => $mode,
            ':hourly_rate'   => $hourlyRate,
            ':monthly_rate'  => $monthlyRate,
        ]);

        // Actualiza ticket a OPEN (como pediste)
        $up = $pdo->prepare("UPDATE tickets SET status = 'OPEN' WHERE ticket_no = :t");
        $up->execute([':t' => $ticketNo]);

        $pdo->commit();

        echo json_encode([
            'ok'      => $ok,
            'uuid'    => $uuid,
            'message' => $ok ? 'Factura certificada' : 'No se pudo certificar (registrada en BD)',
            'error'   => $error,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->debugLog('fel_invoice_exc.txt', ['exception' => $e->getMessage()]);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}



    /** Helper simple para logging */
    private function debugLog(string $file, array $data): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(
            $dir . '/' . $file,
            '[' . date('c') . "]\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n",
            FILE_APPEND
        );
    }

    /** Devuelve [hours, minutes, total] segÃºn tu lÃ³gica actual */
    private function resolveTicketAmount(string $ticketNo, string $mode, ?float $customTotal): array
    {
        if ($mode === 'custom') {
            return [null, null, (float)$customTotal];
        }
        // AquÃ­ usa tu cÃ¡lculo que ya corrigimos con â€œceil a horas exactasâ€.
        // Hardcode simple de ejemplo:
        return [2, 0, 60.00];
    }


    public function getTicketsFromDB() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            // Ãºltimos 200 tickets (ejemplo)
            $st = $pdo->query("SELECT ticket_no, plate, status, entry_at, exit_at FROM tickets ORDER BY created_at DESC LIMIT 200");
            $rows = [];
            while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'name'    => $r['plate'] ?? $r['ticket_no'],
                    'checkIn' => $r['entry_at'],
                    'checkOut'=> $r['exit_at'] ?? '',
                ];
            }
            \App\Utils\Http::json(['ok'=>true,'data'=>$rows]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>true,'data'=>[], 'warning'=>$e->getMessage()]);
        }
    }

    public function facturacionList() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);

            $sql = "
            SELECT
                t.ticket_no,
                COALESCE(t.exit_at, t.entry_at) AS fecha,
                COALESCE(SUM(p.amount), t.amount, 0) AS payments_total,
                t.receptor_nit AS receptor,
                t.duration_min,
                t.entry_at,
                t.exit_at,
                t.amount AS ticket_amount,
                NULL AS uuid,
                NULL AS estado
            FROM tickets t
            LEFT JOIN payments p ON p.ticket_no = t.ticket_no
            WHERE t.status = 'OPEN'
                AND NOT EXISTS (
                SELECT 1 FROM invoices i2
                WHERE i2.ticket_no = t.ticket_no
                    AND i2.status IN ('PENDING','OK')
                )
            GROUP BY
                t.ticket_no,
                COALESCE(t.exit_at, t.entry_at),
                t.amount,
                t.receptor_nit,
                t.duration_min,
                t.entry_at,
                t.exit_at
            ORDER BY fecha DESC
            LIMIT 500
            ";

            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            $hourlyRate = $this->getHourlyRate($pdo);
            foreach ($rows as &$row) {
                $row['payments_total'] = isset($row['payments_total']) ? (float) $row['payments_total'] : null;
                $durationMin = isset($row['duration_min']) ? (int) $row['duration_min'] : null;
                if (($durationMin === null || $durationMin <= 0) && !empty($row['entry_at']) && !empty($row['exit_at'])) {
                    $entryTs = strtotime((string) $row['entry_at']);
                    $exitTs = strtotime((string) $row['exit_at']);
                    if ($entryTs && $exitTs && $exitTs > $entryTs) {
                        $durationMin = (int) round(($exitTs - $entryTs) / 60);
                    }
                }
                $billing = $this->calculateTicketBilling($row, $hourlyRate, true);
                $row['duration_min'] = $billing['duration_minutes'];
                $row['duration_minutes'] = $billing['duration_minutes'];
                $row['hours'] = $billing['hours'];
                $row['total'] = $billing['total'];
                $row['billing_mode'] = $billing['mode'];
                $row['hourly_rate'] = $billing['hourly_rate'];
            }
            unset($row);
            \App\Utils\Http::json(['ok'=>true,'rows'=>$rows]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function reportsTickets() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);

            $status = strtoupper(trim((string)($_GET['status'] ?? 'ANY')));
            if ($status === 'ALL') { $status = 'ANY'; }

            $filters = [
                'from' => $_GET['from'] ?? null,
                'to' => $_GET['to'] ?? null,
            ];

            $sql = "
                SELECT
                    t.ticket_no,
                    t.plate,
                    t.receptor_nit,
                    t.status,
                    t.entry_at,
                    t.exit_at,
                    t.duration_min,
                    COALESCE(SUM(p.amount), t.amount, 0)        AS payments_total,
                    COUNT(p.id)                                  AS payments_count,
                    MIN(p.paid_at)                               AS first_payment_at,
                    MAX(p.paid_at)                               AS last_payment_at
                FROM tickets t
                LEFT JOIN payments p ON p.ticket_no = t.ticket_no
                WHERE 1=1
            ";

            $args = [];
            if ($filters['from']) {
                $sql .= " AND COALESCE(t.exit_at, t.entry_at) >= :from";
                $args[':from'] = $filters['from'] . ' 00:00:00';
            }
            if ($filters['to']) {
                $sql .= " AND COALESCE(t.exit_at, t.entry_at) <= :to";
                $args[':to'] = $filters['to'] . ' 23:59:59';
            }
            if ($status !== 'ANY') {
                $sql .= " AND t.status = :status";
                $args[':status'] = $status;
            }

            $sql .= "
                GROUP BY
                    t.ticket_no,
                    t.plate,
                    t.receptor_nit,
                    t.status,
                    t.entry_at,
                    t.exit_at,
                    t.duration_min,
                    t.amount
                ORDER BY COALESCE(t.exit_at, t.entry_at) DESC, t.ticket_no DESC
                LIMIT 1000
            ";

            $st = $pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            $hourlyRate = $this->getHourlyRate($pdo);
            foreach ($rows as &$row) {
                $row['payments_total'] = isset($row['payments_total']) ? (float) $row['payments_total'] : null;
                $billing = $this->calculateTicketBilling($row, $hourlyRate);
                $row['duration_min'] = $billing['duration_minutes'];
                $row['duration_minutes'] = $billing['duration_minutes'];
                $row['hours'] = $billing['hours'];
                $row['billing_mode'] = $billing['mode'];
                $row['hourly_rate'] = $billing['hourly_rate'];
                $row['billing_total'] = $billing['total'];
                $row['total'] = $billing['total'];
            }
            unset($row);

            $plate = trim((string)($_GET['plate'] ?? ''));
            $nitRaw = trim((string)($_GET['nit'] ?? ''));
            $nitFilter = $nitRaw === '' ? '' : strtoupper($nitRaw);
            $minTotal = isset($_GET['min_total']) && $_GET['min_total'] !== '' ? (float)$_GET['min_total'] : null;
            $maxTotal = isset($_GET['max_total']) && $_GET['max_total'] !== '' ? (float)$_GET['max_total'] : null;

            $rows = array_values(array_filter($rows, function ($row) use ($plate, $nitFilter, $minTotal, $maxTotal) {
                if ($plate !== '' && stripos((string)($row['plate'] ?? ''), $plate) === false) {
                    return false;
                }
                if ($nitFilter !== '') {
                    $rowNit = strtoupper(trim((string)($row['receptor_nit'] ?? '')));
                    if ($nitFilter === 'CF') {
                        if ($rowNit !== '' && $rowNit !== 'CF') {
                            return false;
                        }
                    } elseif ($rowNit !== $nitFilter) {
                        return false;
                    }
                }
                $amount = (float)($row['billing_total'] ?? $row['total'] ?? 0);
                if ($minTotal !== null && $amount < $minTotal) {
                    return false;
                }
                if ($maxTotal !== null && $amount > $maxTotal) {
                    return false;
                }
                return true;
            }));

            if ($rows) {
                $ticketNos = array_column($rows, 'ticket_no');
                $placeholders = implode(',', array_fill(0, count($ticketNos), '?'));
                $payStmt = $pdo->prepare("SELECT ticket_no, amount, method, paid_at FROM payments WHERE ticket_no IN ($placeholders) ORDER BY paid_at ASC");
                $payStmt->execute($ticketNos);
                $paymentMap = [];
                while ($p = $payStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $ticket = $p['ticket_no'];
                    if (!isset($paymentMap[$ticket])) {
                        $paymentMap[$ticket] = [];
                    }
                    $paymentMap[$ticket][] = [
                        'amount' => (float)($p['amount'] ?? 0),
                        'method' => $p['method'] ?? null,
                        'paid_at'=> $p['paid_at'] ?? null,
                    ];
                }
            } else {
                $paymentMap = [];
            }

            $totalTickets = count($rows);
            $totalAmount = 0.0;
            $withPayments = 0;
            $statusBreakdown = [];
            $totalMinutes = 0;
            $countDuration = 0;

            foreach ($rows as &$row) {
                $amount = (float)($row['billing_total'] ?? $row['total'] ?? 0);
                $totalAmount += $amount;

                $row['payments'] = $paymentMap[$row['ticket_no']] ?? [];
                $row['payments_count'] = count($row['payments']);
                if ($row['payments_count'] > 0) {
                    $withPayments++;
                }

                $statusKey = strtoupper((string)($row['status'] ?? 'DESCONOCIDO'));
                if (!isset($statusBreakdown[$statusKey])) {
                    $statusBreakdown[$statusKey] = 0;
                }
                $statusBreakdown[$statusKey]++;

                $duration = $row['duration_min'];
                if ($duration === null && !empty($row['entry_at']) && !empty($row['exit_at'])) {
                    $entryTs = strtotime((string)$row['entry_at']);
                    $exitTs = strtotime((string)$row['exit_at']);
                    if ($entryTs && $exitTs && $exitTs >= $entryTs) {
                        $duration = (int) floor(($exitTs - $entryTs) / 60);
                    }
                }
                if ($duration !== null) {
                    $duration = (int) $duration;
                    if ($duration >= 0) {
                        $totalMinutes += $duration;
                        $countDuration++;
                    }
                }
                $row['duration_min'] = $duration;

                $row['total'] = round($amount, 2);
            }
            unset($row);

            $averageAmount = $totalTickets > 0 ? round($totalAmount / $totalTickets, 2) : 0.0;
            $averageMinutes = $countDuration > 0 ? (int) round($totalMinutes / $countDuration) : null;

            \App\Utils\Http::json([
                'ok' => true,
                'generated_at' => date('c'),
                'filters' => [
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'status' => $status !== 'ANY' ? $status : 'ANY',
                    'plate' => $plate !== '' ? $plate : null,
                    'nit' => $nitRaw !== '' ? $nitRaw : null,
                    'min_total' => $minTotal,
                    'max_total' => $maxTotal,
                ],
                'summary' => [
                    'total_tickets' => $totalTickets,
                    'total_amount' => round($totalAmount, 2),
                    'average_amount' => $averageAmount,
                    'with_payments' => $withPayments,
                    'without_payments' => $totalTickets - $withPayments,
                    'total_minutes' => $totalMinutes,
                    'average_minutes' => $averageMinutes,
                    'status_breakdown' => $statusBreakdown,
                ],
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function facturacionEmitidas() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);

            // filtros opcionales ?from=YYYY-MM-DD&to=YYYY-MM-DD&uuid=...&nit=...&status=OK|ERROR|PENDING|ANY
            $from   = $_GET['from']   ?? null;
            $to     = $_GET['to']     ?? null;
            $uuid   = $_GET['uuid']   ?? null;
            $nit    = $_GET['nit']    ?? null;
            $status = strtoupper(trim($_GET['status'] ?? 'ANY'));

            $sql = "
            SELECT
                i.id,
                i.ticket_no,
                COALESCE(t.exit_at, t.entry_at) AS fecha,
                i.total,
                i.uuid,
                i.status,
                t.receptor_nit AS receptor
            FROM invoices i
            JOIN tickets t ON t.ticket_no = i.ticket_no
            WHERE 1=1
            ";

            $args = [];
            if ($status !== 'ANY') { $sql .= " AND i.status = :status"; $args[':status'] = $status; }
            if ($from)   { $sql .= " AND COALESCE(t.exit_at, t.entry_at) >= :from"; $args[':from'] = $from . ' 00:00:00'; }
            if ($to)     { $sql .= " AND COALESCE(t.exit_at, t.entry_at) <= :to";   $args[':to']   = $to   . ' 23:59:59'; }
            if ($uuid)   { $sql .= " AND i.uuid = :uuid";                            $args[':uuid'] = $uuid; }
            if ($nit)    { $sql .= " AND (t.receptor_nit = :nit OR (:nit='CF' AND (t.receptor_nit IS NULL OR t.receptor_nit='CF')))"; $args[':nit'] = $nit; }

            $sql .= " ORDER BY fecha DESC, i.id DESC LIMIT 1000";

            $st = $pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

            \App\Utils\Http::json(['ok'=>true,'rows'=>$rows,'filters'=>['from'=>$from,'to'=>$to,'uuid'=>$uuid,'nit'=>$nit,'status'=>$status]]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }
    
    public function felPdf() {
        try {
            $uuid = $_GET['uuid'] ?? '';
            if ($uuid === '') throw new \InvalidArgumentException('uuid requerido');
            $g4s  = new \App\Services\G4SClient($this->config);

            // PDF binario como base64 o bytes segÃºn proveedor; aquÃ­ asumimos base64 en Response.Data
            $respStr = $g4s->requestTransaction([
                'Transaction' => 'GET_DOCUMENT',
                'Data1'       => $uuid,  // UUID
                'Data2'       => 'PDF',  // tipo
            ]);
            $resp = is_string($respStr) ? json_decode($respStr, true) : $respStr;
            $b64  = $resp['Response']['Data'] ?? $resp['Data'] ?? null;

            if (!$b64) throw new \RuntimeException('PDF no disponible en respuesta G4S');
            $bin = base64_decode($b64, true);
            if ($bin === false) throw new \RuntimeException('PDF inválido');

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="fel-'.$uuid.'.pdf"');
            echo $bin;
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    public function felXml() {
        try {
            $uuid = $_GET['uuid'] ?? '';
            if ($uuid === '') throw new \InvalidArgumentException('uuid requerido');
            $g4s  = new \App\Services\G4SClient($this->config);

            $respStr = $g4s->requestTransaction([
                'Transaction' => 'GET_XML',
                'Data1'       => $uuid,     // UUID
            ]);
            $resp = is_string($respStr) ? json_decode($respStr, true) : $respStr;
            $b64  = $resp['Response']['Data'] ?? $resp['Data'] ?? null;

            if (!$b64) throw new \RuntimeException('XML no disponible en respuesta G4S');
            $xml = base64_decode($b64, true);
            if ($xml === false) throw new \RuntimeException('XML inválido');

            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="fel-'.$uuid.'.xml"');
            echo $xml;
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    private function calculateTicketBilling(array $ticket, ?float $hourlyRate, bool $enforceMinimumHour = true): array {
        $tz = $this->appTimezone();

        // Normaliza entrada/salida a DateTimeImmutable en TZ de la app
        $entry = $this->parseAppDateTime($ticket['entry_at'] ?? null);
        $exit  = $this->parseAppDateTime($ticket['exit_at']  ?? null);

        if ($entry === null) {
            return [
                'total'           => 0.0,
                'duration_minutes'=> null,
                'hours'           => null,
                'mode'            => 'ticket_amount',
                'hourly_rate'     => $hourlyRate,
                'payments_total'  => isset($ticket['payments_total']) ? (float)$ticket['payments_total'] : null,
                'ticket_amount'   => isset($ticket['ticket_amount'])  ? (float)$ticket['ticket_amount']  : null,
            ];
        }

        if ($exit === null) {
            $exit = new \DateTimeImmutable('now', $tz); // â€œahoraâ€ en misma TZ
        }

        // Diferencia en segundos (no negativa)
        $diffSec = max(0, $exit->getTimestamp() - $entry->getTimestamp());

        // === Regla pedida ===
        // - Cobro por hora redondeando hacia arriba (ceil), respecto al tiempo real.
        // - MÃ­nimo 1 hora si hubo estancia (>0 segundos).
        // Ejemplos que se cumplen:
        //  5:14 â†’ 6:16  = 1h 02m => ceil(3720/3600)=2h
        //  5:14 â†’ 7:13  = 1h 59m => ceil(7140/3600)=2h
        //  5:14 â†’ 7:14  = 2h 00m => ceil(7200/3600)=2h
        //  5:14 â†’ 7:15  = 2h 01m => ceil(7260/3600)=3h
        $billedHours = 0.0;
        $durationMin = null;

        if ($diffSec > 0) {
            if ($enforceMinimumHour) {
                $billedHours = (float) ceil($diffSec / 3600);
                if ($billedHours < 1.0) $billedHours = 1.0;
                $durationMin = (int) ($billedHours * 60); // mÃºltiplo exacto de 60
            } else {
                // sin forzar hora mÃ­nima: solo ceil a minutos
                $mins = (int) ceil($diffSec / 60.0);
                $durationMin = $mins > 0 ? $mins : null;
                $billedHours = $durationMin !== null ? ($durationMin / 60.0) : 0.0;
            }
        } else {
            // sin estancia
            if ($enforceMinimumHour) {
                // si quieres que 0s NO cobren, deja duraciÃ³n en null y horas 0
                $billedHours = 0.0;
                $durationMin = null;
            } else {
                $billedHours = 0.0;
                $durationMin = null;
            }
        }

        // Totales alternativos disponibles
        $paymentsTotal = isset($ticket['payments_total']) ? (float) $ticket['payments_total'] : null;
        $ticketAmount  = isset($ticket['ticket_amount'])  ? (float) $ticket['ticket_amount']  : null;

        // Determinar total
        $mode        = 'ticket_amount';
        $total       = 0.0;
        $appliedRate = null;

        if ($hourlyRate !== null && $billedHours > 0) {
            $total       = round($billedHours * $hourlyRate, 2);
            $mode        = 'hourly';
            $appliedRate = $hourlyRate;
        } elseif ($ticketAmount !== null && $ticketAmount > 0) {
            $total = round($ticketAmount, 2);
            $mode  = 'ticket_amount';
        } elseif ($paymentsTotal !== null && $paymentsTotal > 0) {
            $total = round($paymentsTotal, 2);
            $mode  = 'payments';
        }

        return [
            'total'            => $total,
            'duration_minutes' => $durationMin,
            'hours'            => $durationMin !== null ? ($durationMin / 60.0) : null,
            'mode'             => $mode,
            'hourly_rate'      => $appliedRate,
            'payments_total'   => $paymentsTotal,
            'ticket_amount'    => $ticketAmount,
        ];
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
            return str_repeat('•', max($length, 4));
        }
        return substr($value, 0, $visible)
            . str_repeat('•', max(3, $length - ($visible * 2)))
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

    