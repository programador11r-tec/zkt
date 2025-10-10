<?php
declare(strict_types=1);

// === Front controller principal ===
require __DIR__ . '/../config/autoload.php';

// Evita que warnings/notice rompan las respuestas JSON
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');


use App\Utils\Router;
use App\Controllers\ApiController;

$router = new Router();
$api    = new ApiController();

// === RUTAS API ===

// Diagnóstico / salud
$router->get('/api/health', fn() => $api->health());

// Sincronización de tickets y pagos desde ZKTeco
$router->get('/api/sync/tickets', fn() => $api->syncTicketsAndPayments());

// Facturación de tickets cerrados automáticamente
$router->post('/api/invoice/tickets', fn() => $api->invoiceClosedTickets());

// Listado de facturas emitidas (para la tabla de facturación)
$router->get('/api/fel/issued-rt', fn() => $api->felIssuedRT());

//api tickets
$router->post('/api/ingest/ticket', fn() => $api->ingestTicket());

//api facturas
$router->post('/api/ingest/payment', fn() => $api->ingestPayment());

//
$router->post('/api/ingest/bulk', fn() => $api->ingestBulk());

// Dashboard
$router->get('/api/tickets', fn() => $api->getTicketsFromDB());     

// Facturación (tabla desde BD)
$router->get('/api/facturacion/list', fn() => $api->facturacionList()); 

// Botón Facturar -> G4S
$router->post('/api/fel/invoice', fn() => $api->invoiceOne());          

// emitidas 
$router->get('/api/facturacion/emitidas', fn() => $api->facturacionEmitidas());

// lista desde BD
$router->get('/api/facturacion/emitidas', fn() => $api->facturacionEmitidas()); 

// stream PDF por UUID
$router->get('/api/fel/pdf',            fn() => $api->felPdf());               

// stream XML por UUID
$router->get('/api/fel/xml',            fn() => $api->felXml());               


// === FRONTEND (HTML principal) ===
$router->get('/', function () {
    $index = __DIR__ . '/index.html';
    if (file_exists($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
    } else {
        echo 'Frontend not found';
    }
});

// === Despachar la solicitud ===
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
