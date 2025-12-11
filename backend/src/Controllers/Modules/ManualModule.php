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
trait ManualModule
{
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
            if ($baseUrl === '') { echo json_encode(['ok' => false, 'error' => 'HAMACHI_PARK_BASE_URL no est├í configurado.']); return; }

            // Token en querystring (lo que te funcion├│)
            $accessToken   = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            $tokenQueryKey = (string) ($this->config->get('HAMACHI_PARK_TOKEN_QUERY_KEY', 'access_token')); // 'access_token' o 'accessToken'

            $query = ['channelId' => $channelId];
            if ($accessToken !== '') $query[$tokenQueryKey] = $accessToken;

            $endpoint = $baseUrl . '/api/v1/parkBase/openGateChannel?' . http_build_query($query);

            // Headers m├¡nimos
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
                echo json_encode(['ok'=>false,'title'=>'Apertura manual','message'=>'HTTP(POST) fall├│: '.$err]); return;
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

            // Intentar agregar columna reason si la tabla ya exist├¡a sin ella
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

            // Intentar agregar columna reason si la tabla ya exist├¡a sin ella
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
                echo json_encode(['ok' => false, 'error' => 'NIT inv├ílido (use CF o solo d├¡gitos)']); return;
            }

            $isGrace = ($mode === 'grace');

            // === Config / DB
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // Detectar driver (para SQL espec├¡fico) y fijar TZ de sesi├│n si es MySQL/MariaDB
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
                    echo json_encode(['ok' => false, 'error' => 'monthly_rate no configurado o inv├ílido']); return;
                }
                $total = $monthlyRateNN;
            } else { // custom
                if (!is_finite($amountIn) || $amountIn <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'amount inv├ílido (> 0)']); return;
                }
                $total = (float)$amountIn;
            }

            // === FEL (usar la NUEVA funci├│n con PDF)
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
                    // NUEVO: pedir PDF con m├®todo que retorna uuid y pdf_base64
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

    public function reportsManualOpen(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            /** Conexi├│n */
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            /** Filtros */
            $from = $_GET['from'] ?? null;
            $to   = $_GET['to']   ?? null;
            $text = $_GET['q']    ?? null;  // b├║squeda general (usuario, motivo, etc.)

            $sql = "
                SELECT 
                    id,
                    channel_id,
                    opened_at,
                    reason,

                    CASE
                        WHEN channel_id = '40288048981adc4601981b7cb2660b05' THEN 'Salida'
                        WHEN channel_id = '40288048981adc4601981b7c2d010aff' THEN 'Entrada'
                        ELSE 'Otro canal'
                    END AS tipo
                FROM manual_open_logs
                WHERE 1=1
            ";

            $params = [];

            if ($from) {
                $sql .= " AND DATE(opened_at) >= :from";
                $params[':from'] = $from;
            }

            if ($to) {
                $sql .= " AND DATE(opened_at) <= :to";
                $params[':to'] = $to;
            }

            if ($text) {
                $sql .= " AND (
                            channel_id     LIKE :q
                            OR reason       LIKE :q
                            OR result_message LIKE :q
                            OR extra_json   LIKE :q
                        )";
                $params[':q'] = "%{$text}%";
            }

            $sql .= " ORDER BY opened_at DESC LIMIT 500";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            echo json_encode([
                'ok'   => true,
                'rows' => $rows,
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function reportManualInvoiceList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            /** @var \Config\Config $cfg */
            $cfg = $this->config;
            $pdo = \App\Utils\DB::pdo($cfg);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // Filtros que manda el front
            $from   = $_GET['from']   ?? null;
            $to     = $_GET['to']     ?? null;
            $mode   = $_GET['mode']   ?? null;   // ANY | custom | monthly | grace
            $nit    = $_GET['nit']    ?? null;
            $reason = $_GET['reason'] ?? null;

            $sql = "
                SELECT
                    id,
                    created_at,
                    reason,
                    receptor_nit,
                    receptor_name,
                    amount,
                    used_monthly,
                    send_to_fel,
                    fel_uuid,
                    fel_status,
                    fel_message,
                    fel_pdf_base64,

                    -- lo que espera el front como 'mode'
                    CASE
                        WHEN used_monthly = 1 THEN 'monthly'
                        WHEN (send_to_fel = 0 OR amount = 0) THEN 'grace'
                        ELSE 'custom'
                    END AS mode,

                    -- lo que espera el front como 'status'
                    fel_status AS status
                FROM manual_invoices
                WHERE 1=1
            ";

            $params = [];

            if ($from) {
                $sql .= " AND DATE(created_at) >= :from";
                $params[':from'] = $from;
            }

            if ($to) {
                $sql .= " AND DATE(created_at) <= :to";
                $params[':to'] = $to;
            }

            if ($nit) {
                $sql .= " AND receptor_nit LIKE :nit";
                $params[':nit'] = '%' . $nit . '%';
            }

            if ($reason) {
                $sql .= " AND reason LIKE :reason";
                $params[':reason'] = '%' . $reason . '%';
            }

            // filtro por modo (como el combo del front)
            if ($mode && $mode !== 'ANY') {
                if ($mode === 'monthly') {
                    $sql .= " AND used_monthly = 1";
                } elseif ($mode === 'grace') {
                    $sql .= " AND (send_to_fel = 0 OR amount = 0)";
                } else {
                    // 'custom' ÔåÆ todo lo que NO es mensual y NO es gracia
                    $sql .= " AND (used_monthly IS NULL OR used_monthly = 0)
                            AND (send_to_fel = 1 AND amount > 0)";
                }
            }

            $sql .= " ORDER BY created_at DESC LIMIT 1000";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Ô£à Normalizar fel_uuid para que el front no reciba 1/0 como UUID
            foreach ($rows as &$r) {
                $uuid = trim((string)($r['fel_uuid'] ?? ''));

                // Si viene vac├¡o o como bandera (1/0), lo anulamos
                if ($uuid === '' || $uuid === '0' || $uuid === '1') {
                    $uuid = null;
                }

                $r['fel_uuid'] = $uuid;

                // Alias extra por si el front usa uuid como en emitidas
                $r['uuid'] = $uuid;

                // Casts seguros
                $r['amount'] = (float)($r['amount'] ?? 0);
                $r['used_monthly'] = (int)($r['used_monthly'] ?? 0);
                $r['send_to_fel']  = (int)($r['send_to_fel'] ?? 0);
                $r['status'] = strtoupper((string)($r['status'] ?? $r['fel_status'] ?? ''));
            }
            unset($r);

            echo json_encode([
                'ok'   => true,
                'rows' => $rows,      // ­ƒæê lo que usa el front
                'data' => $rows,      // opcional: compatibilidad
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'manualInvoiceList: ' . $e->getMessage(),
            ]);
        }
    }

    public function manualInvoicePdf(): void
    {
        $id = trim((string)($_GET['id'] ?? ''));

        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'manualInvoicePdf_start',
            'id_qs' => $id,
        ]);

        if ($id === '' || !ctype_digit($id)) {
            http_response_code(400);
            echo "id requerido";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_bad_id',
                'id_qs' => $id
            ]);
            return;
        }

        $db = $this->db();

        // 1) Intentar obtener UUID desde tabla manual_invoices
        $uuid = '';
        try {
            $stmt = $db->prepare("
                SELECT 
                    fel_uuid AS uuid1,
                    uuid     AS uuid2
                FROM manual_invoices
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            if ($row) {
                $uuid = trim((string)($row['uuid1'] ?? $row['uuid2'] ?? ''));
            }
        } catch (\Throwable $e) {
            // si no existe tabla/manual_invoices, no rompemos; probamos otro lookup
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_lookup_manual_invoices_failed',
                'err'  => $e->getMessage()
            ]);
        }

        // 2) Fallback SOLO para buscar el UUID en invoices por manual_id (no pdf)
        if ($uuid === '') {
            $stmt2 = $db->prepare("
                SELECT uuid
                FROM invoices
                WHERE manual_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt2->execute([(int)$id]);
            $row2 = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($row2 && !empty($row2['uuid'])) {
                $uuid = trim((string)$row2['uuid']);
            }

            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_lookup_invoices_by_manual_id',
                'found' => (bool)$row2,
                'uuid'  => $uuid ?: null
            ]);
        }

        if ($uuid === '') {
            http_response_code(404);
            echo "No se encontr├│ UUID para esa factura manual";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_missing_uuid',
                'id'   => $id
            ]);
            return;
        }

        // 3) SOLO G4S
        $pdfBase64 = $this->fetchG4sPdfByUuid($uuid);

        if (!$pdfBase64) {
            http_response_code(404);
            echo "No se pudo obtener PDF de G4S";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_g4s_failed_no_fallback',
                'uuid' => $uuid
            ]);
            return;
        }

        $pdfBinary = base64_decode($pdfBase64, true);
        if ($pdfBinary === false || strlen($pdfBinary) < 1000) {
            http_response_code(500);
            echo "PDF inv├ílido desde G4S";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'manualInvoicePdf_decode_failed',
                'uuid' => $uuid,
                'decoded_ok' => $pdfBinary !== false,
                'decoded_len' => $pdfBinary !== false ? strlen($pdfBinary) : 0,
            ]);
            return;
        }

        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'manualInvoicePdf_serving_g4s_pdf',
            'uuid' => $uuid,
            'binary_len' => strlen($pdfBinary),
        ]);

        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename=\"manual_{$uuid}.pdf\"");
        header("Content-Length: " . strlen($pdfBinary));
        echo $pdfBinary;
    }

    private function getTicketErrorLogPath(): string
    {
        $cfg = (string)$this->config->get('TICKET_ERROR_LOG_FILE', '');
        if ($cfg !== '') {
            return $cfg;
        }

        // Ajusta si tu estructura es distinta:
        // dirname(__DIR__, 3) asumiendo: backend/src/App/Controllers/EstaClase.php
        $base = dirname(__DIR__, 3); // ÔåÆ backend
        return $base . '/storage/logs/ticket_import_errors.log';
    }

    private function loadErroredTicketNosFromLog(): array
    {
        $file = $this->getTicketErrorLogPath();
        if (!is_file($file)) {
            return [];
        }

        $fh = @fopen($file, 'r');
        if (!$fh) {
            return [];
        }

        $result = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $data = json_decode($line, true);
            if (!is_array($data)) continue;

            $tn = $data['ticket_no'] ?? null;
            if ($tn) {
                $result[$tn] = true;
            }
        }
        fclose($fh);

        return $result;
    }

    private function appendTicketErrorToLog(array $payload): void
    {
        $file = $this->getTicketErrorLogPath();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function payNotifyAgain(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $raw  = file_get_contents('php://input') ?: '{}';
            $body = json_decode($raw, true) ?: [];

            $ticketNo = trim((string)($body['ticket_no'] ?? ''));

            if ($ticketNo === '') {
                echo json_encode(['ok' => false, 'error' => 'ticket_no requerido'], JSON_UNESCAPED_UNICODE);
                return;
            }

            @date_default_timezone_set('America/Guatemala');
            $nowGT = new \DateTime('now', new \DateTimeZone('America/Guatemala'));

            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 1) Datos del ticket (para exit_at / placa / status)
            $q = $pdo->prepare("
                SELECT entry_at, exit_at, plate, status
                FROM tickets
                WHERE ticket_no = :t
                LIMIT 1
            ");
            $q->execute([':t' => $ticketNo]);
            $ticket = $q->fetch(\PDO::FETCH_ASSOC);

            if (!$ticket) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'El ticket no existe en la base de datos.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $exitAtStr    = $ticket['exit_at'] ?? null;
            $ticketPlate  = $ticket['plate']   ?? null;
            $ticketStatus = strtoupper((string)($ticket['status'] ?? ''));

            if (!$exitAtStr) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'El ticket no tiene hora de salida registrada (exit_at nulo).',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 2) Validar ventana de 5 minutos desde exit_at
            try {
                $exitAt = new \DateTime($exitAtStr, new \DateTimeZone('America/Guatemala'));
            } catch (\Throwable $e) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Formato de exit_at inv├ílido para el ticket.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $diffSec = $nowGT->getTimestamp() - $exitAt->getTimestamp();
            $diffMin = (int) floor($diffSec / 60);

            if ($diffSec < 0 || $diffMin > 5) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Fuera de la ventana de 5 minutos para reenviar payNotify.',
                    'exit_at' => $exitAt->format('Y-m-d H:i:s'),
                    'now_gt'  => $nowGT->format('Y-m-d H:i:s'),
                    'diff_min'=> $diffMin,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 3) Datos de payments (recordId / billin / placa)
            $payBillin   = 0.0;
            $payRecordId = '0';
            $payPlate    = $ticketPlate;

            try {
                $qp = $pdo->prepare("SELECT billin, billin_json, plate FROM payments WHERE ticket_no = :t LIMIT 1");
                $qp->execute([':t' => $ticketNo]);
                if ($pr = $qp->fetch(\PDO::FETCH_ASSOC)) {
                    if (isset($pr['billin']) && is_numeric($pr['billin'])) {
                        $payBillin = (float)$pr['billin'];
                    }
                    if (!empty($pr['billin_json'])) {
                        $payRecordId = (string)$pr['billin_json'];
                    }
                    if (!empty($pr['plate'])) {
                        $payPlate = $pr['plate'];
                    }
                }
            } catch (\Throwable $e) {
                // si falla, seguimos pero probablemente no haya recordId
            }

            // 4) Preparar payNotify (misma l├│gica que invoiceOne, pero sin FEL)
            $manualOpen     = false;
            $payNotifySent  = false;
            $payNotifyAck   = false;
            $payNotifyError = null;
            $payNotifyRaw   = null;
            $payNotifyType  = null;
            $payNotifyJson  = null;
            $payNotifyHttpCode = null;
            $payNotifyEndpoint = null;
            $payNotifyPayload  = null;

            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'HAMACHI_PARK_BASE_URL no est├í configurado.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $carNumber = $payPlate ?: $ticketPlate;
            $recordId  = $payRecordId;

            if ($recordId === '0' || $recordId === '' || $carNumber === '' || $carNumber === null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Faltan carNumber/recordId para payNotify (no se puede validar ticket en el sistema de parqueo).',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $payNotifyEndpoint = $baseUrl . '/api/v1/parkCost/payNotify';
            $accessToken = (string)($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            if ($accessToken !== '') {
                $payNotifyEndpoint .= (strpos($payNotifyEndpoint, '?') === false ? '?' : '&')
                                    . 'access_token=' . urlencode($accessToken);
            }

            $headers = ['Accept: application/json', 'Content-Type: application/json'];
            $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
            if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader;

            $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
            $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', ''));
            $paymentType = (string)$this->config->get('HAMACHI_PARK_PAYMENT_TYPE', 'cash');

            $notifyPayload = [
                'carNumber'   => $carNumber,
                'paymentType' => $paymentType,
                'recordId'    => $recordId,
            ];
            $payNotifyPayload = $notifyPayload;

            $cid = $this->newCorrelationId('paynotify-again');

            // === Llamada a payNotify (un intento, con logs) ===
            try {
                $ch = curl_init($payNotifyEndpoint);
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
                if ($connectTo !== '') curl_setopt($ch, CURLOPT_CONNECT_TO, [$connectTo]);

                $resp = curl_exec($ch);
                if ($resp === false) {
                    $curlErr = curl_error($ch) ?: 'Error desconocido';
                    $payNotifyError = 'No se pudo contactar al API de parking (payNotifyAgain). Detalle cURL: ' . $curlErr;
                    curl_close($ch);
                } else {
                    $info   = curl_getinfo($ch) ?: [];
                    $status = (int)($info['http_code'] ?? 0);
                    $hsize  = (int)($info['header_size'] ?? 0);
                    $bodyResp  = substr($resp, $hsize) ?: '';
                    $ctype = (string)($info['content_type'] ?? '');
                    curl_close($ch);

                    $payNotifyHttpCode = $status;
                    $payNotifyRaw      = $bodyResp;
                    $payNotifyType     = $ctype;

                    $payNotifySent = ($status >= 200 && $status < 300);

                    $looksJson = stripos($ctype, 'application/json') !== false
                            || (strlen($bodyResp) && ($bodyResp[0] === '{' || $bodyResp[0] === '['));
                    if ($looksJson) {
                        $tmp = json_decode($bodyResp, true);
                        if (json_last_error() === JSON_ERROR_NONE) $payNotifyJson = $tmp;
                    }

                    if ($payNotifySent && is_array($payNotifyJson)) {
                        $code = $payNotifyJson['code'] ?? null;
                        $payNotifyAck = ((int)$code === 0);
                        if (!$payNotifyAck) {
                            $msg = isset($payNotifyJson['message']) ? (string)$payNotifyJson['message'] : 'ACK inv├ílido';
                            $payNotifyError = 'API de parking respondi├│ pero no confirm├│ la validaci├│n del ticket: ' . $msg;
                        }
                    } elseif (!$payNotifySent) {
                        $payNotifyError = "El API de parking respondi├│ con HTTP $status (no se pudo validar el ticket).";
                    }
                }
            } catch (\Throwable $e) {
                $payNotifyError = 'Excepci├│n al llamar API de parking (payNotifyAgain): ' . $e->getMessage();
            }

            if (!$payNotifySent || !$payNotifyAck) {
                $manualOpen = true;
            }

            $this->debugLog('pay_notify_again_diag.txt', [
                'cid'                => $cid,
                'ticket_no'          => $ticketNo,
                'endpoint'           => $payNotifyEndpoint,
                'payload'            => $notifyPayload,
                'http_code'          => $payNotifyHttpCode,
                'error'              => $payNotifyError,
                'manual_open'        => $manualOpen,
                'sent'               => $payNotifySent,
                'ack'                => $payNotifyAck,
                'raw'                => $payNotifyRaw,
                'json'               => $payNotifyJson,
                'type'               => $payNotifyType,
                'exit_at'            => $exitAtStr,
                'now_gt'             => $nowGT->format('Y-m-d H:i:s'),
                'diff_min'           => $diffMin,
            ]);

            echo json_encode([
                'ok'                    => $payNotifySent && $payNotifyAck,
                'manual_open'           => $manualOpen,
                'pay_notify_sent'       => $payNotifySent,
                'pay_notify_ack'        => $payNotifyAck,
                'pay_notify_error'      => $payNotifyError,
                'pay_notify_http_code'  => $payNotifyHttpCode,
                'pay_notify_endpoint'   => $payNotifyEndpoint,
                'pay_notify_payload'    => $payNotifyPayload,
                'pay_notify_raw'        => $payNotifyRaw,
                'pay_notify_json'       => $payNotifyJson,
                'pay_notify_type'       => $payNotifyType,
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            $this->debugLog('pay_notify_again_exc.txt', [
                'exception' => $e->getMessage(),
            ]);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

}
