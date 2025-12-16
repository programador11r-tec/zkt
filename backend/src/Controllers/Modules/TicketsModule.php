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
trait TicketsModule
{
    public function getTicketsFromDB() {
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            // ├â┬║ltimos 200 tickets (ejemplo)
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
                /* ­ƒæç NUEVO: placa desde payments o tickets */
                COALESCE(MAX(p.plate), t.plate) AS plate,
                NULL AS uuid,
                NULL AS estado
            FROM tickets t
            LEFT JOIN payments p ON p.ticket_no = t.ticket_no
            WHERE t.status = 'OPEN'  -- OJO: si tu UI es para CLOSED, c├ímbialo a 'CLOSED'
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

            // === Estad├¡sticas ===
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

            // Filtro NIT (incluye CF/vac├¡o)
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
                    t.plate AS plate,
                    COALESCE(t.exit_at, t.entry_at, i.created_at) AS fecha,
                    i.total,
                    i.uuid,
                    i.discount_code,
                    i.discount_amount,
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
                if (isset($r['discount_amount'])) {
                    $r['discount_amount'] = (float)$r['discount_amount'];
                }

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

    public function reportsDeviceLogs(): void
    {
        // Mismo patr├│n que syncRemoteParkRecords, pero SOLO lee y devuelve JSON
        $cid = $this->newCorrelationId('devlogs');
        $t0  = microtime(true);

        try {
            @date_default_timezone_set('America/Guatemala');

            $from = trim((string)($_GET['from'] ?? ''));
            $to   = trim((string)($_GET['to']   ?? ''));

            if ($from === '' || $to === '') {
                \App\Utils\Http::json([
                    'ok'    => false,
                    'error' => 'Par├ímetros "from" y "to" son requeridos (YYYY-MM-DD).',
                ], 400);
                return;
            }

            // Rango de d├¡a completo
            $beginTime = $from . ' 00:00:00';
            $endTime   = $to   . ' 23:59:59';

            // Device SN: puede venir por querystring, si no se usa el por defecto
            $deviceSn = trim((string)($_GET['deviceSn'] ?? $_GET['device_sn'] ?? ''));
            if ($deviceSn === '') {
                // valor por defecto (el que ya usabas)
                $deviceSn = 'TDBD244800158';
            }

            // === BASE URL desde Hamachi (igual que syncRemoteParkRecords) ===
            $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
            if ($baseUrl === '') {
                \App\Utils\Http::json([
                    'ok'    => false,
                    'error' => 'HAMACHI_PARK_BASE_URL no est├í configurado.',
                ], 400);
                return;
            }

            // Token opcional, mismo patr├│n que syncRemoteParkRecords
            $accessToken = (string) ($_GET['access_token'] ?? $this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));

            // Paginaci├│n opcional
            $pageNo   = (int) ($_GET['pageNo'] ?? $_GET['page'] ?? 1);
            if ($pageNo < 1) $pageNo = 1;
            $pageSize = (int) ($_GET['pageSize'] ?? $_GET['limit'] ?? 25);
            if ($pageSize <= 0) $pageSize = 25;
            $pageSize = min($pageSize, 500); // suficiente para log

            // Query EXACTO que usa el API de CVSecurity
            $query = [
                'deviceSn'  => $deviceSn,
                'pageNo'    => $pageNo,
                'pageSize'  => $pageSize,
                'beginTime' => $beginTime,
                'endTime'   => $endTime,
            ];
            if ($accessToken !== '') {
                $query['access_token'] = $accessToken;
            }

            $endpoint = $baseUrl . '/api/v3/transaction/device?' . http_build_query($query);

            $headers = ['Accept: application/json'];
            $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
            if ($hostHeader !== '') {
                $headers[] = 'Host: ' . $hostHeader;
            }

            $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';

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

            // === MUY IMPORTANTE: CONNECT_TO (igual que syncRemoteParkRecords) ===
            $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', '')); // ej: "localhost:8098:25.21.54.208:8098"
            if ($connectTo !== '') {
                curl_setopt($ch, CURLOPT_CONNECT_TO, [$connectTo]);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err  = curl_error($ch) ?: 'Error desconocido';
                $info = curl_getinfo($ch) ?: [];
                curl_close($ch);

                \App\Utils.Http::json([
                    'ok'    => false,
                    'error' => 'No se pudo contactar al API biom├®trico: ' . $err,
                    'info'  => $info,
                ], 500);
                return;
            }

            $info   = curl_getinfo($ch) ?: [];
            $status = (int)($info['http_code'] ?? 0);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                \App\Utils\Http::json([
                    'ok'        => false,
                    'error'     => 'HTTP ' . $status . ' desde API biom├®trico',
                    'preview'   => substr($raw, 0, 500),
                    'http_code' => $status,
                    'endpoint'  => $endpoint,
                ], 500);
                return;
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                \App\Utils\Http::json([
                    'ok'        => false,
                    'error'     => 'Respuesta del API biom├®trico no es JSON v├ílido.',
                    'raw'       => substr($raw, 0, 500),
                    'http_code' => $status,
                ], 500);
                return;
            }

            if ((int)($payload['code'] ?? -1) !== 0) {
                \App\Utils\Http::json([
                    'ok'     => false,
                    'error'  => $payload['message'] ?? 'API biom├®trico devolvi├│ error.',
                    'remote' => $payload,
                ], 200);
                return;
            }

            $dataBlock = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $rowsRaw   = is_array($dataBlock['data'] ?? null) ? $dataBlock['data'] : [];

            $rows = array_map(function (array $r): array {
                return [
                    'eventTime'      => $r['eventTime']      ?? null,
                    'logId'          => $r['logId']          ?? null,
                    'id'             => $r['id']             ?? null,
                    'pin'            => $r['pin']            ?? null,
                    'name'           => $r['name']           ?? null,
                    'lastName'       => $r['lastName']       ?? null,
                    'verifyModeName' => $r['verifyModeName'] ?? null,
                    'areaName'       => $r['areaName']       ?? null,
                    'devName'        => $r['devName']        ?? null,
                    'eventName'      => $r['eventName']      ?? null,
                    'eventPointName' => $r['eventPointName'] ?? null,
                    'doorName'       => $r['doorName']       ?? null,
                    'readerName'     => $r['readerName']     ?? null,
                    'accZone'        => $r['accZone']        ?? null,
                ];
            }, $rowsRaw);

            \App\Utils\Http::json([
                'ok'   => true,
                'rows' => $rows,
                'meta' => [
                    'deviceSn'    => $deviceSn,
                    'total'       => (int)($dataBlock['total'] ?? count($rows)),
                    'page'        => (int)($dataBlock['page']  ?? 0),
                    'size'        => (int)($dataBlock['size']  ?? count($rows)),
                    'http_code'   => $status,
                    'endpoint'    => $endpoint,
                    'duration_ms' => $this->msSince($t0),
                    'cid'         => $cid,
                ],
            ]);

        } catch (\Throwable $e) {
            \App\Utils\Http::json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
