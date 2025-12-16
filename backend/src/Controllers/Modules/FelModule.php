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
trait FelModule
{
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
            $discountCode = trim((string)($body['discount_code'] ?? ''));
            $discountAmountClient = isset($body['discount_amount_client']) ? (float)$body['discount_amount_client'] : null;

            $this->debugLog('fel_invoice_in.txt', ['body' => $body, 'server' => $_SERVER]);

            if ($ticketNo === '') {
                echo json_encode(['ok' => false, 'error' => 'ticket_no requerido']);
                return;
            }
            if ($mode === 'custom' && (!is_finite($customTotal) || $customTotal <= 0)) {
                echo json_encode(['ok' => false, 'error' => 'custom_total inv├ílido']);
                return;
            }
            if ($receptorNit !== 'CF' && !ctype_digit($receptorNit)) {
                echo json_encode(['ok' => false, 'error' => 'NIT inv├ílido (use CF o solo d├¡gitos)']);
                return;
            }

            $isGrace = ($mode === 'grace');

            // TZ
            @date_default_timezone_set('America/Guatemala');
            $phpTz   = @date_default_timezone_get();
            $phpNow  = date('Y-m-d H:i:s');
            $nowGT   = (new \DateTime('now', new \DateTimeZone('America/Guatemala')))->format('Y-m-d H:i:s');

            // C├ílculo ÔÇ£oficialÔÇØ del backend
            $calc = $this->resolveTicketAmount($ticketNo, $isGrace ? 'hourly' : $mode, $customTotal);
            $hours        = (float)($calc[0] ?? 0);
            $minutes      = (int)  ($calc[1] ?? 0);
            $totalBackend = $isGrace ? 0.00 : (float)($calc[2] ?? 0);
            $extra        = is_array($calc[3] ?? null) ? $calc[3] : [];

            $durationMinBackend = (int) max(0, $hours * 60 + $minutes);
            $hoursBilledBackend = (int) ceil($durationMinBackend / 60);
            $billingAmount      = $isGrace ? 0.00 : (isset($extra['billing_amount']) ? (float)$extra['billing_amount'] : $totalBackend);

            // Datos del cliente para hourly (opcional)
            $dmClient    = isset($body['duration_minutes']) ? (int)$body['duration_minutes'] : null;
            $hbClient    = isset($body['hours_billed_used']) ? (int)$body['hours_billed_used'] : null;
            $rateClient  = isset($body['hourly_rate_used']) ? (float)$body['hourly_rate_used'] : null;
            $totalClient = isset($body['total']) ? (float)$body['total'] : null;

            // Totales iniciales y valores a persistir (se ajustan m├ís abajo)
            $finalTotal          = $totalBackend;
            $durationMinPersist  = $durationMinBackend;
            $hoursBilledPersist  = $hoursBilledBackend;
            $discountInfo        = null;
            $discountApplied     = 0.0;

            if ($mode === 'custom') {
                $finalTotal    = round((float)$customTotal, 2);
                $billingAmount = $finalTotal;

            } elseif ($mode === 'hourly' && !$isGrace) {
                $clientHasMin  = is_finite($dmClient)   && $dmClient   !== null && $dmClient   > 0;
                $clientHasRate = is_finite($rateClient) && $rateClient !== null && $rateClient > 0;

                // Si el cliente env├¡a minutos + tarifa, confiar en su c├ílculo
                if ($clientHasMin && $clientHasRate) {
                    $hbUsed = ($hbClient && $hbClient > 0) ? (int)$hbClient : (int)ceil($dmClient / 60);
                    $clientComputed = round($hbUsed * $rateClient, 2);

                    $finalTotal         = $clientComputed;
                    $billingAmount      = $finalTotal;
                    $durationMinPersist = (int)$dmClient;
                    $hoursBilledPersist = (int)$hbUsed;
                    $extra['final_total_source'] = 'client';
                }
                // Si no env├¡a minutos/tarifa pero s├¡ un total, usar ese total
                elseif (is_finite($totalClient) && $totalClient > 0) {
                    $finalTotal         = round($totalClient, 2);
                    $billingAmount      = $finalTotal;
                    $durationMinPersist = $durationMinBackend;
                    $hoursBilledPersist = $hoursBilledBackend;
                    $extra['final_total_source'] = 'client_total';
                }
                // Respaldo: c├ílculo del backend
                else {
                    $finalTotal         = $totalBackend;
                    $billingAmount      = $finalTotal;
                    $durationMinPersist = $durationMinBackend;
                    $hoursBilledPersist = $hoursBilledBackend;
                    $extra['final_total_source'] = 'backend';
                }

                // trazas locales
                $extra['client_minutes']      = $clientHasMin ? (int)$dmClient : null;
                $extra['client_hours_billed'] = $hbClient ?? null;
                $extra['client_hourly_rate']  = $rateClient ?? null;
                $extra['client_total_sent']   = (is_finite($totalClient) && $totalClient > 0) ? round($totalClient,2) : null;
            }

