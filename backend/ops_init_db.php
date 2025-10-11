<?php
declare(strict_types=1);

use Config\Config;

require __DIR__ . '/config/autoload.php';

$base = __DIR__ . '/';
$config = new Config($base . '.env');
$driver = strtolower((string) $config->get('DB_CONNECTION', 'sqlite'));

switch ($driver) {
    case 'sqlite':
        $dbPath = (string) $config->get('DB_DATABASE', 'storage/sqlite/app.sqlite');
        if (!str_starts_with($dbPath, '/')) {
            $dbPath = $base . rtrim($dbPath, '/');
        }
        if (!is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0777, true);
        }
        $dsn = 'sqlite:' . $dbPath;
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $migrationDir = $base . 'database/migrations/sqlite';
        $seedDir = $base . 'database/seeds/sqlite';
        break;
    case 'mysql':
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_DATABASE', 'zkt')
        );
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }
        $pdo = new \PDO(
            $dsn,
            (string) $config->get('DB_USERNAME', 'root'),
            (string) $config->get('DB_PASSWORD', '')
        );
        foreach ($options as $opt => $value) {
            $pdo->setAttribute($opt, $value);
        }
        $migrationDir = $base . 'database/migrations/mysql';
        $seedDir = $base . 'database/seeds/mysql';
        break;
    default:
        throw new \RuntimeException("Unsupported DB_CONNECTION '{$driver}'. Use sqlite or mysql.");
}

runSqlDirectory($pdo, $migrationDir);
runSqlDirectory($pdo, $seedDir);

echo sprintf("Database initialized using '%s' driver.\n", $driver);

function runSqlDirectory(\PDO $pdo, string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $files = glob(rtrim($dir, '/') . '/*.sql');
    sort($files);
    foreach ($files as $file) {
        runSqlFile($pdo, $file);
    }
}

function runSqlFile(\PDO $pdo, string $file): void {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new \RuntimeException("Cannot read SQL file: {$file}");
    }

    $statements = splitSqlStatements($sql);
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function splitSqlStatements(string $sql): array {
    $clean = [];
    foreach (explode("\n", $sql) as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
}
