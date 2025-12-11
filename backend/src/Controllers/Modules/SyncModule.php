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
trait SyncModule
{
    public function syncRemoteParkRecords() {
        $cid = $this->newCorrelationId('sync');
        $t0  = microtime(true);

        try {
            //Logger::info('park.sync.start', ['cid' => $cid]);

            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') {
                //Logger::error('park.sync.no_base_url', ['cid' => $cid]);
                Http::json(['ok' => false, 'error' => 'HAMACHI_PARK_BASE_URL no est├í configurado.'], 400);
                return;
            }

            $accessToken = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
            if ($accessToken === '') {
                //Logger::warning('park.sync.no_token', ['cid' => $cid]);
                // sigue si tu API no exige token
            }

            $pageNo   = (int) ($_GET['pageNo'] ?? $_GET['page'] ?? 1);
            if ($pageNo < 1) $pageNo = 1;
            $pageSize = (int) ($_GET['pageSize'] ?? $_GET['limit'] ?? 25);
            if ($pageSize <= 0) $pageSize = 25;
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
                throw new \RuntimeException('API remota respondi├│ ' . $status . ': ' . $preview);
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                //Logger::error('park.sync.non_json', ['cid' => $cid, 'preview' => substr($raw, 0, 300)]);
                throw new \RuntimeException('Respuesta remota inv├ílida, no es JSON.');
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
                    'message'  => 'La API remota no devolvi├│ registros.',
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

    private function persistTickets(PDO $pdo, array $rows): int
    {
        if (!$rows) return 0;

        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        // Columnas disponibles en tickets
        $columns      = $this->getTableColumns($pdo, 'tickets');
        $hasSource    = isset($columns['source']);
        $hasRawJson   = isset($columns['raw_json']);
        $hasUpdatedAt = isset($columns['updated_at']);

        // --- PREPARED: existencia en tickets ---
        $existsSql  = 'SELECT 1 FROM tickets WHERE ticket_no = :ticket_no LIMIT 1';
        $existsStmt = $pdo->prepare($existsSql);

        // --- Cargar tickets que ya dieron error desde el log ---
        $erroredTicketNos = $this->loadErroredTicketNosFromLog(); // [ticket_no => true]

        // --- PREPARED: insert puro en tickets ---
        $insertColumns = ['ticket_no','plate','status','entry_at','exit_at','duration_min','amount'];
        $placeholders  = [':ticket_no',':plate',':status',':entry_at',':exit_at',':duration_min',':amount'];

        if ($hasSource)    { $insertColumns[] = 'source';     $placeholders[] = ':source'; }
        if ($hasRawJson)   { $insertColumns[] = 'raw_json';   $placeholders[] = ':raw_json'; }
        if ($hasUpdatedAt) { $insertColumns[] = 'updated_at'; $placeholders[] = ':updated_at'; }

        $insertSql  = sprintf(
            'INSERT INTO tickets (%s) VALUES (%s)',
            implode(',', $insertColumns),
            implode(',', $placeholders)
        );
        $insertStmt = $pdo->prepare($insertSql);

        $inserted = 0;
        $idx = 0;

        foreach ($rows as $rowRaw) {
            $idx++;
            $cid = $this->newCorrelationId('tkt');
            $t0  = microtime(true);

            $row = is_array($rowRaw) ? $this->normalizeParkRecordRow($rowRaw) : null;
            if ($row === null) {
                Logger::warning('tickets.normalize.skip', [
                    'cid'            => $cid,
                    'i'              => $idx,
                    'rowRaw_preview' => substr(json_encode($rowRaw), 0, 300),
                ]);

                // Tambi├®n guardamos en el archivo de errores
                $this->appendTicketErrorToLog([
                    'ticket_no'    => $rowRaw['ticket_no'] ?? '',
                    'plate'        => $rowRaw['plate'] ?? null,
                    'error'        => 'Normalizaci├│n fallida (row nulo).',
                    'raw_json'     => json_encode($rowRaw),
                    'created_at'   => (new \DateTime('now'))->format('Y-m-d H:i:s'),
                ]);

                continue;
            }

            $ticketNo = $row['ticket_no'] ?? null;
            $plate    = $row['plate'] ?? null;

            if ($ticketNo === null || $ticketNo === '') {
                Logger::warning('tickets.skip.no_ticket_no', [
                    'cid' => $cid,
                    'i'   => $idx,
                    'row' => substr(json_encode($row), 0, 300),
                ]);
                continue;
            }

            // 0) ┬┐Este ticket ya est├í en el log de errores? ÔåÆ no lo intentamos de nuevo
            if (isset($erroredTicketNos[$ticketNo])) {
                Logger::info('tickets.skip.already_in_error_log', [
                    'cid'       => $cid,
                    'i'         => $idx,
                    'ticket_no' => $ticketNo,
                    'elapsed_ms'=> $this->msSince($t0),
                ]);
                continue;
            }

            // 1) ┬┐Existe ya en tickets?
            $existsStmt->execute([':ticket_no' => $ticketNo]);
            $exists = (bool) $existsStmt->fetchColumn();

            if ($exists) {
                // NO insertar / NO actualizar / NO billing: solo saltar
                /*Logger::info('tickets.skip.exists', [
                    'cid'        => $cid,
                    'i'          => $idx,
                    'ticket_no'  => $ticketNo,
                    'elapsed_ms' => $this->msSince($t0),
                ]);*/
                continue;
            }

            // 2) Insert puro en tickets
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
            if ($hasRawJson)   { $params[':raw_json']  = $row['raw_json'] ?? json_encode($rowRaw); }
            if ($hasUpdatedAt) {
                $params[':updated_at'] = (new \DateTime('now'))->format('Y-m-d H:i:s');
            }

            try {
                $insertStmt->execute($params);
                $inserted++;

                // 3) BILLING: solo para nuevos inserts
                $b0 = microtime(true);
                try {
                    $billingEnabled = strtolower((string)$this->config->get('BILLING_ENABLED','true')) === 'true';
                    if ($billingEnabled) {
                        $this->ensurePaymentStub($pdo, $row);
                    }
                } catch (\Throwable $e) {
                    Logger::error('billing.ensure.failed', [
                        'cid'        => $cid,
                        'ticket_no'  => $ticketNo,
                        'error'      => $e->getMessage(),
                        'elapsed_ms' => $this->msSince($b0),
                    ]);
                }

            } catch (\Throwable $e) {
                // Si falla el INSERT en tickets, lo mandamos al archivo de log
                Logger::error('tickets.insert.failed', [
                    'cid'        => $cid,
                    'i'          => $idx,
                    'ticket_no'  => $ticketNo,
                    'error'      => $e->getMessage(),
                    'elapsed_ms' => $this->msSince($t0),
                ]);

                $this->appendTicketErrorToLog([
                    'ticket_no'  => $ticketNo,
                    'plate'      => $plate,
                    'error'      => $e->getMessage(),
                    'raw_json'   => json_encode($rowRaw),
                    'created_at' => (new \DateTime('now'))->format('Y-m-d H:i:s'),
                ]);

                // Lo agregamos tambi├®n al array en memoria para esta corrida
                $erroredTicketNos[$ticketNo] = true;

                // Pasamos al siguiente ticket sin romper todo el proceso
                continue;
            }
        }

        return $inserted; // cantidad realmente insertada
    }

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

            // ┬┐Existe ya payment?
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

            // Si no es success ÔåÆ recordIdRef = "0"
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
                    ':billin_json' => $recordIdRef, // ÔåÉ SOLO recordId o "0"
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

                // billin_json ÔåÆ guarda SOLO recordId o "0"
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

}