            // DB / TZ
            $cfg = new \Config\Config(__DIR__ . '/../../.env');
            $pdo = \App\Utils\DB::pdo($this->config);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            Schema::ensureDiscountVoucherSchema($pdo);

            // Validar y aplicar descuento (valor fijo restado al total)
            if ($discountCode !== '') {
                try {
                    $discountInfo    = $this->prepareDiscountForInvoice($pdo, $discountCode);
                    $discountAmount  = isset($discountInfo['amount']) ? (float)$discountInfo['amount'] : 0.0;
                    $discountAmount  = max(0.0, $discountAmount);
                    $discountApplied = $discountAmount;

                    // Opcional: registrar diferencias con el monto enviado por el cliente
                    if (is_finite($discountAmountClient) && $discountAmountClient !== null) {
                        $extra['discount_client_amount'] = (float)$discountAmountClient;
                        if (abs($discountAmountClient - $discountAmount) > 0.01) {
                            $extra['discount_mismatch'] = [
                                'client' => (float)$discountAmountClient,
                                'server' => $discountAmount,
                            ];
                        }
                    }

                    if ($discountApplied > 0) {
                        $billingAmount = max(0.0, $billingAmount - $discountApplied);
                    }
                    $extra['discount_applied'] = $discountApplied;
                } catch (\Throwable $e) {
                    echo json_encode(['ok' => false, 'error' => 'Descuento no vƒ"¡lido: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            // Si el descuento resulta en 0 o menos, convertir a ticket de gracia automáticamente
            if ($discountCode !== '' && $discountApplied <= 0) {
                $isGrace = true;
                $mode = 'grace';
                $billingAmount = 0.0;
                $finalTotal = 0.0;
            }

            // Si el total a facturar quedó en cero, forzar modo gracia para evitar DTE con valor 0
            if (!$isGrace && $billingAmount <= 0) {
                $isGrace = true;
                $mode = 'grace';
                $billingAmount = 0.0;
                $finalTotal = 0.0;
            }

            $mysqlTzDiag = null;
            try {
                $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
                if ($drv === 'mysql') {
                    $pdo->exec("SET time_zone = '-06:00'");
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

            // ================== VALIDACI├ôN DEL TICKET ==================
            $entryAt = $exitAt = $plate = $ticketStatus = null;
            try {
                $q = $pdo->prepare("SELECT entry_at, exit_at, plate, status FROM tickets WHERE ticket_no = :t LIMIT 1");
                $q->execute([':t' => $ticketNo]);
                if ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
                    $entryAt      = $row['entry_at'] ?? null;
                    $exitAt       = $row['exit_at']  ?? null;
                    $plate        = $row['plate']    ?? null;
                    $ticketStatus = strtoupper((string)($row['status'] ?? ''));
                } else {
                    $this->debugLog('fel_invoice_ticket_not_found.txt', [
                        'ticket_no' => $ticketNo,
                        'mode'      => $mode,
                        'body'      => $body,
                    ]);
                    echo json_encode([
                        'ok'    => false,
                        'error' => 'El ticket no existe en la base de datos. Verifique el n├║mero antes de facturar.',
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }
            } catch (\Throwable $e) {
                $this->debugLog('fel_invoice_ticket_query_err.txt', [
                    'ticket_no' => $ticketNo,
                    'mode'      => $mode,
                    'exception' => $e->getMessage(),
                ]);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Error al consultar el ticket en la base de datos: ' . $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Si el ticket ya est├í cerrado, marcamos el error de validaci├│n
            if ($ticketStatus === 'CLOSED') {
                $this->debugLog('fel_invoice_ticket_closed.txt', [
                    'ticket_no'     => $ticketNo,
                    'mode'          => $mode,
                    'ticket_status' => $ticketStatus,
                ]);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'El ticket ya fue cerrado/facturado previamente. No se puede usar para abrir de nuevo.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Tasas registradas
            $hourlyRate  = is_numeric($extra['hourly_rate']  ?? null) ? (float)$extra['hourly_rate']
                        : (is_finite($rateClient) ? (float)$rateClient : 0.00);
            $monthlyRate = is_numeric($extra['monthly_rate'] ?? null) ? (float)$extra['monthly_rate'] : 0.00;

            // ================== FEL (solo si NO es gracia) ==================
            $felOk = false;
            $uuid  = null;
            $felRes = null;
            $felErr = null;
            $pdfBase64 = null;

            // (opcional) para payNotify despu├®s
            $payBillin   = 0.0;
            $payRecordId = '0';
            $payPlate    = $plate;

            if (!$isGrace) {
                // 1) Enviar a FEL
                $clientFel = new \App\Services\G4SClient($cfg);

                $payloadFel = [
                    'ticket_no'    => $ticketNo,
                    'receptor_nit' => $receptorNit,
                    'total'        => round($billingAmount, 2),
                    'hours'        => $hours,
                    'minutes'      => $minutes,
                    'mode'         => $mode,
                ];

                $felRes = $clientFel->submitInvoice($payloadFel);
                $this->debugLog('fel_invoice_out.txt', [
                    'request_payload' => $payloadFel,
                    'g4s_response'    => $felRes,
                    'extra'           => $extra,
                ]);

                $felOk  = (bool)($felRes['ok'] ?? false);
                $uuid   = $felRes['uuid']  ?? null;
                $felErr = $felRes['error'] ?? null;

                // 2) Intentar obtener PDF
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

                // 3) Persistir invoice + PDF
                $pdo->beginTransaction();
                try { $this->ensureInvoicePdfColumn($pdo); } catch (\Throwable $e) {}

                $status = $felOk ? 'CERTIFIED' : 'FAILED';

                $stmt = $pdo->prepare("
                    INSERT INTO invoices
                    (
                        ticket_no, total, uuid, status,
                        request_json, response_json, created_at,
                        receptor_nit, entry_at, exit_at,
                        duration_min, hours_billed, billing_mode,
                        hourly_rate, monthly_rate, discount_code, discount_amount
                    )
                    VALUES
                    (
                        :ticket_no, :total, :uuid, :status,
                        :request_json, :response_json, :created_at,
                        :receptor_nit, :entry_at, :exit_at,
                        :duration_min, :hours_billed, :billing_mode,
                        :hourly_rate, :monthly_rate, :discount_code, :discount_amount
                    )
                ");
                $stmt->execute([
                    ':ticket_no'     => $ticketNo,
                    ':total'         => round($billingAmount, 2),
                    ':uuid'          => $uuid,
                    ':status'        => $status,
                    ':request_json'  => json_encode($body, JSON_UNESCAPED_UNICODE),
                    ':response_json' => json_encode($felRes, JSON_UNESCAPED_UNICODE),
                    ':created_at'    => $nowGT,
                    ':receptor_nit'  => $receptorNit,
                    ':entry_at'      => $entryAt,
                    ':exit_at'       => $exitAt,
                    ':duration_min'  => $durationMinPersist,
                    ':hours_billed'  => $hoursBilledPersist,
                    ':billing_mode'  => $mode,
                    ':hourly_rate'   => $hourlyRate,
                    ':monthly_rate'  => $monthlyRate,
                    ':discount_code'   => $discountCode !== '' ? $discountCode : null,
                    ':discount_amount' => ($discountInfo && $discountApplied > 0) ? $discountApplied : null,
                ]);

                if ($pdfBase64) {
                    try {
                        $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
                        if ($drv === 'mysql') {
                            $pdo->exec("UPDATE invoices SET pdf_base64 = " . $pdo->quote($pdfBase64) . " WHERE ticket_no = " . $pdo->quote($ticketNo) . " LIMIT 1");
                        } else {
                            $pdo->exec("UPDATE invoices SET pdf_base64 = " . $pdo->quote($pdfBase64) . " WHERE ticket_no = " . $pdo->quote($ticketNo));
                        }
                    } catch (\Throwable $e) {}
                }

                // marca ticket cerrado
                $up = $pdo->prepare("UPDATE tickets SET status = 'CLOSED', exit_at = COALESCE(exit_at, :now_exit) WHERE ticket_no = :t");
                $up->execute([':now_exit' => $nowGT, ':t' => $ticketNo]);

                // Redimir descuento solo si FEL se concretó
                if ($discountInfo && $discountCode !== '' && $felOk) {
                    $redeemed = $this->redeemDiscountVoucher($pdo, $discountCode, $ticketNo);
                    if (!$redeemed) {
                        throw new \RuntimeException('No se pudo marcar el descuento como usado.');
                    }
                }

                $pdo->commit();

                // 4) Recupera datos de payments para payNotify
                try {
                    $qp = $pdo->prepare("SELECT billin, billin_json, plate FROM payments WHERE ticket_no = :t LIMIT 1");
                    $qp->execute([':t' => $ticketNo]);
                    if ($pr = $qp->fetch(\PDO::FETCH_ASSOC)) {
                        if (isset($pr['billin']) && is_numeric($pr['billin'])) $payBillin = (float)$pr['billin'];
                        if (!empty($pr['billin_json'])) $payRecordId = (string)$pr['billin_json'];
                        if (!empty($pr['plate'])) $payPlate = $pr['plate'];
                    }
                } catch (\Throwable $e) {}
            } else {
                // GRACE: registra sin FEL
                $pdo->beginTransaction();
                try { $this->ensureInvoicePdfColumn($pdo); } catch (\Throwable $e) {}

                $stmt = $pdo->prepare("
                    INSERT INTO invoices
                    (
                        ticket_no, total, uuid, status,
                        request_json, response_json, created_at,
                        receptor_nit, entry_at, exit_at,
                        duration_min, hours_billed, billing_mode,
                        hourly_rate, monthly_rate, discount_code, discount_amount
                    )
                    VALUES
                    (
                        :ticket_no, :total, :uuid, :status,
                        :request_json, :response_json, :created_at,
                        :receptor_nit, :entry_at, :exit_at,
                        :duration_min, :hours_billed, :billing_mode,
                        :hourly_rate, :monthly_rate, :discount_code, :discount_amount
                    )
                ");
                $stmt->execute([
                    ':ticket_no'     => $ticketNo,
                    ':total'         => 0.00,
                    ':uuid'          => null,
                    ':status'        => 'GRATIS',
                    ':request_json'  => json_encode($body, JSON_UNESCAPED_UNICODE),
                    ':response_json' => json_encode(['ok'=>true,'note'=>'no FEL (grace)'], JSON_UNESCAPED_UNICODE),
                    ':created_at'    => $nowGT,
                    ':receptor_nit'  => $receptorNit,
                    ':entry_at'      => $entryAt,
                    ':exit_at'       => $exitAt,
                    ':duration_min'  => $durationMinPersist,
                    ':hours_billed'  => $hoursBilledPersist,
                    ':billing_mode'  => 'grace',
                    ':hourly_rate'   => $hourlyRate,
                    ':monthly_rate'  => $monthlyRate,
                    ':discount_code'   => $discountCode !== '' ? $discountCode : null,
                    ':discount_amount' => ($discountInfo && $discountApplied > 0) ? $discountApplied : null,
                ]);

                $up = $pdo->prepare("UPDATE tickets SET status = 'CLOSED', exit_at = COALESCE(exit_at, :now_exit) WHERE ticket_no = :t");
                $up->execute([':now_exit' => $nowGT, ':t' => $ticketNo]);

                $pdo->commit();

                if ($discountInfo && $discountCode !== '') {
                    $redeemed = $this->redeemDiscountVoucher($pdo, $discountCode, $ticketNo);
                    if (!$redeemed) {
                        throw new \RuntimeException('No se pudo marcar el descuento como usado.');
                    }
                }
            }
            // ================== /FEL ==================

            // ==== PayNotify / Apertura ====
            $manualOpen     = false;
            $payNotifySent  = false;
            $payNotifyAck   = false;
            $payNotifyError = null;
            $payNotifyRaw   = null;
            $payNotifyType  = null;

            // Ô£à extra para devolver detalle
            $payNotifyHttpCode = null;
            $payNotifyEndpoint = null;
            $payNotifyPayload  = null;
            $payNotifyJson     = null;

            $effectiveBilling = $isGrace ? 0.0 : round((($payBillin > 0) ? $payBillin : $billingAmount), 2);

            if ($isGrace) {
                // ====== GRACE: abrir por canal de salida ======
                $reason = 'ticket de gracia';
                $channelId = '40288048981adc4601981b7cb2660b05';

                $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
                if ($baseUrl === '') {
                    $payNotifyError = 'HAMACHI_PARK_BASE_URL no est├í configurado.';
                    $manualOpen = true;
                } else {
                    $accessToken   = (string) ($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
                    $tokenQueryKey = (string) ($this->config->get('HAMACHI_PARK_TOKEN_QUERY_KEY', 'access_token'));

                    $query = ['channelId' => $channelId];
                    if ($accessToken !== '') $query[$tokenQueryKey] = $accessToken;

                    $payNotifyEndpoint = $baseUrl . '/api/v1/parkBase/openGateChannel?' . http_build_query($query);

                    $headers = ['Accept: application/json', 'Content-Type: application/json; charset=utf-8', 'Expect:'];
                    $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
                    if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader;

                    $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
                    $connectTo = trim((string)$this->config->get('HAMACHI_PARK_CONNECT_TO', ''));

                    try {
                        $ch = curl_init($payNotifyEndpoint);
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
                            CURLOPT_HEADER         => true,
                        ];
                        if (!$verifySsl) { $opts[CURLOPT_SSL_VERIFYPEER]=false; $opts[CURLOPT_SSL_VERIFYHOST]=false; }
                        if ($connectTo !== '') { $opts[CURLOPT_CONNECT_TO] = [$connectTo]; }
                        curl_setopt_array($ch, $opts);

                        $resp = curl_exec($ch);
                        if ($resp === false) {
                            $curlErr = curl_error($ch) ?: 'Error desconocido';
                            $payNotifyError = 'No se pudo contactar al API de parking (grace). Detalle cURL: ' . $curlErr;
                            curl_close($ch);
                            $manualOpen = true;
                        } else {
                            $info   = curl_getinfo($ch) ?: [];
                            $status = (int)($info['http_code'] ?? 0);
                            $hsize  = (int)($info['header_size'] ?? 0);
                            $bodyResp = substr($resp, $hsize) ?: '';
                            $ctype = (string)($info['content_type'] ?? '');
                            curl_close($ch);

                            $payNotifyHttpCode = $status;
                            $payNotifyRaw      = $bodyResp;
                            $payNotifyType     = $ctype;

                            $json = json_decode($bodyResp, true);
                            if (json_last_error() === JSON_ERROR_NONE) $payNotifyJson = $json;

                            $code    = $json['code'] ?? ($json['ret'] ?? ($json['status'] ?? null));
                            $message = $json['message'] ?? ($json['msg'] ?? ($json['detail'] ?? null));
                            $okGate  = ($status === 200) && (
                                $code === 0 || $code === '0' ||
                                (is_string($message) && preg_match('/^success$/i', $message)) ||
                                ($json['success'] ?? false) === true
                            );

                            $manualOpen = !$okGate;
                            if (!$okGate) {
                                $payNotifyError = 'Apertura (grace) fall├│. HTTP ' . $status . ' Mensaje: ' . ($message ?? 'sin detalle');
                            }
                        }
                    } catch (\Throwable $e) {
                        $payNotifyError = 'Excepci├│n al llamar API de parking (grace): ' . $e->getMessage();
                        $manualOpen = true;
                    }
                }

            } else {
                // ====== NO GRACE: payNotify con reintentos (hasta 3) ======
                $shouldNotify = ($felOk && $effectiveBilling > 0);

                if ($shouldNotify) {
                    $cid = $this->newCorrelationId('paynotify');

                    $baseUrl = rtrim((string) $this->config->get('HAMACHI_PARK_BASE_URL', ''), '/');
                    if ($baseUrl === '') {
                        $payNotifyError = 'HAMACHI_PARK_BASE_URL no est├í configurado.';
                        $manualOpen = true;
                    } else {
                        $carNumber = $payPlate ?: ($plate ?: ($extra['plate'] ?? ''));
                        $recordId  = $payRecordId;

                        if ($recordId === '0' || $recordId === '' || $carNumber === '') {
                            $payNotifyError = 'Faltan carNumber/recordId para payNotify (no se puede validar ticket en el sistema de parqueo).';
                            $manualOpen = true;
                        } else {
                            $payNotifyEndpoint = $baseUrl . '/api/v1/parkCost/payNotify';
                            $accessToken = (string)($this->config->get('HAMACHI_PARK_ACCESS_TOKEN', ''));
                            if ($accessToken !== '') {
                                $payNotifyEndpoint .= (strpos($payNotifyEndpoint, '?') === false ? '?' : '&') . 'access_token=' . urlencode($accessToken);
                            }

                            $headers = ['Accept: application/json', 'Content-Type: application/json'];
                            $hostHeader = trim((string) $this->config->get('HAMACHI_PARK_HOST_HEADER', ''));
                            if ($hostHeader !== '') $headers[] = 'Host: ' . $hostHeader;

                            $verifySsl = strtolower((string) $this->config->get('HAMACHI_PARK_VERIFY_SSL', 'false')) === 'true';
                            $connectTo = trim((string) $this->config->get('HAMACHI_PARK_CONNECT_TO', ''));

                            $paymentType = (string)$this->config->get('HAMACHI_PARK_PAYMENT_TYPE', 'cash');

                            $notifyPayload = [
                                'carNumber'   => $carNumber,
                                'paymentType' => $paymentType,
                                'recordId'    => $recordId,
                            ];

                            // Ô£à guardar payload para devolverlo
                            $payNotifyPayload = $notifyPayload;

                            // === NUEVO: estructura para registrar intentos ===
                            $payNotifyAttempts = [];

                            // helper interno para un intento de payNotify
                            $doPayNotifyOnce = function(int $attemptNo) use (
                                $payNotifyEndpoint, $headers, $notifyPayload,
                                $verifySsl, $connectTo,
                                &$payNotifyAttempts
                            ) {
                                $sent   = false;
                                $ack    = false;
                                $error  = null;
                                $http   = null;
                                $raw    = null;
                                $type   = null;
                                $json   = null;

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
                                        $error = 'No se pudo contactar al API de parking (payNotify, intento ' . $attemptNo . '). Detalle cURL: ' . $curlErr;
                                        curl_close($ch);
                                    } else {
                                        $info   = curl_getinfo($ch) ?: [];
                                        $status = (int)($info['http_code'] ?? 0);
                                        $hsize  = (int)($info['header_size'] ?? 0);
                                        $bodyResp  = substr($resp, $hsize) ?: '';
                                        $ctype = (string)($info['content_type'] ?? '');
                                        curl_close($ch);

                                        $http = $status;
                                        $raw  = $bodyResp;
                                        $type = $ctype;

                                        $sent = ($status >= 200 && $status < 300);

                                        $looksJson = stripos($ctype, 'application/json') !== false
                                                || (strlen($bodyResp) && ($bodyResp[0] === '{' || $bodyResp[0] === '['));
                                        if ($looksJson) {
                                            $tmp = json_decode($bodyResp, true);
                                            if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
                                        }

                                        if ($sent && is_array($json)) {
                                            $ack = (isset($json['code']) && (int)$json['code'] === 0);
                                            if (!$ack) {
                                                $msg = isset($json['message']) ? (string)$json['message'] : 'ACK inv├ílido';
                                                $error = 'API de parking respondi├│ pero no confirm├│ la validaci├│n del ticket (intento '.$attemptNo.'): ' . $msg;
                                            }
                                        } elseif (!$sent) {
                                            $error = "El API de parking respondi├│ con HTTP $status (no se pudo validar el ticket, intento $attemptNo).";
                                        }
                                    }

                                } catch (\Throwable $e) {
                                    $error = 'Excepci├│n al llamar API de parking (payNotify, intento '.$attemptNo.'): ' . $e->getMessage();
                                }

                                $attempt = [
                                    'attempt'   => $attemptNo,
                                    'sent'      => $sent,
                                    'ack'       => $ack,
                                    'http_code' => $http,
                                    'error'     => $error,
                                    'raw'       => $raw,
                                    'type'      => $type,
                                    'json'      => $json,
                                ];
                                $payNotifyAttempts[] = $attempt;

                                return $attempt;
                            };

                            // === INTENTO 1 (si falla, NO reintentamos) ===
                            $attempt1 = $doPayNotifyOnce(1);

                            // resumen principal SIEMPRE basado en el primer intento
                            $payNotifySent      = (bool)$attempt1['sent'];
                            $payNotifyAck       = (bool)$attempt1['ack'];
                            $payNotifyError     = $attempt1['error'];
                            $payNotifyHttpCode  = $attempt1['http_code'];
                            $payNotifyRaw       = $attempt1['raw'];
                            $payNotifyType      = $attempt1['type'];
                            $payNotifyJson      = $attempt1['json'];

                            // manual_open solo seg├║n resultado del primer intento
                            if (!$payNotifySent || !$payNotifyAck) {
                                $manualOpen = true;
                            }

                            // === INTENTO 2: SOLO si el primero fue OK ===
                            if ($payNotifySent && $payNotifyAck) {
                                $attempt2 = $doPayNotifyOnce(2);

                                $ok2 = $attempt2['sent'] && $attempt2['ack'];

                                // Si el segundo intento falla, NO hacemos nada m├ís:
                                //  - NO intentamos una 3┬¬ vez
                                //  - NO cambiamos la notificaci├│n (se queda la del 1er intento)
                                if ($ok2) {
                                    // === INTENTO 3: SOLO si el 2.┬║ tambi├®n fue OK ===
                                    $attempt3 = $doPayNotifyOnce(3);
                                    // El resultado del 3er intento solo se registra en logs.
                                }
                            }

                            // log de paynotify con correlaci├│n + todos los intentos
                            $this->debugLog('pay_notify_diag.txt', [
                                'cid'                => $cid,
                                'ticket_no'          => $ticketNo,
                                'endpoint'           => $payNotifyEndpoint,
                                'payload'            => $notifyPayload,

                                // resumen (1er intento)
                                'summary_http_code'  => $payNotifyHttpCode,
                                'summary_error'      => $payNotifyError,
                                'summary_manual_open'=> $manualOpen,
                                'summary_sent'       => $payNotifySent,
                                'summary_ack'        => $payNotifyAck,
                                'summary_raw'        => $payNotifyRaw,
                                'summary_json'       => $payNotifyJson,

                                // detalle de todos los intentos
                                'attempts'           => $payNotifyAttempts,
                            ]);
                        }
                    }
                }
            }

            echo json_encode([
                'ok'               => $isGrace ? true : $felOk,
                'uuid'             => $uuid,
                'message'          => $isGrace ? 'Ticket de gracia registrado (sin FEL)' : ($felOk ? 'Factura certificada' : 'No se pudo certificar (registrada en BD)'),
                'error'            => $isGrace ? null : $felErr,
                'billing_amount'   => $isGrace ? 0.00 : ($payBillin > 0 ? $payBillin : (float)$billingAmount),
                'discount'         => [
                    'code'   => $discountCode !== '' ? $discountCode : null,
                    'amount' => ($discountInfo && $discountApplied > 0) ? $discountApplied : 0.0,
                ],
                'manual_open'      => $manualOpen,

                // PayNotify resumen
                'pay_notify_sent'  => $payNotifySent,
                'pay_notify_ack'   => $payNotifyAck,
                'pay_notify_error' => $payNotifyError,

                // Ô£à PayNotify detalle (lo que pas├│)
                'pay_notify_http_code' => $payNotifyHttpCode,
                'pay_notify_endpoint'  => $payNotifyEndpoint,
                'pay_notify_payload'   => $payNotifyPayload,
                'pay_notify_raw'       => $payNotifyRaw,
                'pay_notify_json'      => $payNotifyJson,
                'pay_notify_type'      => $payNotifyType,

                'has_pdf_base64'   => (bool)$pdfBase64,

                'tz' => [
                    'php_timezone' => $phpTz,
                    'php_now'      => $phpNow,
                    'now_gt'       => $nowGT,
                    'mysql'        => $mysqlTzDiag,
                ],
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $this->debugLog('fel_invoice_exc.txt', ['exception' => $e->getMessage(), 'ticket_no' => $ticketNo ?? null]);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function ensureInvoicePdfColumn(\PDO $pdo): void
    {
        $drv = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));

        if ($drv === 'mysql') {
            // ┬┐Existe la columna?
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
                // log y seguir (no romper flujo de facturaci├│n por esto)
                $this->debugLog('ensure_col_generic_err.txt', ['driver'=>$drv, 'error'=>$e2->getMessage()]);
            }
        }
    }

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

    private function resolveTicketAmount(string $ticketNo, string $mode, ?float $customTotal): array
    {
        if ($mode === 'custom') {
            return [null, null, (float)$customTotal];
        }
        // Aqu├â┬¡ usa tu c├â┬ílculo que ya corrigimos con ├óÔé¼┼ôceil a horas exactas├óÔé¼┬Ø.
        // Hardcode simple de ejemplo:
        return [2, 0, 60.00];
    }

    public function felPdf() {
        try {
            $uuid = $_GET['uuid'] ?? '';
            if ($uuid === '') throw new \InvalidArgumentException('uuid requerido');
            $g4s  = new \App\Services\G4SClient($this->config);

            // PDF binario como base64 o bytes seg├â┬║n proveedor; aqu├â┬¡ asumimos base64 en Response.Data
            $respStr = $g4s->requestTransaction([
                'Transaction' => 'GET_DOCUMENT_SAT_PDF',
                'Data1'       => $uuid,  // UUID
                'Data2'       => 'PDF',  // tipo
            ]);
            $resp = is_string($respStr) ? json_decode($respStr, true) : $respStr;
            $b64  = $resp['Response']['Data'] ?? $resp['Data'] ?? null;

            if (!$b64) throw new \RuntimeException('PDF no disponible en respuesta G4S');
            $bin = base64_decode($b64, true);
            if ($bin === false) throw new \RuntimeException('PDF inv├ílido');

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
            if ($xml === false) throw new \RuntimeException('XML inv├ílido');

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
            $exit = new \DateTimeImmutable('now', $tz); // ├óÔé¼┼ôahora├óÔé¼┬Ø en misma TZ
        }

        // Diferencia en segundos (no negativa)
        $diffSec = max(0, $exit->getTimestamp() - $entry->getTimestamp());

        // === Regla pedida ===
        // - Cobro por hora redondeando hacia arriba (ceil), respecto al tiempo real.
        // - M├â┬¡nimo 1 hora si hubo estancia (>0 segundos).
        // Ejemplos que se cumplen:
        //  5:14 ├óÔÇáÔÇÖ 6:16  = 1h 02m => ceil(3720/3600)=2h
        //  5:14 ├óÔÇáÔÇÖ 7:13  = 1h 59m => ceil(7140/3600)=2h
        //  5:14 ├óÔÇáÔÇÖ 7:14  = 2h 00m => ceil(7200/3600)=2h
        //  5:14 ├óÔÇáÔÇÖ 7:15  = 2h 01m => ceil(7260/3600)=3h
        $billedHours = 0.0;
        $durationMin = null;

        if ($diffSec > 0) {
            if ($enforceMinimumHour) {
                $billedHours = (float) ceil($diffSec / 3600);
                if ($billedHours < 1.0) $billedHours = 1.0;
                $durationMin = (int) ($billedHours * 60); // m├â┬║ltiplo exacto de 60
            } else {
                // sin forzar hora m├â┬¡nima: solo ceil a minutos
                $mins = (int) ceil($diffSec / 60.0);
                $durationMin = $mins > 0 ? $mins : null;
                $billedHours = $durationMin !== null ? ($durationMin / 60.0) : 0.0;
            }
        } else {
            // sin estancia
            if ($enforceMinimumHour) {
                // si quieres que 0s NO cobren, deja duraci├â┬│n en null y horas 0
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

    public function getFelDocumentPdfByUuid(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $uuid = trim((string)($_GET['uuid'] ?? ''));
            if ($uuid === '') { echo json_encode(['ok'=>false,'error'=>'uuid requerido']); return; }

            $cfg    = new \Config\Config(__DIR__ . '/../../.env');
            $client = new \App\Services\G4SClient($cfg);

            // intenta por orden m├ís ÔÇ£oficialÔÇØ
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

            // Si no hay PDF guardado pero s├¡ UUID y fue enviada a FEL con estado OK, intenta descargar y persistir
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

            // 3) Si sigue vac├¡o, intentar con XML almacenado en BD
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

            // Guardar en BD para la pr├│xima
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
                    // claves t├¡picas: xml, dte_xml, xml_base64
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
        // 2) Tags t├¡picos en tu captura: ResponseData3 / ResponseData2
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

    private function fetchG4sPdfByUuid(string $uuid): ?string
    {
        $requestor = '425C5714-AA9E-4212-B4AA-75BD70328030';
        $entity    = '81491514';
        $user      = '425C5714-AA9E-4212-B4AA-75BD70328030';
        $userName  = 'TEMP';
        $endpoint  = 'https://fel.g4sdocumenta.com/webservicefront/factwsfront.asmx';

        $xmlBody = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
        <soap:Body>
            <RequestTransaction xmlns="http://www.fact.com.mx/schema/ws">
            <Requestor>{$requestor}</Requestor>
            <Transaction>GET_DOCUMENT</Transaction>
            <Country>GT</Country>
            <Entity>{$entity}</Entity>
            <User>{$user}</User>
            <UserName>{$userName}</UserName>
            <Data1>{$uuid}</Data1>
            <Data2></Data2>
            <Data3>XML PDF</Data3>
            </RequestTransaction>
        </soap:Body>
        </soap:Envelope>
        XML;

        // LOG request
        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'request_build',
            'uuid' => $uuid,
            'endpoint' => $endpoint,
            'soap_action' => 'RequestTransaction',
            'xml_len' => strlen($xmlBody),
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "http://www.fact.com.mx/schema/ws/RequestTransaction"'
            ],
            CURLOPT_POSTFIELDS     => $xmlBody,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // LOG raw response status
        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'curl_result',
            'http_code' => $code,
            'curl_error' => $err ?: null,
            'response_len' => $response ? strlen($response) : 0,
            'response_head' => $response ? substr($response, 0, 400) : null,
        ]);

        if ($err || $code !== 200 || !$response) {
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'curl_failed',
                'reason' => $err ?: "HTTP $code o respuesta vac├¡a",
            ]);
            return null;
        }

        // ============================
        // 1) LIMPIEZA DEL XML
        // ============================
        $clean = $response;
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean);             // quitar BOM
        $clean = preg_replace('/[^\P{C}\t\n\r]+/u', '', $clean);          // quitar control chars

        // ============================
        // 2) PARSE ROBUSTO DOM (flags seguros)
        // ============================
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $flags = 0;
        if (defined('LIBXML_NONET'))      $flags |= \LIBXML_NONET;
        if (defined('LIBXML_NOERROR'))    $flags |= \LIBXML_NOERROR;
        if (defined('LIBXML_NOWARNING'))  $flags |= \LIBXML_NOWARNING;
        if (defined('LIBXML_RECOVER'))    $flags |= \LIBXML_RECOVER;     // <- puede no existir en tu PHP
        if (defined('LIBXML_PARSEHUGE'))  $flags |= \LIBXML_PARSEHUGE;   // <- igual

        $ok = $dom->loadXML($clean, $flags);

        if (!$ok) {
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'dom_parse_failed',
                'flags' => $flags,
                'errors' => array_map(
                    fn($e) => trim($e->message),
                    libxml_get_errors()
                )
            ]);
            libxml_clear_errors();
            return null;
        }
        libxml_clear_errors();

        // ============================
        // 3) BUSCAR ResponseData3
        // ============================
        $nodes = $dom->getElementsByTagName('ResponseData3');

        if (!$nodes || $nodes->length === 0) {
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'no_responseData3',
                'note' => 'No se encontr├│ etiqueta ResponseData3'
            ]);
            return null;
        }

        $pdfBase64 = trim((string)$nodes->item(0)->textContent);

        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'responseData3_found',
            'base64_len' => strlen($pdfBase64),
            'base64_head' => substr($pdfBase64, 0, 80),
        ]);

        if ($pdfBase64 === '') {
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'responseData3_empty'
            ]);
            return null;
        }

        // Validaci├│n r├ípida base64
        $decoded = base64_decode($pdfBase64, true);
        if ($decoded === false || strlen($decoded) < 1000) {
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'base64_invalid',
                'decoded_ok' => $decoded !== false,
                'decoded_len' => $decoded !== false ? strlen($decoded) : 0
            ]);
            return null;
        }

        return $pdfBase64;
    }

    public function invoicePdf(): void
    {
        $uuid = trim((string)($_GET['uuid'] ?? ''));

        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'invoicePdf_start',
            'uuid_qs' => $uuid,
            'ticket_no_qs' => trim((string)($_GET['ticket_no'] ?? '')),
        ]);

        if ($uuid === '') {
            http_response_code(400);
            echo "uuid requerido";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'missing_uuid',
                'message' => 'No vino uuid'
            ]);
            return;
        }

        $pdfBase64 = $this->fetchG4sPdfByUuid($uuid);

        if (!$pdfBase64) {
            http_response_code(404);
            echo "No se pudo obtener PDF de G4S";
            $this->debugLog('g4s_pdf_debug.txt', [
                'step' => 'g4s_failed_no_fallback',
                'uuid' => $uuid
            ]);
            return;
        }

        $pdfBinary = base64_decode($pdfBase64);

        $this->debugLog('g4s_pdf_debug.txt', [
            'step' => 'serving_g4s_pdf',
            'uuid' => $uuid,
            'binary_len' => strlen($pdfBinary),
        ]);

        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename=\"factura_{$uuid}.pdf\"");
        header("Content-Length: " . strlen($pdfBinary));
        echo $pdfBinary;
    }

}
