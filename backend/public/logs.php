<?php
// Ruta REAL de tus logs en el droplet:
$dir = '/var/www/zkt/backend/storage/logs';

if (!is_dir($dir)) {
    http_response_code(500);
    die("La carpeta de logs no existe: " . htmlspecialchars($dir));
}

$files = array_values(array_filter(scandir($dir), function($f) use ($dir) {
    return $f !== '.' && $f !== '..' && is_file($dir . '/' . $f);
}));

echo "<h2>Logs disponibles</h2><ul>";
foreach ($files as $file) {
    $safe = urlencode($file);
    echo "<li><a href='?file=$safe'>$file</a></li>";
}
echo "</ul>";

if (isset($_GET['file'])) {
    $name = basename($_GET['file']); // evita path traversal
    $filePath = $dir . '/' . $name;

    if (!is_file($filePath)) {
        http_response_code(404);
        die("Archivo no encontrado.");
    }

    echo "<h3>Mostrando: " . htmlspecialchars($name) . "</h3>";
    echo "<pre style='white-space:pre-wrap;background:#111;color:#0f0;padding:12px;border-radius:8px;'>";
    echo htmlspecialchars(file_get_contents($filePath));
    echo "</pre>";
}
