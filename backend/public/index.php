<?php

declare(strict_types=1);

// Front controller
require __DIR__ . '/../config/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);


use App\Utils\Router;
use App\Controllers\ApiController;
use App\Controllers\AuthController;   // ← IMPORTANTE
use App\Utils\Auth;                   // ← IMPORTANTE
use App\Services\G4SClient;
use Config\Config;

$router = new Router();
$api    = new ApiController();

// Restringir rutas si el usuario autenticado es rol "caseta"
$currentUser = \App\Utils\Auth::currentUser();
if ($currentUser && (($currentUser['role'] ?? null) === 'caseta')) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path   = rtrim($path, '/') ?: '/';

    // Solo aplicamos la restricción a rutas API; los assets y el frontend pasan libres
    if (str_starts_with($path, '/api')) {
        // Solo dashboard y facturación (lectura + PDFs FEL) para caseta
        $allowedCaseta = [
            'GET /api/auth/me',
            'POST /api/auth/ping',
            'POST /api/auth/logout',
            'GET /api/health',
            'GET /api/settings',
            'GET /api/tickets',
            'GET /api/facturacion/list',
            'GET /api/facturacion/emitidas',
            'GET /api/fel/pdf',
        'GET /api/fel/xml',
        'GET /api/fel/invoice/pdf',
        'GET /api/fel/document-pdf',
        'GET /api/g4s/lookup-nit',
        // Permit auto-sync desde el dashboard para caseta
        'POST /api/sync/park-records/hamachi',
        'GET /api/reports/tickets',
        // Facturación completa para caseta
        'POST /api/fel/invoice',
        'GET /api/fel/invoice/status-sync',
        'GET /api/fel/manual-invoice/pdf',
        'GET /api/fel/manual-invoice/one',
        'GET /api/fel/manual-invoice-list',
            'GET /api/fel/report-manual-invoice-list',
            'POST /api/fel/manual-invoice',
            'POST /api/invoice/tickets',
        'GET /api/reports/manual-open',
        'GET /api/reports/device-logs',
        'POST /api/gate/manual-open',
        'GET /api/discounts/lookup',
    ];

        $key = $method . ' ' . $path;
        if (!in_array($key, $allowedCaseta, true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Acceso restringido para rol caseta'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/* ============ AUTH ============ */
$router->post('/api/auth/login', function() {
    (new AuthController())->login();
});
$router->get('/api/auth/me', function() {
    (new AuthController())->me();
});
$router->post('/api/auth/logout', function() {
    (new AuthController())->logout();
});

/* ============ API ============ */

// Salud (público si quieres que traiga el status sin login)
$router->get('/api/health', fn() => $api->health());
$router->get('/api/settings', fn() => $api->settingsOverview());
$router->post('/api/settings/hourly-rate', fn() => $api->updateHourlyRate());

// Dashboard (desde BD) — requiere sesión (admin o caseta)
$router->get('/api/tickets', function () use ($api) {
    Auth::requireAuth(); // cualquier usuario autenticado
    return $api->getTicketsFromDB();
});

// Facturación (desde BD + acciones)
$router->get('/api/facturacion/list', function () use ($api) {
    Auth::requireAuth();
    return $api->facturacionList();
});
$router->get('/api/facturacion/emitidas', function () use ($api) {
    Auth::requireAuth();
    return $api->facturacionEmitidas();
});
$router->get('/api/reports/tickets', function () use ($api) {
    Auth::requireAuth();
    return $api->reportsTickets();
});

// Emitir factura: SOLO ADMIN
$router->post('/api/fel/invoice', function () use ($api) {
    Auth::requireAuth();
    return $api->invoiceOne();
});

// Descargar PDF/XML de FEL: autenticado
$router->get('/api/fel/pdf', function () use ($api) {
    Auth::requireAuth();
    return $api->felPdf();
});
$router->get('/api/fel/xml', function () use ($api) {
    Auth::requireAuth();
    return $api->felXml();
});

// --- PDFs FEL ---
// 1) Por UUID directo (descarga desde G4S si hace falta)
$router->get('/api/fel/document-pdf', function () use ($api) {
    $api->getFelDocumentPdfByUuid(); // ?uuid=...
});

// 2) PDF de una manual_invoices por ID (lee BD o baja por UUID y guarda)
$router->get('/api/fel/manual-invoice/pdf', function () use ($api) {
    $api->getManualInvoicePdf(); // ?id=...
});

// 3) Una fila de manual_invoices (para leer fel_pdf_base64 si quieres)
$router->get('/api/fel/manual-invoice/one', function () use ($api) {
    $api->getManualInvoiceOne(); // ?id=...
});

// 🔹 NUEVO: Sincronizar estado FEL de una factura por UUID
// GET /api/fel/invoice/status-sync?uuid=...
$router->get('/api/fel/invoice/status-sync', function () {
    Auth::requireAuth();

    // Cargar configuración desde .env
    $cfg = new Config(__DIR__ . '/../.env');

    // Cliente G4S
    $client = new G4SClient($cfg);

    // Método que agregaste en G4SClient.php
    $client->syncInvoiceStatus();
});

// (Si aún usas estas) — normalmente SOLO ADMIN
$router->get('/api/sync/tickets', function () use ($api) {
     Auth::requireAuth();
    return $api->syncTicketsAndPayments();
});
$router->post('/api/sync/park-records/hamachi', function () use ($api) {
    Auth::requireAuth();
    return $api->syncRemoteParkRecords();
});
$router->post('/api/invoice/tickets', function () use ($api) {
     Auth::requireAuth();
    return $api->invoiceClosedTickets();
});
$router->get('/api/fel/manual-invoice-list', function () use ($api) {
     Auth::requireAuth();
    return $api->manualInvoiceList();
});
$router->get('/api/fel/report-manual-invoice-list', function () use ($api) {
     Auth::requireAuth();
    return $api->reportManualInvoiceList();
});
$router->post('/api/fel/manual-invoice', function () use ($api) {
    Auth::requireAuth();
    return $api->manualInvoiceCreate();
});

$router->get('/api/reports/manual-open', function () use ($api) {
    Auth::requireAuth();
    return $api->reportsManualOpen();   // nuevo método en ApiController
});

// === FEL PDFs ===
$router->get('/api/fel/invoice/pdf', function () use ($api) {
    $api->invoicePdf();
});

// Consulta NIT (G4S): autenticado (tú decides si solo admin)
$router->get('/api/g4s/lookup-nit', function () {
  Auth::requireAuth();

  header('Content-Type: application/json; charset=utf-8');

  $raw = $_GET['nit'] ?? '';
  $nit = preg_replace('/\D+/', '', $raw); // solo dígitos

  if ($nit === '' || strtoupper($raw) === 'CF') {
    echo json_encode(['ok' => true, 'nit' => 'CF', 'nombre' => null]);
    return;
  }

  try {
    $cfg = new \Config\Config(__DIR__ . '/../.env');
    $client = new \App\Services\G4SClient($cfg);
    $res = $client->g4sLookupNit($nit);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
});

// POST /api/gate/manual-open — normalmente caseta y admin
$router->post('/api/gate/manual-open', function () use ($api) {
    $u = Auth::requireAuth(); // ambos roles
    // Si quieres restringir SOLO caseta:
    // if ($u['role'] !== 'caseta') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Solo caseta']); return; }
    return $api->openGateManual();
});

// Reporte de registros del dispositivo biométrico
$router->get('/api/reports/device-logs', function () use ($api) {
    Auth::requireAuth();
    return $api->reportsDeviceLogs();   
});
$router->post('/api/discounts', function () use ($api) {
    Auth::requireAuth();
    return $api->createDiscountVoucher();
});
$router->post('/api/discounts/reprint', function () use ($api) {
    Auth::requireAuth();
    return $api->reprintDiscountVoucher();
});
$router->get('/api/discounts/batches', function () use ($api) {
    Auth::requireAuth();
    return $api->listDiscountBatches();
});
$router->get('/api/discounts', function () use ($api) {
    Auth::requireAuth();
    return $api->listDiscountVouchers();
});
$router->get('/api/discounts/lookup', function () use ($api) {
    Auth::requireAuth();
    return $api->lookupDiscountVoucher();
});

/* ============ FRONTEND ============ */

// Pantalla principal (app) — si no estás sirviendo estáticos con Apache/Nginx
$router->get('/', function () {
  $index = __DIR__ . '/index.html';
  if (file_exists($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return;
  }
  echo 'Frontend not found';
});

// Pantalla de login
$router->get('/login.html', function () {
  $file = __DIR__ . '/login.html';
  if (file_exists($file)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    return;
  }
  http_response_code(404);
  echo 'Login not found';
});

// Sirve el JS de login si no tienes servidor de estáticos
$router->get('/js/login.js', function () {
  $file = __DIR__ . '/js/login.js';
  if (file_exists($file)) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($file);
    return;
  }
  http_response_code(404);
  echo 'Asset not found';
});
// Mantener viva la sesión solo si ya está autenticado
$router->post('/api/auth/ping', function () {
    header('Content-Type: application/json; charset=utf-8');
    $ok = \App\Utils\Auth::touch(); // no crea sesión nueva, solo refresca si existe
    if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false, 'error'=>'No autenticado']); return; }
    echo json_encode(['ok'=>true]);
});

// === BYPASS defensivo para /api/ingest/tickets y /api/ingest/payments ===
$__method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__path   = rtrim($__path, '/');
if ($__path === '') $__path = '/';

// error_log("[ROUTER DEBUG] method=$__method path=$__path");

if ($__method === 'POST' && $__path === '/api/ingest/tickets')  { $api->ingestTickets();  return; }
if ($__method === 'POST' && $__path === '/api/ingest/payments') { $api->ingestPayments(); return; }

/* ============ DISPATCH ============ */

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
