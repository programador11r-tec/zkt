// backend/scripts/validate_dte.php
<?php
$xmlPath = __DIR__ . '/../storage/last_dte.xml';
$xsdPath = __DIR__ . '/../storage/xsd/GT_Documento-0.2.0.xsd';

libxml_use_internal_errors(true);

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;

if (!$dom->load($xmlPath)) {
    foreach (libxml_get_errors() as $e) {
        echo "[LOAD] {$e->message}\n";
    }
    exit(1);
}

if (!$dom->schemaValidate($xsdPath)) {
    $errors = libxml_get_errors();
    foreach ($errors as $e) {
        echo "[XSD] line {$e->line}, col {$e->column}: {$e->message}\n";
    }
    exit(2);
}

echo "OK: XML válido contra XSD\n";
