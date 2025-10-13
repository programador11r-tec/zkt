<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;

class Schema {
    /** @var array<int, bool> */
    private static array $invoiceSchemaEnsured = [];

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
}
