<?php
$dir = __DIR__ . '/../logs';

if (!is_dir($dir)) {
    die("La carpeta de logs no existe.");
}

$files = scandir($dir);
echo "<h2>Logs disponibles</h2><ul>";

foreach ($files as $file) {
    if ($file === "." || $file === "..") continue;
    echo "<li><a href='logs.php?file=$file' target='_blank'>$file</a></li>";
}

echo "</ul>";

if (isset($_GET['file'])) {
    $filePath = $dir . '/' . basename($_GET['file']);
    if (file_exists($filePath)) {
        echo "<h3>Mostrando: " . htmlspecialchars($_GET['file']) . "</h3>";
        echo "<pre style='white-space:pre-wrap;background:#f0f0f0;padding:10px;border-radius:5px;'>";
        echo htmlspecialchars(file_get_contents($filePath));
        echo "</pre>";
    } else {
        echo "Archivo no encontrado.";
    }
}
?>
