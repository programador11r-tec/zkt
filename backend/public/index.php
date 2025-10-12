<?php

declare(strict_types=1);

// Front controller
require __DIR__ . '/../config/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

use App\Utils\Router;
use App\Controllers\ApiController;

$router = new Router();
$api    = new ApiController();

/* ================= API ================= */

// Salud
$router->get('/api/health', fn() => $api->health());

// Ingesta desde ZKBio / externos (PLURAL y métodos existentes)
$router->post('/api/ingest/tickets',  fn() => $api->ingestTickets());
$router->post('/api/ingest/payments', fn() => $api->ingestPayments());
$router->post('/api/ingest/bulk',     fn() => $api->ingestBulk());

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
$router->post('/api/invoice/tickets', fn() => $api->invoiceClosedTickets());
$router->get('/api/fel/issued-rt', fn() => $api->felIssuedRT());

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
