<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;

class Schema {
    /** @var array<int, bool> */
    private static array $invoiceSchemaEnsured = [];
    /** @var array<int, bool> */
    private static array $discountSchemaEnsured = [];

    public static function ensureInvoiceMetadataColumns(PDO $pdo): void {
        $hash = spl_object_id($pdo);
        if (isset(self::$invoiceSchemaEnsured[$hash])) {
            return;
        }
        self::$invoiceSchemaEnsured[$hash] = true;

        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        $columns = [];
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info('invoices')");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (isset($row['name'])) {
                            $columns[strtolower((string) $row['name'])] = true;
                        }
                    }
                }
            } else {
                $stmt = $pdo->query('SHOW COLUMNS FROM invoices');
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
            Logger::error('invoice.schema.inspect_failed', ['error' => $e->getMessage()]);
            return;
        }

        $definitions = [
            'receptor_nit' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(32) NULL',
            'entry_at' => 'DATETIME NULL',
            'exit_at' => 'DATETIME NULL',
            'duration_min' => $driver === 'sqlite' ? 'INTEGER NULL' : 'INT NULL',
            'hours_billed' => $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(8,2) NULL',
            'billing_mode' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(32) NULL',
            'hourly_rate' => $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(12,2) NULL',
            'monthly_rate' => $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(12,2) NULL',
            'discount_code' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(64) NULL',
            'discount_amount' => $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(12,2) NULL',
        ];

        foreach ($definitions as $column => $definition) {
            if (!isset($columns[$column])) {
                try {
                    $pdo->exec("ALTER TABLE invoices ADD COLUMN {$column} {$definition}");
                } catch (\Throwable $e) {
                    Logger::error('invoice.schema.alter_failed', [
                        'column' => $column,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public static function ensureDiscountVoucherSchema(PDO $pdo): void {
        $hash = spl_object_id($pdo);
        if (isset(self::$discountSchemaEnsured[$hash])) {
            return;
        }
        self::$discountSchemaEnsured[$hash] = true;

        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        // Crear tabla discount_vouchers si no existe
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS discount_vouchers (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        batch_id TEXT NOT NULL,
                        code TEXT NOT NULL UNIQUE,
                        amount REAL NOT NULL,
                        description TEXT NULL,
                        status TEXT NOT NULL DEFAULT 'NEW',
                        redeemed_ticket TEXT NULL,
                        redeemed_at DATETIME NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_discount_batch ON discount_vouchers(batch_id);");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_discount_status ON discount_vouchers(status);");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS discount_vouchers (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        batch_id VARCHAR(64) NOT NULL,
                        code VARCHAR(64) NOT NULL UNIQUE,
                        amount DECIMAL(12,2) NOT NULL,
                        description VARCHAR(255) NULL,
                        status VARCHAR(16) NOT NULL DEFAULT 'NEW',
                        redeemed_ticket VARCHAR(64) NULL,
                        redeemed_at DATETIME NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_discount_batch (batch_id),
                        INDEX idx_discount_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            }
        } catch (\Throwable $e) {
            Logger::error('discount.schema.create_failed', ['error' => $e->getMessage()]);
        }

        // Asegurar columnas de descuento en invoices
        self::ensureInvoiceMetadataColumns($pdo);
    }
}
