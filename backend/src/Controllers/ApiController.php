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

    public function health() {
        Http::json(['ok' => true, 'time' => date('c')]);
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

            // total
            $st = $pdo->prepare("
                SELECT t.ticket_no, COALESCE(SUM(p.amount), t.amount, 0) AS total,
                    t.entry_at, t.exit_at
                FROM tickets t
                LEFT JOIN payments p ON p.ticket_no = t.ticket_no
                WHERE t.ticket_no = :t
            ");
            $st->execute([':t'=>$ticketNo]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new \RuntimeException('Ticket no encontrado');
            $total = (float)($row['total'] ?? 0);
            if ($total <= 0) throw new \RuntimeException('Total 0 para facturar');

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
                        ['descripcion'=>'Ticket de parqueo', 'cantidad'=>1, 'precio'=>$total, 'iva'=>0, 'total'=>$total]
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

    public function ingestTicket(){
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            $b = \App\Utils\Http::body();

            // Validación mínima
            $ticket_no = trim((string)($b['ticket_no'] ?? ''));
            if ($ticket_no === '') throw new \InvalidArgumentException('ticket_no requerido');

            $plate   = $b['plate'] ?? null;
            $status  = $b['status'] ?? 'OPEN'; // OPEN|CLOSED
            $entry   = $b['entry_at'] ?? null; // ISO
            $exit    = $b['exit_at']  ?? null; // ISO
            $durMin  = isset($b['duration_min']) ? (int)$b['duration_min'] : null;
            $amount  = isset($b['amount']) ? (float)$b['amount'] : 0;

            $sql = "
            INSERT INTO tickets (ticket_no, plate, status, entry_at, exit_at, duration_min, amount, created_at)
            VALUES (:ticket_no,:plate,:status,:entry_at,:exit_at,:duration_min,:amount, datetime('now'))
            ON CONFLICT(ticket_no) DO UPDATE SET
            plate=excluded.plate, status=excluded.status, entry_at=excluded.entry_at,
            exit_at=excluded.exit_at, duration_min=excluded.duration_min, amount=excluded.amount";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':ticket_no'=>$ticket_no, ':plate'=>$plate, ':status'=>$status,
                ':entry_at'=>$entry, ':exit_at'=>$exit, ':duration_min'=>$durMin, ':amount'=>$amount
            ]);

            \App\Utils\Http::json(['ok'=>true,'ticket_no'=>$ticket_no]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    public function ingestPayment(){
        try {
            $pdo = \App\Utils\DB::pdo($this->config);
            $b = \App\Utils\Http::body();

            $ticket_no = trim((string)($b['ticket_no'] ?? ''));
            if ($ticket_no === '') throw new \InvalidArgumentException('ticket_no requerido');

            $amount = (float)($b['amount'] ?? 0);
            if ($amount <= 0) throw new \InvalidArgumentException('amount > 0 requerido');

            $method = $b['method'] ?? null;
            $paidAt = $b['paid_at'] ?? date('c');

            $st = $pdo->prepare("INSERT INTO payments (ticket_no, amount, method, paid_at, created_at)
                                VALUES (:ticket_no,:amount,:method,:paid_at, datetime('now'))");
            $st->execute([
                ':ticket_no'=>$ticket_no, ':amount'=>$amount, ':method'=>$method, ':paid_at'=>$paidAt
            ]);

            \App\Utils\Http::json(['ok'=>true,'ticket_no'=>$ticket_no,'amount'=>$amount]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 400);
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
                t.receptor_nit
            ORDER BY fecha DESC
            LIMIT 500
            ";

            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            \App\Utils\Http::json(['ok'=>true,'rows'=>$rows]);
        } catch (\Throwable $e) {
            \App\Utils\Http::json(['ok'=>false,'error'=>$e->getMessage()], 500);
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



}

    