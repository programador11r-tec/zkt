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
            //Logger::info('park.sync.start', ['cid' => $cid]);

            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') {
                //Logger::error('park.sync.no_base_url', ['cid' => $cid]);
                Http::json(['ok' => false, 'error' => 'HAMACHI_PARK_BASE_URL no está configurado.'], 400);
                return;
            }

            $accessToken = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            if ($accessToken === '') {
                //Logger::warning('park.sync.no_token', ['cid' => $cid]);
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
            /*Logger::debug('park.sync.req', [
                'cid'        => $cid,
                'endpoint'   => $endpoint,
                'headers'    => $headers,
                'verify_ssl' => $verifySsl,
                'pageNo'     => $pageNo,
                'pageSize'   => $pageSize,
            ]);*/

            // cURL request
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

            // Opcional: CONNECT_TO (SNI)
            $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', '')); // ej: "localhost:8098:25.21.54.208:8098"
            if ($connectTo !== '') {
                curl_setopt($ch, CURLOPT_CONNECT_TO, [$connectTo]);
                //Logger::debug('http.connect_to', ['cid' => $cid, 'connect_to' => $connectTo]);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err  = curl_error($ch) ?: 'Error desconocido';
                $info = curl_getinfo($ch) ?: [];
                curl_close($ch);

               /* Logger::error('park.sync.http_failed', [
                    'cid'               => $cid,
                    'endpoint'          => $endpoint,
                    'error'             => $err,
                    'curl_info'         => $info,
                    'duration_ms'       => $this->msSince($t0),
                    'hint'              => 'Verifica IP/puerto, firewall, y que BASE_URL/CONNECT_TO sean correctos',
                ]);*/
                throw new \RuntimeException('Error al contactar API remota: ' . $err);
            }

            $info   = curl_getinfo($ch) ?: [];
            $status = (int)($info['http_code'] ?? 0);
            curl_close($ch);

            $len = strlen($raw);
            /*Logger::info('park.sync.http_ok', [
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
            ]);*/

            if ($status < 200 || $status >= 300) {
                $preview = substr($raw, 0, 500);
                //Logger::error('park.sync.bad_status', ['cid' => $cid, 'status' => $status, 'preview' => $preview]);
                throw new \RuntimeException('API remota respondió ' . $status . ': ' . $preview);
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                //Logger::error('park.sync.non_json', ['cid' => $cid, 'preview' => substr($raw, 0, 300)]);
                throw new \RuntimeException('Respuesta remota inválida, no es JSON.');
            }

            $records = $this->extractParkRecords($payload);
            if (!$records) {
                /*Logger::info('park.sync.no_records', ['cid' => $cid, 'endpoint' => $endpoint]);
                Http::json([
                    'ok'       => true,
                    'endpoint' => $endpoint,
                    'fetched'  => 0,
                    'upserted' => 0,
                    'skipped'  => 0,
                    'message'  => 'La API remota no devolvió registros.',
                ]);*/
                return;
            }

            $pdo = DB::pdo($this->config);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $upserted = $this->persistTickets($pdo, $records); // normaliza adentro

            /*Logger::info('remote.park.sync_success', [
                'cid'        => $cid,
                'endpoint'   => $endpoint,
                'fetched'    => count($records),
                'upserted'   => $upserted,
                'skipped'    => 0,
                'total_ms'   => $this->msSince($t0),
            ]);*/

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
    private function persistTickets(PDO $pdo, array $rows): int
    {
        if (!$rows) return 0;

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

        // --- PREPARED: existencia ---
        $existsSql = 'SELECT 1 FROM tickets WHERE ticket_no = :ticket_no LIMIT 1';
        $existsStmt = $pdo->prepare($existsSql);

        // --- PREPARED: insert puro ---
        $insertColumns = ['ticket_no','plate','status','entry_at','exit_at','duration_min','amount'];
        $placeholders  = [':ticket_no',':plate',':status',':entry_at',':exit_at',':duration_min',':amount'];

        if ($hasSource)    { $insertColumns[] = 'source';     $placeholders[] = ':source'; }
        if ($hasRawJson)   { $insertColumns[] = 'raw_json';   $placeholders[] = ':raw_json'; }
        if ($hasUpdatedAt) { $insertColumns[] = 'updated_at'; $placeholders[] = ':updated_at'; }

        $insertSql = sprintf(
            'INSERT INTO tickets (%s) VALUES (%s)',
            implode(',', $insertColumns),
            implode(',', $placeholders)
        );
        $insertStmt = $pdo->prepare($insertSql);

        $inserted = 0;
        $idx = 0;

        foreach ($rows as $rowRaw) {
            $idx++;
            $row = is_array($rowRaw) ? $this->normalizeParkRecordRow($rowRaw) : null;
            if ($row === null) {
                Logger::warning('tickets.normalize.skip', ['i' => $idx, 'rowRaw_preview' => substr(json_encode($rowRaw),0,300)]);
                continue;
            }

            $cid = $this->newCorrelationId('tkt');
            $t0  = microtime(true);

            $ticketNo = $row['ticket_no'] ?? null;
            $plate    = $row['plate'] ?? null;

            /*Logger::info('tickets.check.begin', [
                'cid' => $cid, 'i' => $idx, 'ticket_no' => $ticketNo, 'plate' => $plate
            ]);*/

            // 1) ¿Existe ya?
            $existsStmt->execute([':ticket_no' => $ticketNo]);
            $exists = (bool) $existsStmt->fetchColumn();

            if ($exists) {
                // NO insertar / NO actualizar / NO billing: solo saltar
                /*Logger::info('tickets.skip.exists', [
                    'cid' => $cid,
                    'i'   => $idx,
                    'ticket_no' => $ticketNo,
                    'elapsed_ms' => $this->msSince($t0),
                ]);*/
                continue;
            }

            // 2) Insert puro
            $params = [
                ':ticket_no'    => $ticketNo,
                ':plate'        => $plate,
                ':status'       => $row['status'] ?? 'CLOSED',
                ':entry_at'     => $row['entry_at'] ?? null,
                ':exit_at'      => $row['exit_at'] ?? null,
                ':duration_min' => $row['duration_min'] ?? null,
                ':amount'       => $row['amount'] ?? null,
            ];
            if ($hasSource)    { $params[':source']    = $row['source'] ?? null; }
            if ($hasRawJson)   { $params[':raw_json']  = $row['raw_json'] ?? null; }
            if ($hasUpdatedAt) {
                $params[':updated_at'] = (new \DateTime('now'))->format('Y-m-d H:i:s');
            }

           /* Logger::info('tickets.insert.begin', [
                'cid' => $cid, 'i' => $idx, 'ticket_no' => $ticketNo, 'plate' => $plate
            ]);*/

            $insertStmt->execute($params);
            $inserted++;

          /*  Logger::info('tickets.insert.ok', [
                'cid' => $cid,
                'i'   => $idx,
                'ticket_no' => $ticketNo,
                'elapsed_ms' => $this->msSince($t0),
            ]);*/

            // 3) BILLING: solo para nuevos inserts
            $b0 = microtime(true);
            try {
                $billingEnabled = strtolower((string)$this->config->get('BILLING_ENABLED','true')) === 'true';
                if (!$billingEnabled) {
                    //Logger::info('billing.skip.disabled', ['cid' => $cid, 'ticket_no' => $ticketNo]);
                } else {
                    //Logger::info('billing.ensure.begin', ['cid' => $cid, 'ticket_no' => $ticketNo, 'plate' => $plate]);
                    $this->ensurePaymentStub($pdo, $row);
                    //Logger::info('billing.ensure.done', ['cid' => $cid, 'ticket_no' => $ticketNo, 'elapsed_ms' => $this->msSince($b0)]);
                }
            } catch (\Throwable $e) {
                Logger::error('billing.ensure.failed', [
                    'cid' => $cid,
                    'ticket_no' => $ticketNo,
                    'error' => $e->getMessage(),
                    'elapsed_ms' => $this->msSince($b0),
                ]);
            }

            /*Logger::info('tickets.process.end', [
                'cid' => $cid,
                'i'   => $idx,
                'ticket_no' => $ticketNo,
                'total_ms'  => $this->msSince($t0),
            ]);*/
        }

        return $inserted; // cantidad realmente insertada
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

            // Asegurar columnas requeridas (reusa las que ya tienes)
            $this->ensurePaymentsTableHasBillin($pdo);
            $this->ensurePaymentsTableHasBillinJson($pdo); // usaremos esta col como "ref" para recordId
            $this->ensurePaymentsTableHasPlate($pdo);

            // carNumber == plate
            $resp      = $this->fetchVehicleBilling($plate, $cid);
            $billValue = $resp['bill_value'] ?? 0;
            if (!is_numeric($billValue)) $billValue = 0;
            $billValue = (float)$billValue;

            // Si no es success → recordIdRef = "0"
            $recordIdRef = ($resp['ok'] && !empty($resp['record_id'])) ? (string)$resp['record_id'] : '0';

            if (!$exists) {
                $sql = "INSERT INTO payments (ticket_no, amount, method, paid_at, created_at, billin, plate, billin_json)
                        VALUES (:ticket_no, :amount, :method, :paid_at, {$nowExpr}, :billin, :plate, :billin_json)";
                $ins = $pdo->prepare($sql);
                $ins->execute([
                    ':ticket_no'   => $ticketNo,
                    ':amount'      => 0.00,
                    ':method'      => 'pending',
                    ':paid_at'     => $paidAt,
                    ':billin'      => $billValue,
                    ':plate'       => $plate,
                    ':billin_json' => $recordIdRef, // ← SOLO recordId o "0"
                ]);

                /*Logger::info('billing.insert.ok', [
                    'cid' => $cid, 'ticket_no' => $ticketNo, 'billin' => $billValue,
                    'plate' => $plate, 'billin_ref' => $recordIdRef, 'row_count' => $ins->rowCount()
                ]);*/
            } else {
                $limitClause = ($driver === 'mysql') ? ' LIMIT 1' : '';

                // billin
                $sqlBill = "UPDATE payments SET billin = :billin WHERE ticket_no = :t{$limitClause}";
                $upBill  = $pdo->prepare($sqlBill);
                $upBill->execute([':billin' => $billValue, ':t' => $ticketNo]);

                // method/paid_at/plate
                $sqlPlate = "UPDATE payments
                            SET method = COALESCE(method, 'pending'),
                                paid_at = COALESCE(paid_at, :paid_at),
                                plate  = CASE WHEN plate IS NULL OR plate = '' THEN :plate ELSE plate END
                            WHERE ticket_no = :t{$limitClause}";
                $stPlate = $pdo->prepare($sqlPlate);
                $stPlate->execute([
                    ':paid_at' => $paidAt,
                    ':plate'   => $plate,
                    ':t'       => $ticketNo,
                ]);

                // billin_json → guarda SOLO recordId o "0"
                $sqlJson = "UPDATE payments SET billin_json = :j WHERE ticket_no = :t{$limitClause}";
                $stJson  = $pdo->prepare($sqlJson);
                $stJson->execute([
                    ':j' => $recordIdRef,
                    ':t' => $ticketNo,
                ]);

                /*Logger::info('billing.update.ok', [
                    'cid' => $cid, 'ticket_no' => $ticketNo, 'billin' => $billValue,
                    'plate' => $plate, 'billin_ref' => $recordIdRef,
                    'row_count_bill' => $upBill->rowCount(), 'row_count_plate' => $stPlate->rowCount(), 'row_count_json' => $stJson->rowCount()
                ]);*/
            }


            //Logger::info('billing.stub.done', ['cid' => $cid, 'ticket_no' => $ticketNo, 'ms' => $this->msSince($t0)]);
        } catch (\PDOException $e) {
            Logger::error('billing.stub.sql_failed', [
                'cid' => $cid, 'ticket_no' => $t['ticket_no'] ?? null, 'sqlstate' => $e->getCode(), 'error' => $e->getMessage()
            ]);
        } catch (\Throwable $e) {
            Logger::error('billing.stub.failed', [
                'cid' => $cid, 'ticket_no' => $t['ticket_no'] ?? null, 'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Asegura que la tabla payments tenga la columna 'plate'.
     * - MySQL: VARCHAR(32) NULL
     * - SQLite: TEXT NULL
     */
    private function ensurePaymentsTableHasPlate(PDO $pdo): void {
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info('payments')");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $has  = false;
            foreach ($cols as $c) {
                if (isset($c['name']) && strtolower($c['name']) === 'plate') { $has = true; break; }
            }
            if (!$has) {
                $pdo->exec("ALTER TABLE payments ADD COLUMN plate TEXT NULL");
            }
            return;
        }

        // MySQL / MariaDB
        $sql = "SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payments'
                AND COLUMN_NAME = 'plate'
                LIMIT 1";
        $rs  = $pdo->query($sql);
        $has = $rs && (bool)$rs->fetchColumn();
        if (!$has) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN plate VARCHAR(32) NULL");
        }
    }

    ////////////////////////////////////////////////////////
    // Asegura columna `billin` en la tabla payments (logs)
    ////////////////////////////////////////////////////////
    private function ensurePaymentsTableHasBillin(PDO $pdo): void {
        $cid = $this->newCorrelationId('schema');
        try {
            //Logger::debug('payments.ensure_billin.check', ['cid' => $cid]);
            $columns = $this->getTableColumns($pdo, 'payments');

            if (!isset($columns['billin'])) {
                try {
                    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                } catch (\Throwable $e) {
                    $driver = 'mysql';
                }

                if ($driver === 'sqlite') {
                    $sql = "ALTER TABLE payments ADD COLUMN billin TEXT";
                    //Logger::info('payments.ensure_billin.alter', ['cid' => $cid, 'driver' => $driver, 'sql' => $sql]);
                    $pdo->exec($sql);
                } else {
                    $sql = "ALTER TABLE payments ADD COLUMN billin LONGTEXT NULL";
                   // Logger::info('payments.ensure_billin.alter', ['cid' => $cid, 'driver' => $driver, 'sql' => $sql]);
                    $pdo->exec($sql);
                }
                //Logger::info('payments.ensure_billin.created', ['cid' => $cid, 'driver' => $driver]);
            } else {
                Logger::debug('payments.ensure_billin.exists', ['cid' => $cid]);
            }
        } catch (\Throwable $e) {
            Logger::error('payments.ensure_billin.failed', ['cid' => $cid, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /////////////////////////////////////////////////////////////////
    // Asegura columna `billin_json` para guardar JSON completo
    /////////////////////////////////////////////////////////////////
    private function ensurePaymentsTableHasBillinJson(PDO $pdo): void {
        $columns = $this->getTableColumns($pdo, 'payments');
        if (!isset($columns['billin_json'])) {
            try {
                $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable $e) {
                $driver = 'mysql';
            }
            if ($driver === 'sqlite') {
                $pdo->exec("ALTER TABLE payments ADD COLUMN billin_json TEXT NULL");
            } else {
                $pdo->exec("ALTER TABLE payments ADD COLUMN billin_json LONGTEXT NULL");
            }
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
            return ['ok' => false, 'status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0, 'record_id' => null];
        }

        $baseUrl = rtrim((string)$this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            Logger::error('billing.http.no_base_url', ['cid' => $cid]);
            return ['ok' => false, 'status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0, 'record_id' => null];
        }

        $endpoint = $baseUrl . '/api/v1/parkCost/vehicleBilling';
        $accessToken = (string)($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
        if ($accessToken !== '') {
            $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . 'access_token=' . urlencode($accessToken);
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        $verifySsl = strtolower((string)$this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';

        // carNumber == plate
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
            return ['ok' => false, 'status' => 0, 'body_raw' => '', 'json' => null, 'bill_value' => 0, 'record_id' => null];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            //Logger::warning('billing.http.non_json', ['cid' => $cid, 'status' => $status, 'body_preview' => substr($raw,0,300)]);
            return ['ok' => false, 'status' => $status, 'body_raw' => $raw, 'json' => null, 'bill_value' => 0, 'record_id' => null];
        }

        // success si code == 0 y hay data
        $ok = (isset($json['code']) && (int)$json['code'] === 0 && isset($json['data']) && is_array($json['data']));

        // monto
        $billValue = 0.0;
        if ($ok) {
            $data = $json['data'];
            foreach (['finalAmount','amount','total','totalCost','bill','cost'] as $k) {
                if (isset($data[$k]) && is_numeric((string)$data[$k])) {
                    $billValue = (float)$data[$k];
                    break;
                }
            }
            if ($billValue === 0.0) {
                $nestedPaths = [
                    ['charge','finalAmount'],
                    ['charge','amount'],
                    ['summary','total'],
                ];
                foreach ($nestedPaths as $path) {
                    $tmp = $data;
                    foreach ($path as $seg) {
                        if (is_array($tmp) && array_key_exists($seg, $tmp)) {
                            $tmp = $tmp[$seg];
                        } else { $tmp = null; break; }
                    }
                    if ($tmp !== null && is_numeric((string)$tmp)) {
                        $billValue = (float)$tmp;
                        break;
                    }
                }
            }
        }

        // recordId (solo si ok)
        $recordId = null;
        if ($ok && isset($json['data']['recordId']) && is_string($json['data']['recordId'])) {
            $recordId = $json['data']['recordId'];
        }

        try {
            $keys = isset($json['data']) && is_array($json['data']) ? implode(',', array_keys($json['data'])) : '';
            //Logger::debug('billing.http.data_keys', ['cid' => $cid, 'keys' => $keys]);
        } catch (\Throwable $e) { /* ignore */ }

       /* Logger::info('billing.http.ok', [
            'cid' => $cid,
            'status' => $status,
            'ok' => $ok,
            'bill_value' => $billValue,
            'record_id' => $recordId,
            'endpoint' => $endpoint,
            'body_preview' => substr($raw, 0, 400)
        ]);*/

        return [
            'ok'         => $ok,
            'status'     => $status,
            'body_raw'   => $raw,
            'json'       => $json,
            'bill_value' => $billValue,
            'record_id'  => $recordId,
        ];
    }

    //////////////////////////////////////////////////////
    // Actualiza `payments.billin` (con logs y row count)
    //////////////////////////////////////////////////////
    private function upsertPaymentBillin(PDO $pdo, string $ticketNo, $billinValue, ?string $cid = null): void {
        $cid = $cid ?: $this->newCorrelationId('billin');
        $t0  = microtime(true);

        $this->ensurePaymentsTableHasBillin($pdo);

        if ($billinValue === null || $billinValue === '' || !is_numeric($billinValue)) {
            $billinValue = 0;
        }

        // Detecta driver para LIMIT
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }
        $limitClause = ($driver === 'mysql') ? ' LIMIT 1' : '';

        $sql = "UPDATE payments SET billin = :billin WHERE ticket_no = :t{$limitClause}";
        $st  = $pdo->prepare($sql);
        $st->execute([':billin' => $billinValue, ':t' => $ticketNo]);

        /*Logger::info('payments.billin.update.ok', [
            'cid' => $cid,
            'ticket_no' => $ticketNo,
            'billin' => $billinValue,
            'affected' => $st->rowCount(),
            'duration_ms' => $this->msSince($t0),
        ]);*/
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
            $receptorNit = strtoupper(trim((string)($body['receptor_nit'] ?? 'CF'))); // CF permitido
            $mode        = (string)($body['mode'] ?? 'hourly'); // hourly | custom | grace
            $customTotal = isset($body['custom_total']) ? (float)$body['custom_total'] : null;

            $this->debugLog('fel_invoice_in.txt', ['body' => $body, 'server' => $_SERVER]);

            if ($ticketNo === '') {
                echo json_encode(['ok' => false, 'error' => 'ticket_no requerido']); return;
            }
            if ($mode === 'custom' && (!is_finite($customTotal) || $customTotal <= 0)) {
                echo json_encode(['ok' => false, 'error' => 'custom_total inválido']); return;
            }
            if ($receptorNit !== 'CF' && !ctype_digit($receptorNit)) {
                echo json_encode(['ok' => false, 'error' => 'NIT inválido (use CF o solo dígitos)']); return;
            }

            $isGrace = ($mode === 'grace');

            // 0) Fuerza TZ en PHP
            @date_default_timezone_set('America/Guatemala');
            $phpTz   = @date_default_timezone_get();
            $phpNow  = date('Y-m-d H:i:s'); // ahora según PHP/GT
            $nowGT   = (new \DateTime('now', new \DateTimeZone('America/Guatemala')))->format('Y-m-d H:i:s');

            // 1) Calcular montos: en gracia obtenemos tiempos, pero total forzado 0.00
            $calc = $this->resolveTicketAmount($ticketNo, $isGrace ? 'hourly' : $mode, $customTotal);
            $hours   = (float)($calc[0] ?? 0);
            $minutes = (int)  ($calc[1] ?? 0);
            $total   = $isGrace ? 0.00 : (float)($calc[2] ?? 0);
            $extra   = is_array($calc[3] ?? null) ? $calc[3] : [];

            $durationMin  = (int) max(0, $hours * 60 + $minutes);
            $hoursBilled  = (int) ceil($durationMin / 60);
            $billingAmount= $isGrace ? 0.00 : (isset($extra['billing_amount']) ? (float)$extra['billing_amount'] : $total);

            // 2) Config/DB
            $cfg = new \Config\Config(__DIR__ . '/../../.env');
            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 2.1) Fuerza TZ en MySQL/MariaDB (sesión). Si es SQLite, se omite.
            $mysqlTzDiag = null;
            try {
                $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
                if ($drv === 'mysql') {
                    // Forzamos offset fijo (no depende de tablas tz)
                    $pdo->exec("SET time_zone = '-06:00'");
                    // Diagnóstico
                    $stmt = $pdo->query("
                        SELECT
                            @@global.time_zone   AS global_tz,
                            @@session.time_zone  AS session_tz,
                            NOW()                AS now_local,
                            UTC_TIMESTAMP()      AS now_utc,
                            TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset
                    ");
                    $mysqlTzDiag = $stmt->fetch(\PDO::FETCH_ASSOC);
                } else {
                    $mysqlTzDiag = ['note' => 'non-mysql driver', 'driver' => $drv, 'php_now' => $phpNow];
                }
            } catch (\Throwable $e) {
                $mysqlTzDiag = ['error' => $e->getMessage(), 'php_now' => $phpNow];
            }
            $this->debugLog('tz_diag_invoice_one.txt', ['php_tz'=>$phpTz, 'php_now'=>$phpNow, 'mysql'=>$mysqlTzDiag]);

            // Leer info del ticket
            $entryAt = $exitAt = $plate = null;
            try {
                $q = $pdo->prepare("SELECT entry_at, exit_at, plate FROM tickets WHERE ticket_no = :t LIMIT 1");
                $q->execute([':t' => $ticketNo]);
                if ($row = $q->fetch()) {
                    $entryAt = $row['entry_at'] ?? null;
                    $exitAt  = $row['exit_at']  ?? null;
                    $plate   = $row['plate']    ?? null;
                }
            } catch (\Throwable $e) { /* columnas faltantes: ignorar */ }

            // Rates nunca NULL (por constraints)
            $hourlyRate   = is_numeric($extra['hourly_rate']  ?? null) ? (float)$extra['hourly_rate']  : 0.00;
            $monthlyRate  = is_numeric($extra['monthly_rate'] ?? null) ? (float)$extra['monthly_rate'] : 0.00;

            // Para payNotify
            $payBillin   = 0.0;
            $payRecordId = '0';
            $payPlate    = $plate;
            try {
                $qp = $pdo->prepare("SELECT billin, billin_json, plate FROM payments WHERE ticket_no = :t LIMIT 1");
                $qp->execute([':t' => $ticketNo]);
                if ($pr = $qp->fetch()) {
                    if (isset($pr['billin']) && is_numeric($pr['billin'])) $payBillin = (float)$pr['billin'];
                    if (!empty($pr['billin_json'])) $payRecordId = (string)$pr['billin_json']; // recordId o "0"
                    if (!empty($pr['plate'])) $payPlate = $pr['plate'];
                }
            } catch (\Throwable $e) {}

            // 3) G4S solo si NO es gracia
            $felOk = false;
            $uuid  = null;
            $felRes = null;
            $felErr = null;
            $pdfBase64 = null;

            if (!$isGrace) {
                $client = new \App\Services\G4SClient($cfg);
                $payloadFel = [
                    'ticket_no'    => $ticketNo,
                    'receptor_nit' => $receptorNit,
                    'total'        => $total,
                    'hours'        => $hours,
                    'minutes'      => $minutes,
                    'mode'         => $mode,
                ];

                $felRes = $client->submitInvoice($payloadFel);
                $this->debugLog('fel_invoice_out.txt', [
                    'request_payload' => $payloadFel,
                    'g4s_response'    => $felRes,
                ]);

                $felOk  = (bool)($felRes['ok'] ?? false);
                $uuid   = $felRes['uuid']  ?? null;
                $felErr = $felRes['error'] ?? null;

                if ($felOk && $uuid) {
                    if (!empty($felRes['pdf_base64'])) {
                        $pdfBase64 = (string)$felRes['pdf_base64'];
                    } else {
                        $pdfBase64 = $this->pdfFromUuidViaG4S($uuid);
                        if (!$pdfBase64 && isset($felRes['raw'])) {
                            $xmlDte = @file_get_contents(__DIR__ . '/../../storage/last_dte.xml') ?: '';
                            if ($xmlDte !== '') $pdfBase64 = $this->pdfFromXmlViaG4S($xmlDte);
                        }
                    }
                }
            }

            // 4) Persistir en BD + cerrar ticket
            $pdo->beginTransaction();

            try { $this->ensureInvoicePdfColumn($pdo); }
            catch (\Throwable $e) { $this->debugLog('ensure_invoice_pdf_col_err.txt', ['error'=>$e->getMessage()]); }

            // >>> Cambiamos NOW() por :created_at (valor calculado en PHP con TZ GT)
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
                    :request_json, :response_json, :created_at,
                    :receptor_nit, :entry_at, :exit_at,
                    :duration_min, :hours_billed, :billing_mode,
                    :hourly_rate, :monthly_rate
                )
            ");

            $status = $isGrace ? 'GRATIS' : ($felOk ? 'CERTIFIED' : 'FAILED');

            $stmt->execute([
                ':ticket_no'     => $ticketNo,
                ':total'         => $total, // en gracia = 0.00
                ':uuid'          => $uuid,  // en gracia NULL
                ':status'        => $status,
                ':request_json'  => json_encode(
                    $isGrace
                        ? ['mode'=>'grace','ticket_no'=>$ticketNo,'receptor_nit'=>$receptorNit,'total'=>0]
                        : ['mode'=>$mode,'ticket_no'=>$ticketNo,'receptor_nit'=>$receptorNit,'total'=>$total],
                    JSON_UNESCAPED_UNICODE
                ),
                ':response_json' => json_encode($isGrace ? ['ok'=>true,'note'=>'no FEL (grace)'] : $felRes, JSON_UNESCAPED_UNICODE),
                ':created_at'    => $nowGT,
                ':receptor_nit'  => $receptorNit,
                ':entry_at'      => $entryAt,
                ':exit_at'       => $exitAt,
                ':duration_min'  => $durationMin,
                ':hours_billed'  => $hoursBilled,
                ':billing_mode'  => $isGrace ? 'grace' : $mode,
                ':hourly_rate'   => $hourlyRate,
                ':monthly_rate'  => $monthlyRate,
            ]);

            if (!$isGrace && $pdfBase64) {
                try {
                    $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
                    if ($drv === 'mysql') {
                        $pdo->exec("UPDATE invoices SET pdf_base64 = " . $pdo->quote($pdfBase64) . " WHERE ticket_no = " . $pdo->quote($ticketNo) . " LIMIT 1");
                    } else {
                        $pdo->exec("UPDATE invoices SET pdf_base64 = " . $pdo->quote($pdfBase64) . " WHERE ticket_no = " . $pdo->quote($ticketNo));
                    }
                } catch (\Throwable $e) {
                    $this->debugLog('save_pdf_base64_err.txt', ['ticket'=>$ticketNo, 'error'=>$e->getMessage()]);
                }
            }

            // Sellar salida si no existía — reemplazamos NOW() por :now_exit
            $up = $pdo->prepare("UPDATE tickets SET status = 'CLOSED', exit_at = COALESCE(exit_at, :now_exit) WHERE ticket_no = :t");
            $up->execute([':now_exit' => $nowGT, ':t' => $ticketNo]);

            $pdo->commit();

            // 5) payNotify (igual que tenías)
            $manualOpen     = false;
            $payNotifySent  = false;
            $payNotifyAck   = false;
            $payNotifyError = null;
            $payNotifyRaw   = null;
            $payNotifyType  = null;

            $effectiveBilling = ($payBillin > 0) ? $payBillin : (float)$billingAmount;
            $shouldNotify     = $isGrace ? true : ($felOk && $effectiveBilling > 0);

            if ($shouldNotify) {
                $cid = $this->newCorrelationId('paynotify');

                $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
                if ($baseUrl === '') {
                    //Logger::error('paynotify.no_base_url', ['cid' => $cid]);
                    $payNotifyError = 'HAMACHI_PARK_BASE_URL no está configurado.';
                    $manualOpen = true;
                } else {
                    $carNumber = $payPlate ?: ($plate ?: ($extra['plate'] ?? ''));
                    $recordId  = $payRecordId;

                    if ($recordId === '0' || $recordId === '' || $carNumber === '') {
                        //Logger::error('paynotify.missing_data', ['cid'=>$cid, 'carNumber'=>$carNumber, 'recordId'=>$recordId]);
                        $payNotifyError = 'Faltan carNumber/recordId para payNotify';
                        $manualOpen = true;
                    } else {
                        $endpoint = $baseUrl . '/api/v1/parkCost/payNotify';
                        $accessToken = (string)($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
                        if ($accessToken !== '') {
                            $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . 'access_token=' . urlencode($accessToken);
                        }

                        $headers = ['Accept: application/json', 'Content-Type: application/json'];
                        $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
                        if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader;

                        $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
                        $connectTo = trim((string) $this->config->get('HAMACHI_PARK_CONNECT_TO', ''));

                        $paymentType = $isGrace ? 'free' : (string)$this->config->get('HAMACHI_PARK_PAYMENT_TYPE', 'cash');

                        $notifyPayload = [
                            'carNumber'   => $carNumber,
                            'paymentType' => $paymentType,
                            'recordId'    => $recordId,
                        ];

                        /*Logger::debug('paynotify.req', [
                            'cid'        => $cid,
                            'endpoint'   => $endpoint,
                            'headers'    => $headers,
                            'verify_ssl' => $verifySsl,
                            'connect_to' => $connectTo ?: null,
                            'payload'    => $notifyPayload,
                        ]);*/

                        try {
                            $ch = curl_init($endpoint);
                            curl_setopt_array($ch, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_TIMEOUT        => 20,
                                CURLOPT_HTTPHEADER     => $headers,
                                CURLOPT_NOSIGNAL       => true,
                                CURLOPT_TCP_KEEPALIVE  => 1,
                                CURLOPT_TCP_KEEPIDLE   => 30,
                                CURLOPT_TCP_KEEPINTVL  => 10,
                                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => json_encode($notifyPayload, JSON_UNESCAPED_UNICODE),
                                CURLOPT_HEADER         => true,
                            ]);
                            if (!$verifySsl) {
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            }
                            if ($connectTo !== '') {
                                curl_setopt($ch, CURLOPT_CONNECT_TO, [$connectTo]);
                                //Logger::debug('http.connect_to', ['cid' => $cid, 'connect_to' => $connectTo]);
                            }

                            $resp = curl_exec($ch);
                            if ($resp === false) {
                                $err  = curl_error($ch) ?: 'Error desconocido';
                                $info = curl_getinfo($ch) ?: [];
                                curl_close($ch);

                                //Logger::error('paynotify.http_failed', ['cid'=>$cid, 'endpoint'=>$endpoint, 'error'=>$err, 'curl_info'=>$info]);
                                $payNotifyError = $err;
                                $manualOpen = true;
                            } else {
                                $info   = curl_getinfo($ch) ?: [];
                                $status = (int)($info['http_code'] ?? 0);
                                $hsize  = (int)($info['header_size'] ?? 0);
                                curl_close($ch);

                                $body  = substr($resp, $hsize) ?: '';
                                $ctype = (string)($info['content_type'] ?? '');
                                $payNotifyRaw  = $body;
                                $payNotifyType = $ctype;

                                $payNotifySent = ($status >= 200 && $status < 300);

                                $json = null;
                                $looksJson = stripos($ctype, 'application/json') !== false
                                        || (strlen($body) && ($body[0] === '{' || $body[0] === '['));
                                if ($looksJson) {
                                    $tmp = json_decode($body, true);
                                    if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
                                }

                                if ($payNotifySent && is_array($json)) {
                                    $payNotifyAck = (isset($json['code']) && (int)$json['code'] === 0);
                                    if (!$payNotifyAck) {
                                        $payNotifyError = isset($json['message']) ? (string)$json['message'] : 'ACK inválido';
                                    }
                                } else if (!$payNotifySent) {
                                    $payNotifyError = "HTTP $status";
                                }

                                if (!$payNotifySent || !$payNotifyAck) {
                                    $manualOpen = true;
                                    /*Logger::error('paynotify.nack', [
                                        'cid'=>$cid, 'status'=>$status, 'ack'=>$payNotifyAck,
                                        'body_preview'=>mb_substr($body, 0, 500),
                                        'error'=>$payNotifyError
                                    ]);*/
                                } else {
                                    Logger::info('paynotify.ok', ['cid'=>$cid, 'status'=>$status]);
                                }
                            }

                            /*Logger::debug('paynotify.resp', [
                                'cid'   => $cid,
                                'sent'  => $payNotifySent,
                                'ack'   => $payNotifyAck,
                                'ctype' => $payNotifyType,
                                'raw'   => $payNotifyRaw ? mb_substr($payNotifyRaw, 0, 4000) : null,
                                'error' => $payNotifyError,
                            ]);*/

                        } catch (\Throwable $e) {
                            $payNotifyError = $e->getMessage();
                            $manualOpen = true;
                            Logger::error('paynotify.exception', ['cid'=>$cid, 'error'=>$payNotifyError]);
                        }
                    }
                }
            } else {
                // Gracia pero sin datos suficientes para notificar -> abrir manual
                $manualOpen = true;
            }

            echo json_encode([
                'ok'               => $isGrace ? true : $felOk,
                'uuid'             => $uuid,
                'message'          => $isGrace ? 'Ticket de gracia registrado (sin FEL)' : ($felOk ? 'Factura certificada' : 'No se pudo certificar (registrada en BD)'),
                'error'            => $isGrace ? null : $felErr,
                'billing_amount'   => $isGrace ? 0.00 : ($payBillin > 0 ? $payBillin : (float)$billingAmount),
                'manual_open'      => $manualOpen,
                'pay_notify_sent'  => $payNotifySent,
                'pay_notify_ack'   => $payNotifyAck,
                'pay_notify_error' => $payNotifyError,
                'has_pdf_base64'   => (bool)$pdfBase64,

                // >>> Resultado de la apertura en modo gracia <<<
                'grace_gate_open_ok'  => $isGrace ? $graceGateOpenOk   : null,
                'grace_gate_open_msg' => $isGrace ? $graceGateOpenMsg  : null,
                'grace_gate_channel'  => $isGrace ? $graceGateChannel  : null,

                // >>> Diagnóstico de zona horaria <<<
                'tz' => [
                    'php_timezone' => $phpTz,
                    'php_now'      => $phpNow,
                    'now_gt'       => $nowGT,
                    'mysql'        => $mysqlTzDiag,
                ],
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->debugLog('fel_invoice_exc.txt', ['exception' => $e->getMessage()]);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Garantiza que exista la columna invoices.pdf_base64
     * - MySQL: LONGTEXT NULL
     * - SQLite: TEXT NULL
     */
    private function ensureInvoicePdfColumn(\PDO $pdo): void
    {
        $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));

        if ($drv === 'mysql') {
            // ¿Existe la columna?
            $chk = $pdo->query("SHOW COLUMNS FROM `invoices` LIKE 'pdf_base64'");
            $exists = $chk && $chk->fetch();

            if (!$exists) {
                $pdo->exec("ALTER TABLE `invoices` ADD COLUMN `pdf_base64` LONGTEXT NULL");
            }
            return;
        }

        if ($drv === 'sqlite' || $drv === 'sqlite2') {
            $exists = false;
            $res = $pdo->query("PRAGMA table_info(invoices)");
            if ($res) {
                while ($r = $res->fetch(\PDO::FETCH_ASSOC)) {
                    if (isset($r['name']) && strtolower($r['name']) === 'pdf_base64') {
                        $exists = true; break;
                    }
                }
            }
            if (!$exists) {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN pdf_base64 TEXT NULL");
            }
            return;
        }

        // Otros drivers: intentar un UPDATE de prueba y si falla, intentar ALTER
        try {
            $pdo->exec("UPDATE invoices SET pdf_base64 = pdf_base64 WHERE 1=0");
        } catch (\Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN pdf_base64 TEXT NULL");
            } catch (\Throwable $e2) {
                // log y seguir (no romper flujo de facturación por esto)
                $this->debugLog('ensure_col_generic_err.txt', ['driver'=>$drv, 'error'=>$e2->getMessage()]);
            }
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
                /* 👇 NUEVO: placa desde payments o tickets */
                COALESCE(MAX(p.plate), t.plate) AS plate,
                NULL AS uuid,
                NULL AS estado
            FROM tickets t
            LEFT JOIN payments p ON p.ticket_no = t.ticket_no
            WHERE t.status = 'OPEN'  -- OJO: si tu UI es para CLOSED, cámbialo a 'CLOSED'
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

            // === Filtros base ===
            $status = strtoupper(trim((string)($_GET['status'] ?? 'OPEN'))); // por defecto solo 'OPEN'
            $from   = $_GET['from'] ?? null; // formato: 'YYYY-MM-DD 00:00:00'
            $to     = $_GET['to']   ?? null; // formato: 'YYYY-MM-DD 23:59:59'

            $params = [];
            $conds  = [];

            // Solo tickets abiertos (requerido)
            $conds[] = "t.status = 'OPEN'";

            // Filtro por fecha
            if (!empty($from)) {
                $conds[] = "COALESCE(t.exit_at, t.entry_at) >= :from";
                $params[':from'] = $from;
            }
            if (!empty($to)) {
                $conds[] = "COALESCE(t.exit_at, t.entry_at) <= :to";
                $params[':to'] = $to;
            }

            // === SQL principal ===
            $sql = "
                SELECT
                    t.ticket_no,
                    t.plate,
                    t.receptor_nit,
                    t.status,
                    t.entry_at,
                    t.exit_at,
                    t.duration_min,
                    COALESCE(SUM(p.amount), t.amount, 0) AS payments_total,
                    COUNT(p.id) AS payments_count,
                    MIN(p.paid_at) AS first_payment_at,
                    MAX(p.paid_at) AS last_payment_at,
                    COALESCE(t.exit_at, t.entry_at) AS fecha
                FROM tickets t
                LEFT JOIN payments p ON p.ticket_no = t.ticket_no
            ";

            if ($conds) {
                $sql .= "WHERE " . implode(' AND ', $conds) . "\n";
            }

            $sql .= "
                GROUP BY
                    t.ticket_no,
                    t.plate,
                    t.receptor_nit,
                    t.status,
                    t.entry_at,
                    t.exit_at,
                    t.duration_min
                ORDER BY fecha DESC
                LIMIT 1000
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // === Procesamiento adicional ===
            $hourlyRate = $this->getHourlyRate($pdo);
            foreach ($rows as &$row) {
                $row['payments_total'] = isset($row['payments_total']) ? (float)$row['payments_total'] : null;
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

            // === Filtros secundarios (placa, NIT, montos) ===
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
                        if ($rowNit !== '' && $rowNit !== 'CF') return false;
                    } elseif ($rowNit !== $nitFilter) {
                        return false;
                    }
                }
                $amount = (float)($row['billing_total'] ?? $row['total'] ?? 0);
                if ($minTotal !== null && $amount < $minTotal) return false;
                if ($maxTotal !== null && $amount > $maxTotal) return false;
                return true;
            }));

            // === Mapeo de pagos ===
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

            // === Estadísticas ===
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
                    $duration = (int)$duration;
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

            // === Respuesta final ===
            \App\Utils\Http::json([
                'ok' => true,
                'generated_at' => date('c'),
                'filters' => [
                    'from' => $from,
                    'to' => $to,
                    'status' => 'OPEN', // fijo
                    'plate' => $plate ?: null,
                    'nit' => $nitRaw ?: null,
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

    public function facturacionEmitidas(): void
    {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);

            // filtros opcionales ?from=YYYY-MM-DD&to=YYYY-MM-DD&uuid=...&nit=...&status=OK|ERROR|PENDING|ANY
            $from   = isset($_GET['from'])   ? trim((string)$_GET['from'])   : '';
            $to     = isset($_GET['to'])     ? trim((string)$_GET['to'])     : '';
            $uuid   = isset($_GET['uuid'])   ? trim((string)$_GET['uuid'])   : '';
            $nit    = isset($_GET['nit'])    ? trim((string)$_GET['nit'])    : '';
            $status = strtoupper(trim((string)($_GET['status'] ?? 'ANY')));

            if ($from !== '' && $to === '')   $to = $from;
            if ($to   !== '' && $from === '') $from = $to;

            $where  = [];
            $params = [];

            // Fecha por salida/entrada (ajusta a i.created_at si prefieres)
            if ($from !== '' && $to !== '') {
                $where[] = "DATE(COALESCE(t.exit_at, t.entry_at)) BETWEEN :from AND :to";
                $params[':from'] = $from;
                $params[':to']   = $to;
            } elseif ($from !== '') {
                $where[] = "DATE(COALESCE(t.exit_at, t.entry_at)) >= :from";
                $params[':from'] = $from;
            } elseif ($to !== '') {
                $where[] = "DATE(COALESCE(t.exit_at, t.entry_at)) <= :to";
                $params[':to'] = $to;
            }

            // Estado normalizado (CERTIFIED -> OK, etc.)
            if ($status !== '' && $status !== 'ANY') {
                $where[] = "CASE
                    WHEN UPPER(i.status) IN ('OK','CERTIFIED','CERT') THEN 'OK'
                    WHEN UPPER(i.status) IN ('PENDING','PENDIENTE')   THEN 'PENDING'
                    WHEN UPPER(i.status) IN ('ERROR','FAILED','FAIL') THEN 'ERROR'
                    ELSE UPPER(i.status)
                END = :st";
                $params[':st'] = $status;
            }

            // Filtro NIT (incluye CF/vacío)
            if ($nit !== '') {
                $where[] = "(
                    t.receptor_nit = :nit
                    OR (:nit = 'CF' AND (t.receptor_nit IS NULL OR t.receptor_nit = '' OR UPPER(t.receptor_nit)='CF'))
                )";
                $params[':nit'] = $nit;
            }

            if ($uuid !== '') {
                $where[] = "i.uuid = :uuid";
                $params[':uuid'] = $uuid;
            }

            $ws = $where ? ('WHERE '.implode(' AND ', $where)) : '';

            $sql = "
                SELECT
                    i.id,
                    i.ticket_no,
                    COALESCE(t.exit_at, t.entry_at, i.created_at) AS fecha,
                    i.total,
                    i.uuid,
                    -- status normalizado para el front
                    CASE
                        WHEN UPPER(i.status) IN ('OK','CERTIFIED','CERT') THEN 'OK'
                        WHEN UPPER(i.status) IN ('PENDING','PENDIENTE')   THEN 'PENDING'
                        WHEN UPPER(i.status) IN ('ERROR','FAILED','FAIL') THEN 'ERROR'
                        ELSE UPPER(i.status)
                    END AS status,
                    t.receptor_nit AS receptor,
                    i.response_json
                FROM invoices i
                JOIN tickets t ON t.ticket_no = i.ticket_no
                $ws
                ORDER BY fecha DESC, i.id DESC
                LIMIT 1000
            ";

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

            // Post-procesado: forzar tipos y fallback de UUID desde response_json si fuera necesario
            foreach ($rows as &$r) {
                $r['total'] = isset($r['total']) ? (float)$r['total'] : 0.0;

                if (empty($r['uuid']) && !empty($r['response_json'])) {
                    try {
                        $j = json_decode((string)$r['response_json'], true, 512, JSON_THROW_ON_ERROR);
                        $r['uuid'] = $j['uuid'] ?? ($j['data']['uuid'] ?? null);
                    } catch (\Throwable $e) { /* ignorar */ }
                }
            }
            unset($r);

            \App\Utils\Http::json([
                'ok' => true,
                'rows' => $rows,
                'filters' => ['from'=>$from,'to'=>$to,'uuid'=>$uuid,'nit'=>$nit,'status'=>$status]
            ]);
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
                'Transaction' => 'GET_DOCUMENT_SAT_PDF',
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

    public function openGateManual(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // === Input ===
            $raw       = file_get_contents('php://input') ?: '{}';
            $body      = json_decode($raw, true) ?: [];
            $reason    = trim((string)($body['reason'] ?? $_GET['reason'] ?? ''));
            $channelId = trim((string)($body['channel_id'] ?? $_GET['channel_id'] ?? ''));

            if ($reason === '') {
                echo json_encode(['ok' => false, 'title' => 'Apertura manual', 'message' => 'Debes indicar el motivo.','field'=>'reason']); return;
            }
            if ($channelId === '') {
                echo json_encode(['ok' => false, 'title' => 'Apertura manual', 'message' => 'channel_id requerido.','field'=>'channel_id']); return;
            }

            // === Config ===
            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') { echo json_encode(['ok' => false, 'error' => 'HAMACHI_PARK_BASE_URL no está configurado.']); return; }

            // Token en querystring (lo que te funcionó)
            $accessToken   = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            $tokenQueryKey = (string) ($this->config->get('HAMACHI_PARK_TOKEN_QUERY_KEY', 'access_token')); // 'access_token' o 'accessToken'

            $query = ['channelId' => $channelId];
            if ($accessToken !== '') $query[$tokenQueryKey] = $accessToken;

            $endpoint = $baseUrl . '/api/v1/parkBase/openGateChannel?' . http_build_query($query);

            // Headers mínimos
            $headers = ['Accept: application/json', 'Content-Type: application/json; charset=utf-8', 'Expect:'];
            $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
            if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader; // usa el host REAL solo si el equipo lo requiere

            // SSL / CONNECT_TO
            $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
            $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', ''));

            // === POST forzado con "{}" (requerido por el firmware) ===
            $ch = curl_init($endpoint);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 30,
                CURLOPT_TCP_KEEPINTVL  => 10,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_AUTOREFERER    => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '{}',
            ];
            if (!$verifySsl) { $opts[CURLOPT_SSL_VERIFYPEER]=false; $opts[CURLOPT_SSL_VERIFYHOST]=false; }
            if ($connectTo !== '') { $opts[CURLOPT_CONNECT_TO] = [$connectTo]; }
            curl_setopt_array($ch, $opts);

            $rawResp = curl_exec($ch);
            $err     = $rawResp === false ? (curl_error($ch) ?: 'Error desconocido') : '';
            $info    = curl_getinfo($ch) ?: [];
            curl_close($ch);

            if ($rawResp === false) {
                echo json_encode(['ok'=>false,'title'=>'Apertura manual','message'=>'HTTP(POST) falló: '.$err]); return;
            }

            $status  = (int)($info['http_code'] ?? 0);
            $payload = json_decode((string)$rawResp, true);

            $code    = $payload['code'] ?? ($payload['ret'] ?? ($payload['status'] ?? null));
            $message = $payload['message'] ?? ($payload['msg'] ?? ($payload['detail'] ?? null));
            $ok      = ($status === 200) && (
                $code === 0 || $code === '0' ||
                (is_string($message) && preg_match('/^success$/i', $message)) ||
                ($payload['success'] ?? false) === true
            );

            if (!$message) $message = $ok ? 'success' : 'Fallo en la apertura';

            // (Opcional) log en BD si lo usas:
            $tzGT = new \DateTimeZone('America/Guatemala');
            $now  = new \DateTime('now', $tzGT);
            $openedAt = $now->format('Y-m-d H:i:s');
            try {
                $pdo = DB::pdo($this->config);
                $this->ensureManualOpenTable($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO manual_open_logs (
                        channel_id, opened_at, reason, http_status, result_code, result_message, extra_json
                    ) VALUES (
                        :channel_id, :opened_at, :reason, :http_status, :result_code, :result_message, :extra_json
                    )
                ");
                $stmt->execute([
                    ':channel_id'     => $channelId,
                    ':opened_at'      => $openedAt,
                    ':reason'         => $reason,
                    ':http_status'    => $status,
                    ':result_code'    => $code,
                    ':result_message' => (string)$message,
                    ':extra_json'     => (string)$rawResp,
                ]);
            } catch (\Throwable $e) {
                $this->debugLog('manual_open_err.txt', ['when'=>$openedAt,'reason'=>$reason,'error'=>$e->getMessage()]);
            }

            echo json_encode([
                'ok'          => $ok,
                'title'       => 'Apertura manual',
                'message'     => $message,
                'http_status' => $status,
                'code'        => $code,
                'opened_at'   => $openedAt ?? null,
                'channel_id'  => $channelId,
                'debug'       => [
                    'endpoint' => $endpoint,
                    'bytes'    => strlen((string)$rawResp),
                    'preview'  => is_string($rawResp) ? substr($rawResp, 0, 200) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok'=>false,'title'=>'Apertura manual','message'=>$e->getMessage()]);
        }
    }

    private function ensureManualOpenTable(PDO $pdo): void
    {
        // Compatible con MySQL y SQLite
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            // Crear tabla si no existe
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS manual_open_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel_id TEXT NOT NULL,
                    opened_at TEXT NOT NULL,
                    reason TEXT NULL,
                    http_status INTEGER,
                    result_code TEXT,
                    result_message TEXT,
                    extra_json TEXT
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manual_open_logs_opened_at ON manual_open_logs(opened_at)");

            // Intentar agregar columna reason si la tabla ya existía sin ella
            try { $pdo->exec("ALTER TABLE manual_open_logs ADD COLUMN reason TEXT NULL"); } catch (\Throwable $e) { /* ya existe */ }

        } else {
            // MySQL / MariaDB
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS manual_open_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    channel_id VARCHAR(64) NOT NULL,
                    opened_at DATETIME NOT NULL,
                    reason VARCHAR(255) NULL,
                    http_status INT NULL,
                    result_code VARCHAR(16) NULL,
                    result_message VARCHAR(255) NULL,
                    extra_json MEDIUMTEXT NULL,
                    KEY idx_manual_open_logs_opened_at (opened_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Intentar agregar columna reason si la tabla ya existía sin ella
            try { $pdo->exec("ALTER TABLE manual_open_logs ADD COLUMN reason VARCHAR(255) NULL AFTER opened_at"); } catch (\Throwable $e) { /* ya existe */ }
        }
    }

public function manualInvoiceCreate(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw  = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];

        $reason       = trim((string)($body['reason'] ?? ''));                 // motivo OBLIGATORIO
        $receptorNit  = strtoupper(trim((string)($body['receptor_nit'] ?? 'CF'))); // CF permitido
        $receptorName = trim((string)($body['receptor_name'] ?? ''));          // opcional (lookup)
        $mode         = (string)($body['mode'] ?? 'custom');                   // custom | monthly | grace
        $amountIn     = isset($body['amount']) ? (float)$body['amount'] : null;

        $this->debugLog('fel_manual_invoice_in.txt', ['body' => $body, 'server' => $_SERVER]);

        if ($reason === '') {
            echo json_encode(['ok' => false, 'error' => 'reason requerido']); return;
        }
        if ($receptorNit !== 'CF' && !ctype_digit($receptorNit)) {
            echo json_encode(['ok' => false, 'error' => 'NIT inválido (use CF o solo dígitos)']); return;
        }

        $isGrace = ($mode === 'grace');

        // === Config / DB
        /** @var \Config\Config $cfg */
        $cfg = $this->config;
        $pdo = \App\Utils\DB::pdo($cfg);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Detectar driver (para SQL específico) y fijar TZ de sesión si es MySQL/MariaDB
        $driver   = (string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $isSqlite = ($driver === 'sqlite');
        if (!$isSqlite) {
            // Guatemala no tiene horario de verano; fijamos UTC-06:00 confiablemente
            try { $pdo->exec("SET time_zone = '-06:00'"); } catch (\Throwable $e) {
                // No detenemos el flujo si falla; de todos modos insertamos created_at desde app
                $this->debugLog('fel_manual_invoice_tz_warn.txt', ['error' => $e->getMessage()]);
            }
        }

        // === AUTOFIX estructura solo para manual_invoices (soporta MySQL/SQLite)
        try {
            if ($isSqlite) {
                // Crear tabla si no existe (SQLite)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS manual_invoices (
                        id             INTEGER PRIMARY KEY AUTOINCREMENT,
                        created_at     TEXT NOT NULL,
                        reason         TEXT NOT NULL,
                        receptor_nit   TEXT NOT NULL,
                        receptor_name  TEXT NULL,
                        amount         REAL NOT NULL DEFAULT 0.00,
                        used_monthly   INTEGER NOT NULL DEFAULT 0,
                        send_to_fel    INTEGER NOT NULL DEFAULT 1,
                        fel_uuid       TEXT NULL,
                        fel_status     TEXT NULL,
                        fel_message    TEXT NULL,
                        fel_pdf_base64 TEXT NULL
                    );
                ");

                // Columnas existentes
                $cols = [];
                $rs = $pdo->query("PRAGMA table_info(manual_invoices)");
                foreach ($rs ?: [] as $r) { $cols[$r['name']] = true; }

                if (!isset($cols['receptor_name'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN receptor_name TEXT NULL;");
                }
                if (!isset($cols['fel_pdf_base64'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN fel_pdf_base64 TEXT NULL;");
                }
                if (!isset($cols['created_at'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN created_at TEXT NOT NULL DEFAULT '';");
                }
            } else {
                // MySQL/MariaDB
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS manual_invoices (
                        id             INT AUTO_INCREMENT PRIMARY KEY,
                        created_at     DATETIME NOT NULL,
                        reason         VARCHAR(255) NOT NULL,
                        receptor_nit   VARCHAR(32)  NOT NULL,
                        receptor_name  VARCHAR(255) NULL,
                        amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        used_monthly   TINYINT(1) NOT NULL DEFAULT 0,
                        send_to_fel    TINYINT(1) NOT NULL DEFAULT 1,
                        fel_uuid       VARCHAR(64)  NULL,
                        fel_status     VARCHAR(32)  NULL,
                        fel_message    VARCHAR(255) NULL,
                        fel_pdf_base64 MEDIUMTEXT   NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                // Columnas existentes
                $cols = [];
                $q = $pdo->query("SHOW COLUMNS FROM manual_invoices");
                while ($r = $q->fetch(\PDO::FETCH_ASSOC)) { $cols[$r['Field']] = true; }

                if (!isset($cols['receptor_name'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN receptor_name VARCHAR(255) NULL AFTER receptor_nit;");
                }
                if (!isset($cols['fel_pdf_base64'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN fel_pdf_base64 MEDIUMTEXT NULL AFTER fel_message;");
                }
                if (!isset($cols['created_at'])) {
                    $pdo->exec("ALTER TABLE manual_invoices ADD COLUMN created_at DATETIME NOT NULL AFTER id;");
                }
            }
        } catch (\Throwable $e) {
            $this->debugLog('fel_manual_invoice_autofix.txt', ['error' => $e->getMessage()]);
        }

        // === Cargar settings para monthly_rate
        $settings = [];
        try { $settings = \App\Utils\Schema::loadAppSettings(); } catch (\Throwable $e) {}
        $monthlyRateCfg = isset($settings['billing']['monthly_rate']) ? (float)$settings['billing']['monthly_rate'] : null;
        $monthlyRateNN  = is_numeric($monthlyRateCfg) ? (float)$monthlyRateCfg : 0.00;

        // === Resolver total
        if ($isGrace) {
            $total = 0.00;
        } elseif ($mode === 'monthly') {
            if (!is_finite($monthlyRateNN) || $monthlyRateNN <= 0) {
                echo json_encode(['ok' => false, 'error' => 'monthly_rate no configurado o inválido']); return;
            }
            $total = $monthlyRateNN;
        } else { // custom
            if (!is_finite($amountIn) || $amountIn <= 0) {
                echo json_encode(['ok' => false, 'error' => 'amount inválido (> 0)']); return;
            }
            $total = (float)$amountIn;
        }

        // === FEL (usar la NUEVA función con PDF)
        $felOk = false; $uuid = null; $felRes = null; $felErr = null; $pdfB64 = null; $pdfSavedPath = null;

        if (!$isGrace) {
            $client = new \App\Services\G4SClient($cfg);
            $payloadFel = [
                'ticket_no'    => null, // manual
                'receptor_nit' => $receptorNit,
                'total'        => $total,
                'descripcion'  => mb_substr($reason, 0, 120),
            ];

            $this->debugLog('fel_manual_invoice_out.txt', ['request_payload' => $payloadFel]);

            try {
                // NUEVO: pedir PDF con método que retorna uuid y pdf_base64
                $felRes  = $client->submitInvoiceWithPdf($payloadFel);
                $felOk   = (bool)($felRes['ok'] ?? false);
                $uuid    = $felRes['uuid']        ?? null;
                $pdfB64  = $felRes['pdf_base64']  ?? null;
                $felErr  = $felRes['error']       ?? null;

                // Guardar PDF como archivo si vino
                if ($felOk && $pdfB64) {
                    $tzGT = new \DateTimeZone('America/Guatemala');
                    $now  = new \DateTime('now', $tzGT);
                    $dir  = sprintf('%s/fel/%s/%s',
                        rtrim((string)$cfg->get('STORAGE_PATH', __DIR__.'/../../storage'), '/'),
                        $now->format('Y'),
                        $now->format('m')
                    );
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);

                    $fname = ($uuid ?: 'DTE-'.$now->format('Ymd-His')).'.pdf';
                    $pdfSavedPath = $dir.'/'.$fname;
                    @file_put_contents($pdfSavedPath, base64_decode($pdfB64));
                }
            } catch (\Throwable $e) {
                $felOk  = false;
                $felErr = $e->getMessage();
                $felRes = ['ok'=>false,'error'=>$felErr];
            }
        }

        // === Timestamp Guatemala forzado (para el INSERT)
        $tzGT      = new \DateTimeZone('America/Guatemala');
        $createdDT = new \DateTime('now', $tzGT);
        $createdAt = $createdDT->format('Y-m-d H:i:s'); // siempre UTC-6 en texto

        // === Persistir SOLO en manual_invoices
        $pdo->beginTransaction();
        $stmtM = $pdo->prepare("
            INSERT INTO manual_invoices
                (created_at, reason, receptor_nit, receptor_name, amount, used_monthly, send_to_fel, fel_uuid, fel_status, fel_message, fel_pdf_base64)
            VALUES
                (:created_at, :r, :nit, :name, :amt, :um, :send, :uuid, :st, :msg, :pdf)
        ");
        $stmtM->execute([
            ':created_at' => $createdAt,
            ':r'          => $reason,
            ':nit'        => $receptorNit,
            ':name'       => ($receptorName !== '' ? $receptorName : null),
            ':amt'        => $total,
            ':um'         => ($mode === 'monthly') ? 1 : 0,
            ':send'       => $isGrace ? 0 : 1,
            ':uuid'       => $uuid,
            ':st'         => $isGrace ? 'SKIPPED' : ($felOk ? 'OK' : 'ERROR'),
            ':msg'        => $isGrace ? 'no FEL (grace)' : ($felOk ? 'Emitida correctamente' : ($felErr ?: 'Error FEL')),
            ':pdf'        => ($felOk && $pdfB64) ? $pdfB64 : null,
        ]);
        $manualId = (int)$pdo->lastInsertId();
        $pdo->commit();

        echo json_encode([
            'ok'               => $isGrace ? true : $felOk,
            'uuid'             => $uuid,
            'message'          => $isGrace ? 'Factura de gracia registrada (sin FEL)' : ($felOk ? 'Factura manual certificada' : 'No se pudo certificar (registrada en BD)'),
            'error'            => $isGrace ? null : $felErr,
            'billing_amount'   => (float)$total,
            'manual_id'        => $manualId,
            'created_at_gt'    => $createdAt,              // devuelvo la fecha/hora GT usada
            'pdf_base64'       => $pdfB64,                 // PDF para descargar en UI
            'pdf_saved_path'   => $pdfSavedPath,           // ruta guardada (si aplica)
            'manual_open'      => false,
            'pay_notify_sent'  => false,
            'pay_notify_ack'   => false,
            'pay_notify_error' => null,
        ], JSON_UNESCAPED_UNICODE);

    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $this->debugLog('fel_manual_invoice_exc.txt', ['exception' => $e->getMessage()]);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

    public function manualInvoiceList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // DB desde .env con helper
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $stmt = $pdo->query("
                SELECT
                    id, created_at, reason, receptor_nit, receptor_name, amount,
                    used_monthly, send_to_fel, fel_uuid, fel_status, fel_message
                FROM manual_invoices
                ORDER BY id DESC
                LIMIT 100
            ");
            $rows = $stmt->fetchAll();

            echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'manualInvoiceList: '.$e->getMessage()]);
        }
    }

    public function getFelDocumentPdfByUuid(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $uuid = trim((string)($_GET['uuid'] ?? ''));
            if ($uuid === '') { echo json_encode(['ok'=>false,'error'=>'uuid requerido']); return; }

            $cfg    = new \Config\Config(__DIR__ . '/../../.env');
            $client = new \App\Services\G4SClient($cfg);

            // intenta por orden más “oficial”
            $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT_SAT_PDF');
            if (!$client->isBase64Pdf($pdfB64)) {
                $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT_PDF');
            }
            if (!$client->isBase64Pdf($pdfB64)) {
                $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT');
            }

            if (!$client->isBase64Pdf($pdfB64)) {
                echo json_encode(['ok'=>false,'error'=>'PDF no disponible para ese UUID']); return;
            }

            // opcional: guardar en storage/fel/YYYY/MM/UUID.pdf
            $tzGT = new \DateTimeZone('America/Guatemala');
            $now  = new \DateTime('now', $tzGT);
            $dir  = sprintf('%s/fel/%s/%s',
                rtrim((string)$cfg->get('STORAGE_PATH', __DIR__.'/../../storage'), '/'),
                $now->format('Y'), $now->format('m')
            );
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir.'/'.$uuid.'.pdf', base64_decode($pdfB64));

            echo json_encode(['ok'=>true,'uuid'=>$uuid,'pdf_base64'=>$pdfB64]);
        } catch (\Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function getManualInvoicePdf(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id requerido']); return; }

            // Usa la config del controlador (.env) y el helper DB::pdo
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // Busca la factura
            $st  = $pdo->prepare("SELECT * FROM manual_invoices WHERE id = :i LIMIT 1");
            $st->execute([':i'=>$id]);
            $row = $st->fetch();

            if (!$row) { echo json_encode(['ok'=>false,'error'=>'No existe la factura']); return; }

            $pdfB64 = (string)($row['fel_pdf_base64'] ?? '');
            $uuid   = (string)($row['fel_uuid'] ?? '');

            // Si no hay PDF guardado pero sí UUID y fue enviada a FEL con estado OK, intenta descargar y persistir
            if ($pdfB64 === '' && $uuid !== '' && (int)$row['send_to_fel'] === 1 && strtoupper((string)$row['fel_status']) === 'OK') {
                $client = new \App\Services\G4SClient($cfg);

                $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT_SAT_PDF');
                if (!$client->isBase64Pdf($pdfB64)) {
                    $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT_PDF');
                }
                if (!$client->isBase64Pdf($pdfB64)) {
                    $pdfB64 = $client->fetchPdfByGuid($uuid, 'GET_DOCUMENT');
                }

                if ($client->isBase64Pdf($pdfB64)) {
                    // Guardar en BD
                    $up = $pdo->prepare("UPDATE manual_invoices SET fel_pdf_base64 = :p WHERE id = :i");
                    $up->execute([':p'=>$pdfB64, ':i'=>$id]);

                    // Guardar en disco
                    $tzGT = new \DateTimeZone('America/Guatemala');
                    $now  = new \DateTime('now', $tzGT);
                    $dir  = sprintf(
                        '%s/fel/%s/%s',
                        rtrim((string)$cfg->get('STORAGE_PATH', __DIR__.'/../../storage'), '/'),
                        $now->format('Y'),
                        $now->format('m')
                    );
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    @file_put_contents($dir.'/'.$uuid.'.pdf', base64_decode($pdfB64));
                }
            }

            if ($pdfB64 === '') { echo json_encode(['ok'=>false,'error'=>'PDF no disponible']); return; }
            echo json_encode(['ok'=>true,'id'=>$id,'uuid'=>$uuid ?: null,'pdf_base64'=>$pdfB64]);
        } catch (\Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function getManualInvoiceOne(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id requerido']); return; }

            // Usa la config del controlador (.env) y el helper DB::pdo
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $st = $pdo->prepare("SELECT * FROM manual_invoices WHERE id = :i LIMIT 1");
            $st->execute([':i'=>$id]);
            $row = $st->fetch();

            if (!$row) { echo json_encode(['ok'=>false,'error'=>'No existe']); return; }

            echo json_encode(['ok'=>true,'data'=>$row], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function felServePdf(): void
    {
        try {
            $uuid = isset($_GET['uuid']) ? trim((string)$_GET['uuid']) : '';
            $id   = isset($_GET['id'])   ? (int)$_GET['id'] : 0;

            if ($uuid === '' && $id <= 0) {
                http_response_code(400);
                echo 'Missing uuid or id';
                return;
            }

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Cargar fila
            if ($uuid !== '') {
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE uuid = :u LIMIT 1");
                $stmt->execute([':u' => $uuid]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :i LIMIT 1");
                $stmt->execute([':i' => $id]);
            }
            $inv = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$inv) {
                http_response_code(404);
                echo 'Invoice not found';
                return;
            }

            $uuid = (string)($inv['uuid'] ?? $uuid);

            // 1) Si ya hay pdf_base64 en BD => servir
            $pdfB64 = (string)($inv['pdf_base64'] ?? '');
            if ($pdfB64 !== '') {
                $this->outputPdfFromBase64($pdfB64, $uuid);
                return;
            }

            // 2) Intentar obtener PDF desde G4S por UUID
            $pdfB64 = $uuid ? ($this->tryFetchFelPdfBase64($uuid) ?? '') : '';

            // 3) Si sigue vacío, intentar con XML almacenado en BD
            if ($pdfB64 === '') {
                $xml = $this->extractXmlFromInvoiceRow($inv);
                if ($xml !== null && $xml !== '') {
                    $pdfB64 = $this->pdfFromXmlViaG4S($xml) ?? '';
                }
            }

            if ($pdfB64 === '') {
                http_response_code(404);
                echo 'PDF not available';
                return;
            }

            // Guardar en BD para la próxima
            try {
                $this->ensureInvoicePdfColumn($pdo);
                $u = $pdo->prepare("UPDATE invoices SET pdf_base64 = :b WHERE uuid = :u OR id = :i");
                $u->execute([':b'=>$pdfB64, ':u'=>$uuid, ':i'=>$inv['id'] ?? 0]);
            } catch (\Throwable $e) {
                $this->debugLog('fel_pdf_save_b64_err.txt', ['e'=>$e->getMessage()]);
            }

            // Servir
            $this->outputPdfFromBase64($pdfB64, $uuid);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Error: '.$e->getMessage();
        }
    }

    /** Intenta sacar XML desde columnas comunes */
    private function extractXmlFromInvoiceRow(array $inv): ?string
    {
        // 1) Columna dedicada (recomendada)
        if (!empty($inv['xml_dte'])) {
            return (string)$inv['xml_dte'];
        }

        // 2) Algunos guardan XML en response_json o en request_json base64
        $try = ['response_json','request_json'];
        foreach ($try as $k) {
            if (!empty($inv[$k])) {
                $js = json_decode((string)$inv[$k], true);
                if (is_array($js)) {
                    // claves típicas: xml, dte_xml, xml_base64
                    if (!empty($js['xml']) && is_string($js['xml'])) return $js['xml'];
                    if (!empty($js['dte_xml']) && is_string($js['dte_xml'])) return $js['dte_xml'];
                    if (!empty($js['xml_base64']) && is_string($js['xml_base64'])) {
                        $bin = base64_decode($js['xml_base64'], true);
                        if ($bin !== false) return $bin;
                    }
                }
            }
        }
        return null;
    }

    /** Envía headers y descarga PDF desde base64 */
    private function outputPdfFromBase64(string $b64, string $uuid = ''): void
    {
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            http_response_code(500);
            echo 'Invalid base64';
            return;
        }
        $fname = 'DTE'.($uuid ? '-'.$uuid : '').'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Content-Length: '.strlen($bin));
        echo $bin;
    }

    private function pdfFromXmlViaG4S(string $xml): ?string
    {
        $xml = trim($xml);
        if ($xml === '') return null;

        $data3 = (string)$this->config->get('FEL_G4S_DATA3', '');
        $data2 = base64_encode($xml);

        try {
            $soapResp = $this->requestTransaction([
                'Transaction' => 'SYSTEM_REQUEST',
                'Data1'       => 'POST_DOCUMENT_SAT_PDF',
                'Data2'       => $data2,
                'Data3'       => $data3,
            ]);
            return $this->extractPdfBase64FromSoap($soapResp);
        } catch (\Throwable $e) {
            $this->debugLog('g4s_pdf_from_xml_err.txt', ['e'=>$e->getMessage()]);
            return null;
        }
    }

    private function pdfFromUuidViaG4S(string $uuid): ?string
    {
        $uuid  = trim($uuid);
        if ($uuid === '') return null;

        $data3 = (string)$this->config->get('FEL_G4S_DATA3', '');

        try {
            $soapResp = $this->requestTransaction([
                'Transaction' => 'SYSTEM_REQUEST',
                'Data1'       => 'GET_DOCUMENT_SAT_PDF', // algunos emisores usan exactamente este literal
                'Data2'       => $uuid,                   // UUID en Data2
                'Data3'       => $data3,
            ]);
            return $this->extractPdfBase64FromSoap($soapResp);
        } catch (\Throwable $e) {
            $this->debugLog('g4s_pdf_from_uuid_err.txt', ['e'=>$e->getMessage()]);
            return null;
        }
    }

    private function extractPdfBase64FromSoap(string $soapResp): ?string
    {
        // 1) JSON embebido dentro de RequestTransactionResult
        if (preg_match('/<RequestTransactionResult>(.*?)<\/RequestTransactionResult>/is', $soapResp, $m)) {
            $content = trim($m[1]);
            $json = json_decode($content, true);
            if (is_array($json)) {
                foreach (['pdf_base64','PDF','Pdf','pdf','document','data'] as $k) {
                    if (!empty($json[$k]) && is_string($json[$k])) {
                        $b64 = $json[$k];
                        $bin = base64_decode($b64, true);
                        if ($bin !== false && strncmp($bin, '%PDF', 4) === 0) return $b64;
                    }
                }
            } else {
                $bin = base64_decode($content, true);
                if ($bin !== false && strncmp($bin, '%PDF', 4) === 0) return $content;
            }
        }
        // 2) Tags típicos en tu captura: ResponseData3 / ResponseData2
        foreach (['ResponseData3','ResponseData2','ResponseData1','PDF'] as $tag) {
            if (preg_match('/<'.$tag.'>(.*?)<\/'.$tag.'>/is', $soapResp, $m)) {
                $b64 = trim($m[1]);
                $bin = base64_decode($b64, true);
                if ($bin !== false && strncmp($bin, '%PDF', 4) === 0) return $b64;
            }
        }
        $this->debugLog('g4s_pdf_unparsed.txt', ['preview'=>mb_substr($soapResp, 0, 1500)]);
        return null;
    }

}

    
