<?php

declare(strict_types=1);

// Front controller
require __DIR__ . '/../config/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

use App\Utils\Router;
use App\Controllers\ApiController;
use App\Services\G4SClient;
use Config\Config; // <— este es el tipo que pide el constructor

$router = new Router();
$api    = new ApiController();


/* ================= API ================= */

// Salud
$router->get('/api/health', fn() => $api->health());
$router->get('/api/settings', fn() => $api->settingsOverview());
$router->post('/api/settings/hourly-rate', fn() => $api->updateHourlyRate());

// Ingesta desde ZKBio / externos (PLURAL y métodos existentes)
$router->post('/api/ingest/tickets',  fn() => $api->ingestTickets());
$router->post('/api/ingest/payments', fn() => $api->ingestPayments());
$router->post('/api/ingest/bulk',     fn() => $api->ingestBulk());
// NUEVO: merge server-side
$router->post('/api/ingest/zkbio-merge', fn() => $api->ingestZKBioMerge());

// Dashboard (desde BD)
$router->get('/api/tickets', fn() => $api->getTicketsFromDB());

// Facturación (desde BD + acciones)
$router->get('/api/facturacion/list',     fn() => $api->facturacionList());
$router->get('/api/facturacion/emitidas', fn() => $api->facturacionEmitidas());
$router->get('/api/reports/tickets',      fn() => $api->reportsTickets());
$router->post('/api/fel/invoice',         fn() => $api->invoiceOne());
$router->get('/api/fel/pdf',              fn() => $api->felPdf());
$router->get('/api/fel/xml',              fn() => $api->felXml());

// (Si aún usas estas)
$router->get('/api/sync/tickets',  fn() => $api->syncTicketsAndPayments());
$router->post('/api/sync/park-records/hamachi', fn() => $api->syncRemoteParkRecords());
$router->post('/api/invoice/tickets', fn() => $api->invoiceClosedTickets());
$router->get('/api/fel/issued-rt', fn() => $api->felIssuedRT());

// Consulta NIT (G4S)
$router->get('/api/g4s/lookup-nit', function () {
  header('Content-Type: application/json; charset=utf-8');

  $raw = $_GET['nit'] ?? '';
  $nit = preg_replace('/\D+/', '', $raw); // solo dígitos

  if ($nit === '' || strtoupper($raw) === 'CF') {
    echo json_encode(['ok' => true, 'nit' => 'CF', 'nombre' => null]);
    return;
  }

  try {
    // Carga el .env (Config espera la ruta)
    $cfg = new \Config\Config(__DIR__ . '/../.env');

    // Instancia el cliente y pásale la config
    $client = new \App\Services\G4SClient($cfg);

    // ✅ Pásale el NIT al método
    $res = $client->g4sLookupNit($nit);

    // Imprime la respuesta
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
});

// POST /api/gate/manual-open
$router->post('/api/gate/manual-open', function() use ($controller) {
    $controller->openGateManual();
});


/* =============== Frontend =============== */
$router->get('/', function () {
  $index = __DIR__ . '/index.html';
  if (file_exists($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return;
  }
  echo 'Frontend not found';
});

// === BYPASS defensivo para /api/ingest/tickets y /api/ingest/payments ===
// Normaliza el path (quita querystring y slash final)
$__method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__path   = rtrim($__path, '/');
if ($__path === '') $__path = '/';

// Descomenta temporal si quieres ver qué path llega
// error_log("[ROUTER DEBUG] method=$__method path=$__path");

if ($__method === 'POST' && $__path === '/api/ingest/tickets')  { $api->ingestTickets();  return; }
if ($__method === 'POST' && $__path === '/api/ingest/payments') { $api->ingestPayments(); return; }


/* ============== Dispatch ============== */
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
