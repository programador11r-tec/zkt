<?php
declare(strict_types=1);

namespace App\Controllers\Modules;

use App\Utils\DB;
use App\Utils\Http;
use App\Utils\Schema;
use App\Utils\Logger;
use PDO;

trait DiscountModule
{
    public function createDiscountVoucher(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $raw  = file_get_contents('php://input') ?: '{}';
            $body = json_decode($raw, true) ?: [];

            $amount      = isset($body['amount']) ? (float)$body['amount'] : 0.0;
            $description = trim((string)($body['description'] ?? ''));
            $quantity    = isset($body['quantity']) ? (int)$body['quantity'] : 1;

            if ($amount <= 0) {
                Http::json(['ok' => false, 'error' => 'El monto del descuento debe ser mayor a 0'], 400);
                return;
            }
            if ($quantity < 1 || $quantity > 50) {
                Http::json(['ok' => false, 'error' => 'La cantidad debe estar entre 1 y 50'], 400);
                return;
            }

            $pdo = DB::pdo($this->config);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            Schema::ensureDiscountVoucherSchema($pdo);

            $batchId = $this->generateBatchId();
            $now     = (new \DateTime('now'))->format('Y-m-d H:i:s');

            $insert = $pdo->prepare("
                INSERT INTO discount_vouchers (batch_id, code, amount, description, status, created_at, updated_at)
                VALUES (:batch_id, :code, :amount, :description, 'NEW', :created_at, :created_at)
            ");

            $items = [];
            for ($i = 0; $i < $quantity; $i++) {
                $code = $this->generateDiscountCode();
                $insert->execute([
                    ':batch_id'    => $batchId,
                    ':code'        => $code,
                    ':amount'      => $amount,
                    ':description' => $description !== '' ? $description : null,
                    ':created_at'  => $now,
                ]);
                $items[] = [
                    'code'        => $code,
                    'amount'      => $amount,
                    'description' => $description,
                    'status'      => 'NEW',
                    'batch_id'    => $batchId,
                    'created_at'  => $now,
                ];
            }

            Http::json([
                'ok'       => true,
                'batch_id' => $batchId,
                'items'    => $items,
            ]);
        } catch (\Throwable $e) {
            Logger::error('discount.create.failed', ['error' => $e->getMessage()]);
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function listDiscountVouchers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $pdo = DB::pdo($this->config);
            Schema::ensureDiscountVoucherSchema($pdo);
            $status = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : null;
            $batch  = isset($_GET['batch_id']) ? trim((string)$_GET['batch_id']) : null;

            $sql = "SELECT id, batch_id, code, amount, description, status, redeemed_ticket, redeemed_at, created_at, updated_at
                    FROM discount_vouchers ";
            $params = [];
            $conds = [];
            if ($status && in_array($status, ['NEW', 'REDEEMED', 'VOID'], true)) {
                $conds[] = "status = :st";
                $params[':st'] = $status;
            }
            if ($batch !== null && $batch !== '') {
                $conds[] = "batch_id = :b";
                $params[':b'] = $batch;
            }
            if ($conds) {
                $sql .= "WHERE " . implode(' AND ', $conds) . " ";
            }
            $sql .= "ORDER BY created_at DESC LIMIT 200";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Http::json(['ok' => true, 'rows' => $rows]);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function listDiscountBatches(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $pdo = DB::pdo($this->config);
            Schema::ensureDiscountVoucherSchema($pdo);
            $stmt = $pdo->query("
                SELECT batch_id,
                       MAX(description) AS description,
                       MAX(amount) AS amount,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status='NEW' THEN 1 ELSE 0 END) AS new_count,
                       SUM(CASE WHEN status='REDEEMED' THEN 1 ELSE 0 END) AS redeemed_count,
                       MIN(created_at) AS first_created,
                       MAX(created_at) AS last_created
                FROM discount_vouchers
                GROUP BY batch_id
                ORDER BY last_created DESC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            Http::json(['ok' => true, 'rows' => $rows]);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function reprintDiscountVoucher(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $raw  = file_get_contents('php://input') ?: '{}';
            $body = json_decode($raw, true) ?: [];
            $code = trim((string)($body['code'] ?? ''));

            if ($code === '') {
                Http::json(['ok' => false, 'error' => 'Envía code para imprimir'], 400);
                return;
            }

            $pdo = DB::pdo($this->config);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            Schema::ensureDiscountVoucherSchema($pdo);

            $stmt = $pdo->prepare("SELECT batch_id, amount, description, code, status, created_at FROM discount_vouchers WHERE code = :c LIMIT 1");
            $stmt->execute([':c' => $code]);

            $src = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                Http::json(['ok' => false, 'error' => 'Cupón no encontrado'], 404);
                return;
            }

            Http::json([
                'ok'   => true,
                'item' => [
                    'code'        => $src['code'],
                    'amount'      => (float)$src['amount'],
                    'description' => $src['description'] ?? '',
                    'status'      => $src['status'] ?? 'NEW',
                    'batch_id'    => $src['batch_id'],
                    'created_at'  => $src['created_at'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Logger::error('discount.reprint.failed', ['error' => $e->getMessage()]);
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function lookupDiscountVoucher(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $code = trim((string)($_GET['code'] ?? ''));
            if ($code === '') {
                Http::json(['ok' => false, 'error' => 'code requerido'], 400);
                return;
            }

            $pdo = DB::pdo($this->config);
            Schema::ensureDiscountVoucherSchema($pdo);

            $stmt = $pdo->prepare("SELECT code, amount, description, status, redeemed_ticket, redeemed_at FROM discount_vouchers WHERE code = :c LIMIT 1");
            $stmt->execute([':c' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Http::json(['ok' => false, 'error' => 'Cupón no encontrado'], 404);
                return;
            }

            Http::json([
                'ok' => true,
                'code' => $row['code'],
                'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                'description' => $row['description'] ?? '',
                'status' => $row['status'],
                'redeemed_ticket' => $row['redeemed_ticket'] ?? null,
                'redeemed_at' => $row['redeemed_at'] ?? null,
                'can_redeem' => strtoupper((string)$row['status']) === 'NEW',
            ]);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function prepareDiscountForInvoice(PDO $pdo, string $code): array
    {
        Schema::ensureDiscountVoucherSchema($pdo);
        $stmt = $pdo->prepare("SELECT code, amount, description, status FROM discount_vouchers WHERE code = :c LIMIT 1");
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('El descuento no existe.');
        }
        if (strtoupper((string)$row['status']) !== 'NEW') {
            throw new \RuntimeException('El descuento ya fue usado o no está disponible.');
        }
        $row['amount'] = isset($row['amount']) ? (float)$row['amount'] : 0.0;
        return $row;
    }

    private function redeemDiscountVoucher(PDO $pdo, string $code, string $ticketNo): bool
    {
        $stmt = $pdo->prepare("
            UPDATE discount_vouchers
            SET status = 'REDEEMED',
                redeemed_ticket = :t,
                redeemed_at = :at,
                updated_at = :at
            WHERE code = :c AND status = 'NEW'
        ");
        $now = (new \DateTime('now'))->format('Y-m-d H:i:s');
        $stmt->execute([
            ':t' => $ticketNo,
            ':at'=> $now,
            ':c' => $code,
        ]);
        return $stmt->rowCount() > 0;
    }

    private function generateDiscountCode(): string
    {
        return 'DV-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function generateBatchId(): string
    {
        return 'BATCH-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
