<?php
declare(strict_types=1);

use Config\Config;
use App\Utils\DB;
use App\Utils\Schema;

require __DIR__ . '/config/autoload.php';

$config = new Config(__DIR__ . '/.env');
$pdo = DB::pdo($config);
Schema::ensureInvoiceMetadataColumns($pdo);

echo "Invoice metadata columns verified/created successfully.\n";
