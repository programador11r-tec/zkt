<?php
// backend/scripts/seed_users.php
declare(strict_types=1);

require __DIR__ . '/../config/autoload.php';

use App\Utils\DB;

$pdo = DB::connect();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','caseta') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$defaults = [
  ['username' => 'admin',  'password' => 'Admin$2025',  'role' => 'admin'],
  ['username' => 'caseta', 'password' => 'Caseta$2025', 'role' => 'caseta'],
];

foreach ($defaults as $u) {
    // skip if exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $u['username']]);
    if ($stmt->fetchColumn()) {
        echo "[skip] {$u['username']} ya existe\n";
        continue;
    }

    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $ins  = $pdo->prepare("INSERT INTO users (username, password_hash, role, active) VALUES (:u, :p, :r, 1)");
    $ins->execute([':u' => $u['username'], ':p' => $hash, ':r' => $u['role']]);
    echo "[ok] usuario {$u['username']} creado\n";
}

echo "Listo.\n";
