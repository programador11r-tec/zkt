<?php

declare(strict_types=1);

// Front controller
require __DIR__ . '/../config/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

use App\Utils\Router;
use App\Controllers\ApiController;
use App\Controllers\AuthController;   // ← IMPORTANTE
use App\Utils\Auth;                   // ← IMPORTANTE
use App\Services\G4SClient;
use Config\Config;

$router = new Router();
$api    = new ApiController();

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
$router->post('/api/fel/manual-invoice', function () use ($api) {
    Auth::requireAuth();
    return $api->manualInvoiceCreate();
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
