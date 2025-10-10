<?php
// Initialize SQLite DB from migrations and seeds
$base = __DIR__ . '/../';
$dbPath = $base . 'storage/sqlite/app.sqlite';
@mkdir(dirname($dbPath), 0777, true);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrations = glob($base . 'database/migrations/*.sql');
foreach ($migrations as $file) {
  $db->exec(file_get_contents($file));
}
$seeds = glob($base . 'database/seeds/*.sql');
foreach ($seeds as $file) {
  $db->exec(file_get_contents($file));
}
echo "DB initialized at $dbPath\n";
