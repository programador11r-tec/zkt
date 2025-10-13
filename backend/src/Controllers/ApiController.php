<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Http;
use App\Utils\Logger;
use App\Services\ZKTecoClient;
use App\Services\G4SClient;
use Config\Config;
use App\Utils\DB;
use PDO;

class ApiController {
    private Config $config;

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

    private function getHourlyRate(PDO $pdo): ?float {
        $raw = $this->getAppSetting($pdo, 'billing.hourly_rate');
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
            $rate = $this->getHourlyRate($pdo);
            $settings['billing']['hourly_rate'] = $rate;

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
                WHERE t.status = 'CLOSED'
                  AND EXISTS (SELECT 1 FROM payments p WHERE p.ticket_no = t.ticket_no)
                  AND NOT EXISTS (
                    SELECT 1 FROM invoices i2
                    WHERE i2.ticket_no = t.ticket_no
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
                    'subtitle' => 'Última certificación FEL registrada',
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

            $pdo = DB::pdo($this->config);
            $formatted = $value === null ? null : number_format($value, 2, '.', '');
            $this->setAppSetting($pdo, 'billing.hourly_rate', $formatted);

            Http::json([
                'ok' => true,
                'hourly_rate' => $value === null ? null : (float) $formatted,
            ]);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function simulateFelInvoice() {
        $body = Http::body();
        $invoice = $body['invoice'] ?? [
            'serie' => 'PRUEBA',
            'numero' => 1,
            'nitReceptor' => 'CF',
            'items' => [
                ['descripcion' => 'Servicio de Control de Acceso', 'cantidad' => 1, 'precio' => 100.00, 'iva' => 12.00, 'total' => 112.00]
            ],
            'total' => 112.00
        ];

        $g4s = new G4SClient($this->config);
        $resp = $g4s->submitInvoice($invoice);
        Http::json(['request' => $invoice, 'response' => $resp]);
    }

    public function syncTicketsAndPayments() {
        try {
            $pdo = DB::pdo($this->config);
            $zk = new ZKTecoClient($this->config);

            // Puedes parametrizar "since" por query ?since=ISO
            $since = $_GET['since'] ?? date('c', strtotime('-3 days'));

            $entries  = $zk->listEntries($since);
            $payments = $zk->listPayments($since);

            $pdo->beginTransaction();

            $countT = 0;
            foreach ($entries as $t) { DB::upsertTicket($pdo, $t); $countT++; }

            $countP = 0;
            foreach ($payments as $p) { 
                // evitar duplicados burdos: si ya existe pago exacto, sáltalo
                $dupe = $pdo->prepare("SELECT 1 FROM payments WHERE ticket_no=? AND amount=? AND paid_at=?");
                $dupe->execute([$p['ticket_no'], $p['amount'], $p['paid_at']]);
                if (!$dupe->fetchColumn()) {
                    DB::insertPayment($pdo, $p); 
                    $countP++;
                }
            }

            $pdo->commit();

            Http::json([
                'ok' => true,
                'since' => $since,
                'tickets_upserted' => $countT,
                'payments_inserted' => $countP
            ]);
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            Logger::error('syncTicketsAndPayments error', ['e'=>$e->getMessage()]);
            Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function invoiceClosedTickets() {
        try {
            $pdo = DB::pdo($this->config);
            $g4s = new G4SClient($this->config);

            // Seleccionar tickets CERRADOS con pagos y sin factura
            $sql = "SELECT t.* FROM tickets t
                    WHERE t.status='CLOSED' 
                    AND EXISTS (SELECT 1 FROM payments p WHERE p.ticket_no=t.ticket_no)
                    AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.ticket_no=t.ticket_no)";
            $tickets = $pdo->query($sql)->fetchAll();

            $results = [];
            foreach ($tickets as $t) {
                // pagos del ticket
                $ps = $pdo->prepare("SELECT * FROM payments WHERE ticket_no=?");
                $ps->execute([$t['ticket_no']]);
                $payments = $ps->fetchAll();

                // construir payload
                $payload = $g4s->buildInvoiceFromTicket($t, $payments);

                // enviar a G4S
                $resp = $g4s->submitInvoice($payload);

                $uuid = $resp['uuid'] ?? $resp['UUID'] ?? null;
                $status = $uuid ? 'OK' : 'ERROR';

                // guardar resultado
                $ins = $pdo->prepare("INSERT INTO invoices (ticket_no, total, uuid, status, request_json, response_json, created_at)
                                    VALUES (:ticket_no, :total, :uuid, :status, :request_json, :response_json, datetime('now'))");
                $total = 0.0; foreach ($payments as $p) $total += (float)$p['amount'];
                $ins->execute([
                    ':ticket_no' => $t['ticket_no'],
                    ':total' => $total,
                    ':uuid' => $uuid,
                    ':status' => $status,
                    ':request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ':response_json' => json_encode($resp, JSON_UNESCAPED_UNICODE),
                ]);

                $results[] = [
                    'ticket_no' => $t['ticket_no'],
                    'total' => $total,
                    'status' => $status,
                    'uuid' => $uuid,
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

            // Normalización → filas para tabla
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

    public function invoiceOne() {
        try {
            $b = \App\Utils\Http::body();
            $ticketNo    = trim((string)($b['ticket_no'] ?? ''));
            $receptorNit = $b['receptor_nit'] ?? 'CF';
            $serie       = $b['serie'] ?? 'A';
            $numero      = $b['numero'] ?? null;
            if ($ticketNo === '') throw new \InvalidArgumentException('ticket_no requerido');

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->ensureSettingsTable($pdo);

            // total
            $st = $pdo->prepare("
                SELECT
                    t.ticket_no,
                    COALESCE(SUM(p.amount), t.amount, 0) AS total,
                    t.entry_at,
                    t.exit_at,
                    t.duration_min,
                    t.amount AS ticket_amount
                FROM tickets t
                LEFT JOIN payments p ON p.ticket_no = t.ticket_no
                WHERE t.ticket_no = :t
                GROUP BY t.ticket_no, t.entry_at, t.exit_at, t.duration_min, t.amount
            ");
            $st->execute([':t'=>$ticketNo]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new \RuntimeException('Ticket no encontrado');
            $mode = strtolower((string)($b['mode'] ?? ''));
            $description = trim((string)($b['description'] ?? ''));

            $durationMin = null;
            $entryTs = !empty($row['entry_at']) ? strtotime((string) $row['entry_at']) : false;
            $exitTs  = !empty($row['exit_at'])  ? strtotime((string) $row['exit_at'])  : false;
            if ($entryTs && $exitTs && $exitTs > $entryTs) {
                $durationMin = (int) round(($exitTs - $entryTs) / 60);
            }
            if ($durationMin === null || $durationMin <= 0) {
                $durationMin = isset($row['duration_min']) ? (int) $row['duration_min'] : null;
            }
            $hours = $durationMin !== null && $durationMin > 0 ? max(1.0, round($durationMin / 60, 2)) : null;

            $total = (float)($row['total'] ?? 0);
            $itemQuantity = 1.0;
            $itemPrice = $total;
            $concept = $description !== '' ? $description : 'Ticket de parqueo';

            if ($mode === 'hourly') {
                $hourlyRate = $this->getHourlyRate($pdo);
                if ($hourlyRate === null) {
                    throw new \RuntimeException('Configura la tarifa por hora en Ajustes antes de facturar por tiempo.');
                }
                if ($hours === null || $hours <= 0) {
                    throw new \RuntimeException('No se pudo calcular la duración del ticket para aplicar la tarifa por hora.');
                }
                $total = round($hours * $hourlyRate, 2);
                if ($total <= 0) {
                    throw new \RuntimeException('El total calculado por hora es inválido.');
                }
                $itemQuantity = round($hours, 2);
                $itemPrice = $hourlyRate;
                if ($description === '') {
                    $concept = sprintf('Servicio de parqueo %.2f h x Q%.2f', $itemQuantity, $hourlyRate);
                }
            } elseif ($mode === 'custom') {
                $customRaw = $b['custom_total'] ?? null;
                if ($customRaw === null || $customRaw === '') {
                    throw new \InvalidArgumentException('Ingresa el total personalizado para facturar.');
                }
                if (!is_numeric($customRaw)) {
                    throw new \InvalidArgumentException('El total personalizado debe ser numérico.');
                }
                $total = round((float) $customRaw, 2);
                if ($total <= 0) {
                    throw new \InvalidArgumentException('El total personalizado debe ser mayor a cero.');
                }
                $itemQuantity = 1.0;
                $itemPrice = $total;
                if ($description === '') {
                    $concept = 'Servicio personalizado de parqueo';
                }
            }

            if ($total <= 0) throw new \RuntimeException('Total 0 para facturar');

            $ticketUpdate = $pdo->prepare('UPDATE tickets SET amount = :amount WHERE ticket_no = :ticket');
            $ticketUpdate->execute([':amount' => $total, ':ticket' => $ticketNo]);

            // ya facturado/en proceso
            $chk = $pdo->prepare("SELECT status, uuid FROM invoices WHERE ticket_no=:t LIMIT 1");
            $chk->execute([':t'=>$ticketNo]);
            $iv = $chk->fetch(\PDO::FETCH_ASSOC);
            if ($iv && in_array($iv['status'], ['PENDING','OK'], true)) {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Ticket ya facturado o en proceso','uuid'=>$iv['uuid']], 409);
                return;
            }

            // payload FEL (simple)
            $doc = [
                'metadata' => [
                    'ticketNo'    => $ticketNo,
                    'external_id' => $ticketNo,
                    'issueDate'   => $row['exit_at'] ?? $row['entry_at'] ?? date('c'),
                    'billing_mode'=> $mode ?: 'default',
                    'hours'       => $hours,
                ],
                'emisor' => [
                    'nit' => $this->config->get('FEL_G4S_ENTITY', ''),
                ],
                'receptor' => [
                    'nit' => $receptorNit,
                    'nombre' => ($receptorNit==='CF'?'Consumidor Final':null),
                ],
                'documento' => [
                    'serie'  => $serie,
                    'numero' => $numero,
                    'moneda' => 'GTQ',
                    'items'  => [
                        ['descripcion'=>$concept, 'cantidad'=>$itemQuantity, 'precio'=>$itemPrice, 'iva'=>0, 'total'=>$total]
                    ],
                    'total'  => $total
                ]
            ];

            // marca PENDING
            $insPending = $pdo->prepare("
            INSERT INTO invoices (ticket_no, total, uuid, status, request_json, response_json, created_at)
            VALUES (:t, :total, NULL, 'PENDING', :req, NULL, NOW())
            ON DUPLICATE KEY UPDATE uuid=NULL, status='PENDING', request_json=VALUES(request_json), response_json=NULL
            ");
            $insPending->execute([':t'=>$ticketNo, ':total'=>$total, ':req'=>json_encode($doc, JSON_UNESCAPED_UNICODE)]);

            // === llamada a G4S ===
            $g4s = new \App\Services\G4SClient($this->config);
            try {
                // usa tu implementación real:
                $resp = method_exists($g4s,'submitInvoice')
                    ? $g4s->submitInvoice($doc)
                    : json_decode($g4s->requestTransaction([
                        'Transaction'=>'TIMBRAR',
                        'Data1'=> json_encode($doc, JSON_UNESCAPED_UNICODE)
                    ]), true);

                $uuid   = $resp['uuid'] ?? $resp['UUID'] ?? ($resp['Response']['Identifier']['DocumentGUID'] ?? null);
                $status = $uuid ? 'OK' : 'ERROR';

                $updIv = $pdo->prepare("UPDATE invoices SET uuid=:uuid, status=:status, response_json=:resp WHERE ticket_no=:t");
                $updIv->execute([
                    ':uuid'=>$uuid,
                    ':status'=>$status,
                    ':resp'=>json_encode($resp, JSON_UNESCAPED_UNICODE),
                    ':t'=>$ticketNo
                ]);

                if ($uuid) {
                    $pdo->prepare("UPDATE tickets SET invoiced_at=NOW(), invoice_status='OK' WHERE ticket_no=:t")
                        ->execute([':t'=>$ticketNo]);
                }

                \App\Utils\Http::json(['ok'=>($status==='OK'), 'uuid'=>$uuid, 'response'=>$resp]);
            } catch (\Throwable $eCall) {
                // Guarda el error de la llamada
                $err = ['error'=>$eCall->getMessage(), 'trace'=>($this->config->get('APP_DEBUG','true')==='true' ? $eCall->getTraceAsString(): null)];
                $pdo->prepare("UPDATE invoices SET status='ERROR', response_json=:resp WHERE ticket_no=:t")
                    ->execute([':resp'=>json_encode($err, JSON_UNESCAPED_UNICODE), ':t'=>$ticketNo]);

                throw $eCall; // para que también se refleje al front
            }

        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    public function ingestTickets() {
        try {
            // seguridad simple por header
            $key = $this->config->get('INGEST_KEY', '');
            if ($key !== '' && ($_SERVER['HTTP_X_INGEST_KEY'] ?? '') !== $key) {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Unauthorized'], 401);
                return;
            }

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $body = \App\Utils\Http::body();

            $rows = [];

            // A) Formato “raw ZKBio CVSecurity”: { code, message, data: { data: [ ... ] } }
            if (isset($body['data']['data']) && is_array($body['data']['data'])) {
                foreach ($body['data']['data'] as $r) {
                    // Mapeo de campos de tu ejemplo:
                    $id        = (string)($r['id'] ?? '');
                    if ($id === '') continue;

                    $car       = (string)($r['carNumber'] ?? null);
                    $exit      = (string)($r['checkOutTime'] ?? null); // "yyyy-MM-dd HH:mm:ss.SSS"
                    $parking   = (string)($r['parkingTime'] ?? null);  // "HH:mm:ss"

                    // Calcula entry_at si tenemos parkingTime y checkOutTime
                    $entryAt = null;
                    if ($exit && $parking && preg_match('/^\d{2}:\d{2}:\d{2}/', $parking)) {
                        $secs = 0;
                        [$h,$m,$s] = array_map('intval', explode(':', substr($parking,0,8)));
                        $secs = $h*3600 + $m*60 + $s;
                        $exitTs = strtotime(str_replace('.', '', $exit)); // tolerante a .mmm
                        if ($exitTs) $entryAt = date('Y-m-d H:i:s', $exitTs - $secs);
                    }

                    $rows[] = [
                        'ticket_no'   => $id,
                        'plate'       => $car ?: null,
                        'status'      => 'CLOSED',             // por ser record OUT
                        'entry_at'    => $entryAt,
                        'exit_at'     => $exit ? substr($exit,0,19) : null,
                        'duration_min'=> isset($parking) && strlen($parking)>=5 ? 
                                        (int)floor((($h??0)*60)+($m??0)+(($s??0)>0?1:0)) : null,
                        'amount'      => null,                 // si no viene, queda null
                        'source'      => 'zkbio',
                        'raw_json'    => json_encode($r, JSON_UNESCAPED_UNICODE),
                    ];
                }

            // B) Formato normalizado: { tickets: [ {ticket_no, plate, entry_at, exit_at, duration_min, amount, status} ] }
            } elseif (isset($body['tickets']) && is_array($body['tickets'])) {
                foreach ($body['tickets'] as $t) {
                    if (empty($t['ticket_no'])) continue;
                    $rows[] = [
                        'ticket_no'   => (string)$t['ticket_no'],
                        'plate'       => $t['plate'] ?? null,
                        'status'      => strtoupper((string)($t['status'] ?? 'CLOSED')),
                        'entry_at'    => $t['entry_at'] ?? null,
                        'exit_at'     => $t['exit_at'] ?? null,
                        'duration_min'=> isset($t['duration_min']) ? (int)$t['duration_min'] : null,
                        'amount'      => isset($t['amount']) ? (float)$t['amount'] : null,
                        'source'      => $t['source'] ?? 'external',
                        'raw_json'    => json_encode($t, JSON_UNESCAPED_UNICODE),
                    ];
                }
            } else {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Payload inválido'], 400);
                return;
            }

            if (!$rows) { \App\Utils\Http::json(['ok'=>true,'ingested'=>0]); return; }

            $up = $pdo->prepare("
                INSERT INTO tickets (ticket_no, plate, status, entry_at, exit_at, duration_min, amount, source, raw_json, created_at, updated_at)
                VALUES (:ticket_no,:plate,:status,:entry_at,:exit_at,:duration_min,:amount,:source,:raw_json, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                plate=VALUES(plate),
                status=VALUES(status),
                entry_at=VALUES(entry_at),
                exit_at=VALUES(exit_at),
                duration_min=VALUES(duration_min),
                amount=VALUES(amount),
                source=VALUES(source),
                raw_json=VALUES(raw_json),
                updated_at=NOW()
            ");

            $n=0;
            foreach ($rows as $r) { $up->execute($r); $n++; }

            \App\Utils\Http::json(['ok'=>true,'ingested'=>$n]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function ingestPayments() {
        try {
            // seguridad simple
            $key = $this->config->get('INGEST_KEY', '');
            if ($key !== '' && ($_SERVER['HTTP_X_INGEST_KEY'] ?? '') !== $key) {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Unauthorized'], 401);
                return;
            }

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $body = \App\Utils\Http::body();
            $rows = [];

            // A) Normalizado: { payments: [ {ticket_no, amount, method, paid_at, ref} ] }
            if (isset($body['payments']) && is_array($body['payments'])) {
                foreach ($body['payments'] as $p) {
                    if (empty($p['ticket_no']) || !isset($p['amount'])) continue;
                    $rows[] = [
                        'ticket_no' => (string)$p['ticket_no'],
                        'amount'    => (float)$p['amount'],
                        'method'    => $p['method'] ?? 'cash',
                        'paid_at'   => $p['paid_at'] ?? date('Y-m-d H:i:s'),
                        'ref'       => $p['ref'] ?? null,
                        'raw_json'  => json_encode($p, JSON_UNESCAPED_UNICODE),
                    ];
                }

            // B) Raw ZKBio (si algún endpoint de ZK trae pagos; aquí dejamos ejemplo genérico)
            } elseif (isset($body['data']['data']) && is_array($body['data']['data'])) {
                foreach ($body['data']['data'] as $r) {
                    // adapta si tu fuente de pagos trae otros nombres
                    $rows[] = [
                        'ticket_no' => (string)($r['id'] ?? ''),
                        'amount'    => (float)($r['amount'] ?? 0),
                        'method'    => (string)($r['method'] ?? 'cash'),
                        'paid_at'   => (string)($r['paidAt'] ?? date('Y-m-d H:i:s')),
                        'ref'       => (string)($r['txn'] ?? null),
                        'raw_json'  => json_encode($r, JSON_UNESCAPED_UNICODE),
                    ];
                }
            } else {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Payload inválido'], 400);
                return;
            }

            if (!$rows) { \App\Utils\Http::json(['ok'=>true,'ingested'=>0]); return; }

            $ins = $pdo->prepare("
                INSERT INTO payments (ticket_no, amount, method, paid_at, ref, raw_json, created_at)
                VALUES (:ticket_no,:amount,:method,:paid_at,:ref,:raw_json, NOW())
            ");
            $n=0;
            foreach ($rows as $r) { $ins->execute($r); $n++; }

            \App\Utils\Http::json(['ok'=>true,'ingested'=>$n]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

    public function ingestBulk(){
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            $b = \App\Utils\Http::body();

            $tickets  = is_array($b['tickets']  ?? null) ? $b['tickets']  : [];
            $payments = is_array($b['payments'] ?? null) ? $b['payments'] : [];

            $pdo->beginTransaction();

            // upsert tickets
            if ($tickets) {
                $stT = $pdo->prepare("
                INSERT INTO tickets (ticket_no, plate, status, entry_at, exit_at, duration_min, amount, created_at)
                VALUES (:ticket_no,:plate,:status,:entry_at,:exit_at,:duration_min,:amount, datetime('now'))
                ON CONFLICT(ticket_no) DO UPDATE SET
                    plate=excluded.plate, status=excluded.status, entry_at=excluded.entry_at,
                    exit_at=excluded.exit_at, duration_min=excluded.duration_min, amount=excluded.amount
                ");
                foreach ($tickets as $t) {
                    if (empty($t['ticket_no'])) continue;
                    $stT->execute([
                        ':ticket_no'=>$t['ticket_no'],
                        ':plate'=>$t['plate'] ?? null,
                        ':status'=>$t['status'] ?? 'OPEN',
                        ':entry_at'=>$t['entry_at'] ?? null,
                        ':exit_at'=>$t['exit_at'] ?? null,
                        ':duration_min'=> isset($t['duration_min']) ? (int)$t['duration_min'] : null,
                        ':amount'=> isset($t['amount']) ? (float)$t['amount'] : 0,
                    ]);
                }
            }

            // insert payments
            if ($payments) {
                $stP = $pdo->prepare("
                INSERT INTO payments (ticket_no, amount, method, paid_at, created_at)
                VALUES (:ticket_no,:amount,:method,:paid_at, datetime('now'))
                ");
                foreach ($payments as $p) {
                    if (empty($p['ticket_no']) || (float)($p['amount'] ?? 0) <= 0) continue;
                    $stP->execute([
                        ':ticket_no'=>$p['ticket_no'],
                        ':amount'=>(float)$p['amount'],
                        ':method'=>$p['method'] ?? null,
                        ':paid_at'=>$p['paid_at'] ?? date('c'),
                    ]);
                }
            }

            $pdo->commit();
            \App\Utils\Http::json(['ok'=>true,'tickets'=>count($tickets),'payments'=>count($payments)]);
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    public function getTicketsFromDB() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            // últimos 200 tickets (ejemplo)
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
                COALESCE(SUM(p.amount), t.amount, 0) AS total,
                t.receptor_nit AS receptor,
                t.duration_min,
                t.entry_at,
                t.exit_at,
                t.amount AS ticket_amount,
                NULL AS uuid,
                NULL AS estado
            FROM tickets t
            LEFT JOIN payments p ON p.ticket_no = t.ticket_no
            WHERE t.status = 'CLOSED'
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
            foreach ($rows as &$row) {
                $durationMin = isset($row['duration_min']) ? (int) $row['duration_min'] : null;
                if (($durationMin === null || $durationMin <= 0) && !empty($row['entry_at']) && !empty($row['exit_at'])) {
                    $entryTs = strtotime((string) $row['entry_at']);
                    $exitTs = strtotime((string) $row['exit_at']);
                    if ($entryTs && $exitTs && $exitTs > $entryTs) {
                        $durationMin = (int) round(($exitTs - $entryTs) / 60);
                    }
                }
                $row['duration_minutes'] = $durationMin;
                $row['hours'] = $durationMin !== null && $durationMin > 0 ? round($durationMin / 60, 2) : null;
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
                    COALESCE(SUM(p.amount), t.amount, 0)        AS total,
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
                $amount = (float)($row['total'] ?? 0);
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
                $amount = (float)($row['total'] ?? 0);
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

            // PDF binario como base64 o bytes según proveedor; aquí asumimos base64 en Response.Data
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

    public function ingestZKBioMerge() {
        try {
            // Seguridad por header
            $key = $this->config->get('INGEST_KEY', '');
            if ($key !== '' && ($_SERVER['HTTP_X_INGEST_KEY'] ?? '') !== $key) {
                \App\Utils\Http::json(['ok'=>false,'error'=>'Unauthorized'], 401);
                return;
            }

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $body = \App\Utils\Http::body();  // <- wrapper
            if (!is_array($body)) { \App\Utils\Http::json(['ok'=>false,'error'=>'Payload inválido (no JSON)'],400); return; }
            $outRoot = $body['out'] ?? null;
            $inRoot  = $body['in']  ?? null;

            // Helpers para sacar el array de data
            $toArray = function($root) {
                if (!$root) return [];
                if (isset($root['data']['data']) && is_array($root['data']['data'])) return $root['data']['data'];
                if (isset($root['data']) && is_array($root['data'])) return $root['data'];
                return [];
            };

            $outs = $toArray($outRoot);
            $ins  = $toArray($inRoot);

            // Indexar entradas por placa y ordenar por checkInTime asc
            $insByPlate = [];
            foreach ($ins as $r) {
                $plate = (string)($r['carNumber'] ?? '');
                if ($plate === '') continue;
                $insByPlate[$plate][] = $r;
            }
            foreach ($insByPlate as &$arr) {
                usort($arr, function($a,$b){
                    $da = strtotime((string)($a['checkInTime'] ?? '')) ?: 0;
                    $db = strtotime((string)($b['checkInTime'] ?? '')) ?: 0;
                    return $da <=> $db;
                });
            }
            unset($arr);

            // Armar filas normalizadas
            $rows = [];
            foreach ($outs as $o) {
                $id    = (string)($o['id'] ?? '');
                if ($id === '') continue;

                $plate = (string)($o['carNumber'] ?? '');
                $exitS = (string)($o['checkOutTime'] ?? '');
                $exitS = $exitS !== '' ? substr($exitS,0,19) : null; // quitar .mmm
                $exitT = $exitS ? strtotime($exitS) : null;

                // Buscar la última entrada <= salida
                $entryS = null;
                if ($plate !== '' && $exitT && !empty($insByPlate[$plate])) {
                    $cands = $insByPlate[$plate];
                    for ($i = count($cands)-1; $i >= 0; $i--) {
                        $ci = (string)($cands[$i]['checkInTime'] ?? '');
                        $ci = $ci !== '' ? substr($ci,0,19) : null;
                        if (!$ci) continue;
                        $ciT = strtotime($ci);
                        if ($ciT && $ciT <= $exitT) { $entryS = $ci; break; }
                    }
                }

                // Calcular duración
                $dur = null;
                if ($entryS && $exitT) {
                    $enT = strtotime($entryS);
                    if ($enT && $exitT >= $enT) {
                        $mins = (int)round(($exitT - $enT)/60);
                        if ($mins >= 0) $dur = $mins;
                    }
                } else {
                    // fallback a parkingTime si existe
                    $pt = (string)($o['parkingTime'] ?? '');
                    if (preg_match('/^\d{2}:\d{2}:\d{2}/', $pt)) {
                        [$hh,$mm,$ss] = array_map('intval', explode(':', substr($pt,0,8)));
                        $dur = $hh*60 + $mm + ($ss > 0 ? 1 : 0);
                        if ($exitT && $dur !== null) {
                            $entryS = date('Y-m-d H:i:s', $exitT - ($dur*60));
                        }
                    }
                }

                $rows[] = [
                    'ticket_no'    => $id,
                    'plate'        => $plate ?: null,
                    'status'       => 'CLOSED',
                    'entry_at'     => $entryS,
                    'exit_at'      => $exitS,
                    'duration_min' => $dur,
                    'amount'       => null,
                    'source'       => 'zkbio',
                    'raw_json'     => json_encode($o, JSON_UNESCAPED_UNICODE),
                ];
            }

            if (!$rows) { \App\Utils\Http::json(['ok'=>true,'ingested'=>0]); return; }

            // UPSERT (tu misma consulta)
            $up = $pdo->prepare("
                INSERT INTO tickets (ticket_no, plate, status, entry_at, exit_at, duration_min, amount, source, raw_json, created_at, updated_at)
                VALUES (:ticket_no,:plate,:status,:entry_at,:exit_at,:duration_min,:amount,:source,:raw_json, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    plate=VALUES(plate),
                    status=VALUES(status),
                    entry_at=VALUES(entry_at),
                    exit_at=VALUES(exit_at),
                    duration_min=VALUES(duration_min),
                    amount=VALUES(amount),
                    source=VALUES(source),
                    raw_json=VALUES(raw_json),
                    updated_at=NOW()
            ");

            $n=0;
            foreach ($rows as $r) { $up->execute($r); $n++; }

            \App\Utils\Http::json(['ok'=>true,'ingested'=>$n]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
        }
    }

}

    