<?php
declare(strict_types=1);

namespace App\Services;

use Config\Config;

class G4SClient
{   
    
    private int $lastHttpStatus = 0;
    private Config $config;
    private string $storageDir;
    private array $lastHttpHeaders = [];

    public function __construct(Config $config){
        $this->config = $config;
        $this->storageDir = __DIR__ . '/../../storage';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    /**
     * Enviar factura a G4S (TIMBRAR) usando SecureTransaction + DataExchange (XML dentro de CDATA).
     * Devuelve array con: ok(bool), uuid(?string), raw(string XML SOAP), httpStatus(?int)
     */
 public function submitInvoice(array $payload)
{
    $logFile = __DIR__ . '/../../storage/fel_submit_invoice.log';
    \App\Utils\Logger::log("=== [SUBMIT INVOICE START] ===", $logFile);
    \App\Utils\Logger::log("Payload recibido: " . json_encode($payload, JSON_UNESCAPED_UNICODE), $logFile);

    // 1) Validaciones
    $total = isset($payload['total']) ? (float)$payload['total'] : 0.0;
    if ($total <= 0) {
        $msg = "❌ Total debe ser > 0 para construir DTE (valor={$total})";
        \App\Utils\Logger::log($msg, $logFile);
        throw new \RuntimeException($msg);
    }

    // 2) Normaliza/consulta NIT
    $nit = strtoupper(trim($payload['receptor_nit'] ?? 'CF'));
    if ($nit !== 'CF') {
        try {
            $q = $this->g4sLookupNit($nit);
            \App\Utils\Logger::log("[NIT LOOKUP] " . json_encode($q, JSON_UNESCAPED_UNICODE), $logFile);
            if (!empty($q['ok']) && !empty($q['nombre'])) {
                $payload['receptor_nombre'] = $q['nombre'];
            }
        } catch (\Throwable $e) {
            \App\Utils\Logger::log("[NIT LOOKUP] Error: " . $e->getMessage(), $logFile);
        }
    }
    $payload['receptor_nit'] = $nit;

    // 3) Construye XML DTE
    try {
        $xmlDte = $this->buildGuatemalaDTE([
            'emisor' => [
                'nit'             => $this->config->get('FEL_G4S_ENTITY', '81491514'),
                'nombre'          => $this->config->get('EMISOR_NOMBRE', 'PARQUEO OBELISCO REFORMA'),
                'comercial'       => $this->config->get('EMISOR_COMERCIAL', 'PARQUEO OBELISCO REFORMA'),
                'establecimiento' => $this->config->get('FEL_G4S_ESTABLECIMIENTO', '4'),
                'direccion'       => [
                    'direccion'    => $this->config->get('EMISOR_DIR', 'Ciudad'),
                    'postal'       => $this->config->get('EMISOR_POSTAL', '01001'),
                    'municipio'    => $this->config->get('EMISOR_MUNI', 'Guatemala'),
                    'departamento' => $this->config->get('EMISOR_DEPTO', 'Guatemala'),
                    'pais'         => 'GT',
                ],
            ],
            'receptor' => [
                'nit'    => $nit,
                'nombre' => $nit === 'CF' ? 'Consumidor Final' : ($payload['receptor_nombre'] ?? 'Receptor'),
                'direccion' => [
                    'direccion'    => 'CIUDAD',
                    'postal'       => '01005',
                    'municipio'    => '.',
                    'departamento' => '.',
                    'pais'         => 'GT',
                ],
            ],
            'documento' => [
                'moneda' => 'GTQ',
                'total'  => $total,
                'items'  => [
                    ['descripcion' => $payload['descripcion'] ?? 'Servicio de parqueo'],
                ],
            ],
        ]);
    } catch (\Throwable $e) {
        \App\Utils\Logger::log("❌ Error generando XML DTE: " . $e->getMessage(), $logFile);
        throw $e;
    }

    // 4) Guarda el XML para inspección
    $xmlPath = __DIR__ . '/../../storage/last_dte.xml';
    @file_put_contents($xmlPath, $xmlDte);
    \App\Utils\Logger::log("XML generado guardado en: {$xmlPath}", $logFile);

    // 5) Envía SYSTEM_REQUEST + POST_DOCUMENT_SAT (Data2 = XML Base64)
$clave     = (string)($this->config->get('FEL_G4S_PASS', '') ?: $this->config->get('FEL_G4S_CLAVE', ''));
$reference = (string)($payload['ticket_no'] ?? ('FACT'.date('YmdHis')));

// base64 limpio del XML
$xmlNoBom = preg_replace('/^\xEF\xBB\xBF/', '', $xmlDte);
$dataB64  = str_replace(["\r", "\n"], '', base64_encode($xmlNoBom));

// ⬇️ Lo que cambia: Data2 lleva el Base64 del XML; la clave pasa (si la requieren) a Data3 o no se envía.
$params = [
    'Transaction' => 'SYSTEM_REQUEST',
    'Data1'       => 'POST_DOCUMENT_SAT',
    'Data2'       => $dataB64,      // <-- XML FEL EN BASE64 AQUÍ
    // 'Data4'    => $clave,        // solo si tu variant la necesita; si NO, elimínalo
];

\App\Utils\Logger::log("→ SYSTEM_REQUEST params: Data1=POST_DOCUMENT_SAT, Data2(Base64)=".strlen($dataB64)." bytes, Data3={$reference}", $logFile);

try {
    $respXml = $this->requestTransaction($params);
} catch (\Throwable $e) {
    \App\Utils\Logger::log("❌ Error en requestTransaction(): " . $e->getMessage(), $logFile);
    throw $e;
}


    \App\Utils\Logger::log("SOAP Response:\n" . $respXml, $logFile);

    // 6) Extrae UUID si viene
    $uuid = null;
    try {
        $sx = @simplexml_load_string($respXml);
        if ($sx) {
            $sx->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
            $sx->registerXPathNamespace('ns0','http://www.fact.com.mx/schema/ws');
            // a) puede venir en el XML interno
            $node = $sx->xpath('//ns0:RequestTransactionResult');
            if ($node && isset($node[0])) {
                $inner = (string)$node[0];
                $ix = @simplexml_load_string($inner);
                if ($ix) {
                    $uuid = (string)($ix->Response->UUID ?? '');
                    if ($uuid === '' && isset($ix->DocumentGUID)) $uuid = (string)$ix->DocumentGUID;
                }
            }
        }
        // b) fallback regex
        if (!$uuid && preg_match('/<UUID>([^<]+)<\/UUID>/', $respXml, $m)) $uuid = $m[1];
        if (!$uuid && preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/', $respXml, $m)) $uuid = $m[1];
    } catch (\Throwable $e) {
        \App\Utils\Logger::log("Warn parse UUID: ".$e->getMessage(), $logFile);
    }

    // 7) Respuesta final
    $result = [
        'ok'         => (bool)$uuid,
        'uuid'       => $uuid,
        'raw'        => $respXml,
        'httpStatus' => $this->getLastHttpStatus(),
        'reference'  => $reference,
    ];
    \App\Utils\Logger::log("Resultado final: " . json_encode($result, JSON_UNESCAPED_UNICODE), $logFile);
    \App\Utils\Logger::log("=== [SUBMIT INVOICE END] ===", $logFile);

    return $result;
}





/** 🧩 Helper para loggear request/response SOAP */
private function logSoap(string $file, string $content): void
{
    $dir = __DIR__ . '/../../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/' . $file,
        '[' . date('c') . "]\n" . $content . "\n\n",
        FILE_APPEND
    );
}


    /* ====================== SecureTransaction helpers ====================== */
// En App\Services\G4SClient.php


public function getLastHttpStatus(): int {
    return $this->lastHttpStatus;
}

private function requestTransaction(array $params): string
{
    $soapUrl   = (string)$this->config->get('FEL_G4S_SOAP_URL', 'https://fel.g4sdocumenta.com/webservicefront/factwsfront.asmx');
    $soapAction= 'http://www.fact.com.mx/schema/ws/RequestTransaction';

    // Campos obligatorios según docs G4S
    $requestor = (string)$this->config->get('FEL_G4S_REQUESTOR', '');
    $country   = (string)$this->config->get('FEL_G4S_COUNTRY', 'GT');
    $entity    = (string)$this->config->get('FEL_G4S_ENTITY', '');
    $user      = (string)$this->config->get('FEL_G4S_USER', $requestor);
    $username  = (string)$this->config->get('FEL_G4S_USERNAME', 'TEMP');

    $transaction = (string)($params['Transaction'] ?? 'TIMBRAR');
    $data1       = (string)($params['Data1'] ?? ''); // XML DTE (normal o Base64)
    $data2       = (string)($params['Data2'] ?? ''); // password
    $data3       = (string)($params['Data3'] ?? ''); // opcional

    // Sobre SOAP completo
    $envelope = <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                   xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                   xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body>
        <RequestTransaction xmlns="http://www.fact.com.mx/schema/ws">
          <Requestor>{$requestor}</Requestor>
          <Transaction>{$transaction}</Transaction>
          <Country>{$country}</Country>
          <Entity>{$entity}</Entity>
          <User>{$user}</User>
          <UserName>{$username}</UserName>
          <Data1>{$data1}</Data1>
          <Data2>{$data2}</Data2>
          <Data3>{$data3}</Data3>
        </RequestTransaction>
      </soap:Body>
    </soap:Envelope>
    XML;

    // Logging (útil para depurar)
    $logFile = __DIR__ . '/../../storage/fel_submit_invoice.log';
    \App\Utils\Logger::log("SOAP Request →\n".$envelope, $logFile);

    $ch = curl_init($soapUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "'.$soapAction.'"',
        ],
        CURLOPT_POSTFIELDS     => $envelope,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $this->lastHttpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        $err = curl_error($ch) ?: 'Error desconocido';
        curl_close($ch);
        throw new \RuntimeException('Error SOAP G4S: '.$err);
    }
    curl_close($ch);

    return $resp;
}


    private function buildSecureSoapEnvelope11(string $entity, string $dataExchange): string{
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
        <soap:Body>
            <SecureTransaction xmlns="http://www.fact.com.mx/schema/ws">
            <Entity>{$this->xmlEscape($entity)}</Entity>
            <DataExchange><![CDATA[{$dataExchange}]]></DataExchange>
            </SecureTransaction>
        </soap:Body>
        </soap:Envelope>
        XML;
    }

    private function buildSecureSoapEnvelope12(string $entity, string $dataExchange): string{
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
        <soap12:Body>
            <SecureTransaction xmlns="http://www.fact.com.mx/schema/ws">
            <Entity>{$this->xmlEscape($entity)}</Entity>
            <DataExchange><![CDATA[{$dataExchange}]]></DataExchange>
            </SecureTransaction>
        </soap12:Body>
        </soap12:Envelope>
        XML;
    }

    private function callSecureTransaction(string $dataExchange): string{
        $url    = $this->validateSoapUrl((string)$this->config->get('FEL_G4S_SOAP_URL'));
        $entity = $this->validateEntity((string)$this->config->get('FEL_G4S_ENTITY'));

        $payload = trim($dataExchange);
        if ($payload === '') {
            throw new \InvalidArgumentException('El cuerpo DataExchange para SecureTransaction no puede estar vacío.');
        }

        // --- SOAP 1.1 ---
        $soap11 = $this->buildSecureSoapEnvelope11($entity, $dataExchange);
        @file_put_contents($this->storageDir.'/last_secure_req_11.xml', $soap11);

        $headers11 = "Content-Type: text/xml; charset=utf-8\r\n"
                   . "SOAPAction: \"http://www.fact.com.mx/schema/ws/SecureTransaction\"\r\n";
        try {
            $resp11 = $this->postSoapRequest($url, $soap11, $headers11, 45, 'No se pudo conectar con G4S (SecureTransaction SOAP 1.1)');
            @file_put_contents($this->storageDir.'/last_secure_resp_11.xml', $resp11);
            if (strpos($resp11, '<SecureTransactionResult>') !== false) {
                return $resp11;
            }
        } catch (\RuntimeException $e) {
            $resp11 = null;
        }

        // --- SOAP 1.2 (fallback) ---
        $soap12 = $this->buildSecureSoapEnvelope12($entity, $dataExchange);
        @file_put_contents($this->storageDir.'/last_secure_req_12.xml', $soap12);

        $headers12 = "Content-Type: application/soap+xml; charset=utf-8\r\n";
        $resp12 = $this->postSoapRequest($url, $soap12, $headers12, 45, 'No se pudo conectar con G4S (SecureTransaction SOAP 1.2)');
        @file_put_contents($this->storageDir.'/last_secure_resp_12.xml', $resp12);
        return $resp12;
    }

    private function extractSecureResultString(string $respXml): string{
        if (preg_match('/<SecureTransactionResult>(.*?)<\/SecureTransactionResult>/s', $respXml, $m)) {
            // decodifica entidades si vinieran escapadas
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return '';
    }

    private function xmlEscape(string $s): string{
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function validateSoapUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Config FEL_G4S_SOAP_URL requerida para comunicarse con G4S.');
        }
        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('FEL_G4S_SOAP_URL debe ser una URL válida (por ejemplo https://fel.g4sdocumenta.com/webservicefront/factwsfront.asmx).');
        }
        return $trimmed;
    }

    private function normalizeTransaction(mixed $transaction): string
    {
        $value = strtoupper(trim((string)($transaction ?? '')));
        if ($value === '') {
            throw new \InvalidArgumentException('El campo Transaction es obligatorio para RequestTransaction.');
        }
        if (!preg_match('/^[A-Z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException('Transaction inválido. Debe coincidir con las constantes documentadas por G4S (por ejemplo TIMBRAR, GET_XML, GET_DOCUMENT).');
        }
        return $value;
    }

    private function validateTransactionPayload(string $transaction, string $data1, string $data2, string $data3): void
    {
        $needsData1 = [
            'TIMBRAR',
            'GET_XML',
            'GET_DOCUMENT',
            'GET_XML_AND_HTML',
            'CANCEL_XML',
            'CANCEL_XML_BY_INTERNAL_ID',
            'MARK_XML_AS_PAID',
            'MARK_XML_AS_UNPAID',
        ];

        if (in_array($transaction, $needsData1, true) && trim($data1) === '') {
            throw new \InvalidArgumentException(sprintf('Data1 es obligatorio para la transacción %s según la especificación de RequestTransaction.', $transaction));
        }

        if ($transaction === 'TIMBRAR') {
            $encode = strtolower((string)$this->config->get('FEL_G4S_DATAX_ENCODE', 'base64'));
            if ($encode === 'base64') {
                $normalized = preg_replace('/\s+/', '', $data1);
                if ($normalized === '' || base64_decode($normalized, true) === false) {
                    throw new \InvalidArgumentException('Data1 debe estar codificado en Base64 porque FEL_G4S_DATAX_ENCODE=base64.');
                }
            }
            if ($data2 === '') {
                throw new \InvalidArgumentException('Data2 (contraseña del firmante) es obligatorio para la transacción TIMBRAR.');
            }
            if ($data3 === '') {
                throw new \InvalidArgumentException('Data3 (modo de operación TEST/PRODUCCION) es obligatorio para la transacción TIMBRAR. Configure FEL_G4S_MODE.');
            }
            if (!preg_match('/^[A-Z0-9_]+$/', $data3)) {
                throw new \InvalidArgumentException('Data3 debe coincidir con un modo válido reconocido por G4S (por ejemplo TEST, PRODUCCION).');
            }
        }

        if ($transaction === 'GET_DOCUMENT' && $data2 === '') {
            throw new \InvalidArgumentException('Data2 (tipo de documento a recuperar, por ejemplo PDF) es obligatorio para GET_DOCUMENT.');
        }
    }

    private function validateGuid(string $value, string $envKey): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException(sprintf('Config %s requerida. Debe contener el GUID asignado por G4S.', $envKey));
        }
        $pattern = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/';
        if (!preg_match($pattern, $trimmed)) {
            throw new \InvalidArgumentException(sprintf('%s debe ser un GUID válido (formato 8-4-4-4-12). Valor recibido: %s', $envKey, $trimmed));
        }
        return strtoupper($trimmed);
    }

    private function validateCountry(string $value): string
    {
        $trimmed = strtoupper(trim($value ?: 'GT'));
        if (!preg_match('/^[A-Z]{2}$/', $trimmed)) {
            throw new \InvalidArgumentException('FEL_G4S_COUNTRY debe ser el código ISO de dos letras (por ejemplo GT).');
        }
        return $trimmed;
    }

    private function validateEntity(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Config FEL_G4S_ENTITY requerida (NIT del emisor sin guiones).');
        }
        if (preg_match('/\s/', $trimmed)) {
            throw new \InvalidArgumentException('FEL_G4S_ENTITY no debe contener espacios.');
        }
        return $trimmed;
    }

    private function requireNonEmpty(string $value, string $envKey): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException(sprintf('Config %s es obligatoria para comunicarse con G4S.', $envKey));
        }
        return $trimmed;
    }

    private function postSoapRequest(string $url, string $body, string $headers, int $timeout, string $errorPrefix): string
    {
        global $http_response_header;

        $options = [
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ];

        $this->lastHttpHeaders = [];
        $this->lastHttpStatus  = null;

        $ctx  = stream_context_create($options);
        $resp = @file_get_contents($url, false, $ctx);
        $headersResp = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            $headersResp = $http_response_header;
        }

        if (is_array($headersResp)) {
            $this->lastHttpHeaders = $headersResp;
            $this->lastHttpStatus  = $this->extractStatusCode($headersResp);
        }

        if ($resp === false) {
            $err = error_get_last();
            $statusLine = $headersResp[0] ?? '';
            $details = trim(($statusLine ? $statusLine . ' ' : '') . ($err['message'] ?? ''));
            $msg = $details !== '' ? $errorPrefix . ': ' . $details : $errorPrefix;
            throw new \RuntimeException($msg);
        }

        return $resp;
    }

    private function extractStatusCode(array $headers): ?int
    {
        if (empty($headers)) {
            return null;
        }

        if (preg_match('/\s(\d{3})\s/', (string)$headers[0], $m)) {
            return (int)$m[1];
        }

        return null;
    }


    public function getLastHttpHeaders(): array
    {
        return $this->lastHttpHeaders;
    }

    /* ========================= DTE FEL Guatemala ========================= */

    /**
     * Builder mínimo válido (FACT, IVA 12%, 1 ítem).
     * Ajusta campos de dirección/correos/razón social según tu emisor.
     */
  private function buildGuatemalaDTE(array $doc): string
{
    // Fecha GT sin offset
    $tzGT   = new \DateTimeZone('America/Guatemala');
    $fechaG = new \DateTime('now', $tzGT);
    $fecha  = $fechaG->format('Y-m-d\TH:i:s');

    // ===== Emisor
    $nitEmisor    = trim($doc['emisor']['nit']     ?? '81491514');
    $nomEmisor    = $doc['emisor']['nombre']       ?? 'PARQUEO OBELISCO REFORMA';
    $nomComercial = $doc['emisor']['comercial']    ?? 'PARQUEO OBELISCO REFORMA';
    $codEst       = $doc['emisor']['establecimiento'] ?? $this->config->get('FEL_G4S_ESTABLECIMIENTO', '4');

    $dirE = [
        'direccion'   => $doc['emisor']['direccion']['direccion']    ?? 'loc.8 y 9, Edificio Reforma obelisco 16 calle, Cdad. de Guatemala',
        'postal'      => $doc['emisor']['direccion']['postal']       ?? '01009',
        'municipio'   => $doc['emisor']['direccion']['municipio']    ?? 'GUATEMALA',
        'depto'       => $doc['emisor']['direccion']['departamento'] ?? 'GUATEMALA',
        'pais'        => $doc['emisor']['direccion']['pais']         ?? 'GT',
    ];

    // ===== Receptor (con posible enriquecimiento por NIT)
    $nitRec = strtoupper(trim($doc['receptor']['nit'] ?? 'CF'));
    $nomRec = $doc['receptor']['nombre'] ?? ($nitRec === 'CF' ? 'Consumidor Final' : null);

    $dirR = [
        'direccion'   => $doc['receptor']['direccion']['direccion']    ?? 'CIUDAD',
        'postal'      => $doc['receptor']['direccion']['postal']       ?? '01005',
        'municipio'   => $doc['receptor']['direccion']['municipio']    ?? '.',
        'depto'       => $doc['receptor']['direccion']['departamento'] ?? '.',
        'pais'        => $doc['receptor']['direccion']['pais']         ?? 'GT',
    ];

    if ($nitRec !== '' && $nitRec !== 'CF') {
        try {
            $q = $this->g4sLookupNit($nitRec);
            if (!empty($q['ok'])) {
                if (!empty($q['nombre'])) {
                    $nomRec = $q['nombre'];
                }
                if (!empty($q['direccion']) && is_array($q['direccion'])) {
                    $dirR = array_merge($dirR, array_filter([
                        'direccion' => $q['direccion']['direccion']   ?? null,
                        'postal'    => $q['direccion']['postal']      ?? null,
                        'municipio' => $q['direccion']['municipio']   ?? null,
                        'depto'     => $q['direccion']['departamento']?? null,
                        'pais'      => $q['direccion']['pais']        ?? null,
                    ], fn($v) => !is_null($v)));
                }
            }
        } catch (\Throwable $e) {
            // continúa con los datos originales si falla
        }
        if (!$nomRec) $nomRec = 'Receptor';
    }

    // ===== Documento / Ítem
    $moneda      = $doc['documento']['moneda'] ?? ($doc['moneda'] ?? 'GTQ');
    $totalBruto  = isset($doc['documento']['total']) ? (float)$doc['documento']['total'] : (float)($doc['total'] ?? 0);
    $descItem    = $doc['documento']['items'][0]['descripcion'] ?? ($doc['descripcion'] ?? 'Servicio de parqueo');

    // Base + IVA (precios mostrados con IVA); IVA con FLOOR a 6 decimales
    $base = $totalBruto > 0 ? round($totalBruto / 1.12, 6) : 0.0;
    $iva  = max(0, floor( ($totalBruto - $base) * 1_000_000 ) / 1_000_000);

    $f6  = fn($n) => number_format((float)$n, 6, '.', '');
    $f10 = fn($n) => number_format((float)$n, 10, '.', '');
    $f2  = fn($n) => number_format((float)$n, 2,  '.', '');

    $cantidad         = $f10(1);
    $unidad           = 'UNI';
    $precioLinea      = $f6($totalBruto);
    $montoGravable    = $f6($base);
    $montoImpuestoIVA = $f6($iva);
    $totalLinea       = $f6($totalBruto);
    $granTotal        = $f6($totalBruto); // 6 decimales como en tu XML “perfecto”

    // Total en letras (tu helper sin NumberFormatter)
    $totalEnLetras = $this->montoEnLetrasGT((float)$granTotal);

    return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<dte:GTDocumento
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:crc="http://www.sat.gob.gt/face2/ComplementoReferenciaConstancia/0.1.0"
    xmlns:cesp="http://www.sat.gob.gt/face2/ComplementoEspectaculos/0.1.0"
    xmlns:ctrasmer="http://www.sat.gob.gt/face2/TrasladoMercancias/0.1.0"
    xmlns:cexprov="http://www.sat.gob.gt/face2/ComplementoExportacionProvisional/0.1.0"
    xmlns:clepp="http://www.sat.gob.gt/face2/ComplementoPartidosPolitico/0.1.0"
    xmlns:cmep="http://www.sat.gob.gt/face2/ComplementoMediosDePago/0.1.0"
    xmlns:cfc="http://www.sat.gob.gt/dte/fel/CompCambiaria/0.1.0"
    xmlns:cfe="http://www.sat.gob.gt/face2/ComplementoFacturaEspecial/0.1.0"
    xmlns:cno="http://www.sat.gob.gt/face2/ComplementoReferenciaNota/0.1.0"
    xmlns:cca="http://www.sat.gob.gt/face2/CobroXCuentaAjena/0.1.0"
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    xmlns:ctup="http://www.sat.gob.gt/face2/ComplementoTurismoPasaje/0.1.0"
    xmlns:cex="http://www.sat.gob.gt/face2/ComplementoExportaciones/0.1.0"
    xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0"
    Version="0.1">
  <dte:SAT ClaseDocumento="dte">
    <dte:DTE ID="DatosCertificados">
      <dte:DatosEmision ID="DatosEmision">
        <dte:DatosGenerales Tipo="FACT" FechaHoraEmision="{$this->xmlEscape($fecha)}" CodigoMoneda="{$this->xmlEscape($moneda)}"/>
        <dte:Emisor NITEmisor="{$this->xmlEscape($nitEmisor)}"
                    NombreEmisor="{$this->xmlEscape($nomEmisor)}"
                    CodigoEstablecimiento="{$this->xmlEscape($codEst)}"
                    NombreComercial="{$this->xmlEscape($nomComercial)}"
                    AfiliacionIVA="GEN">
          <dte:DireccionEmisor>
            <dte:Direccion>{$this->xmlEscape($dirE['direccion'])}</dte:Direccion>
            <dte:CodigoPostal>{$this->xmlEscape($dirE['postal'])}</dte:CodigoPostal>
            <dte:Municipio>{$this->xmlEscape(strtoupper($dirE['municipio']))}</dte:Municipio>
            <dte:Departamento>{$this->xmlEscape(strtoupper($dirE['depto']))}</dte:Departamento>
            <dte:Pais>{$this->xmlEscape($dirE['pais'])}</dte:Pais>
          </dte:DireccionEmisor>
        </dte:Emisor>
        <dte:Receptor IDReceptor="{$this->xmlEscape($nitRec)}" NombreReceptor="{$this->xmlEscape($nomRec)}">
          <dte:DireccionReceptor>
            <dte:Direccion>{$this->xmlEscape($dirR['direccion'])}</dte:Direccion>
            <dte:CodigoPostal>{$this->xmlEscape($dirR['postal'])}</dte:CodigoPostal>
            <dte:Municipio>{$this->xmlEscape($dirR['municipio'])}</dte:Municipio>
            <dte:Departamento>{$this->xmlEscape($dirR['depto'])}</dte:Departamento>
            <dte:Pais>{$this->xmlEscape($dirR['pais'])}</dte:Pais>
          </dte:DireccionReceptor>
        </dte:Receptor>
        <dte:Frases>
          <dte:Frase TipoFrase="1" CodigoEscenario="2"/>
        </dte:Frases>
        <dte:Items>
          <dte:Item NumeroLinea="1" BienOServicio="S">
            <dte:Cantidad>{$cantidad}</dte:Cantidad>
            <dte:UnidadMedida>UNI</dte:UnidadMedida>
            <dte:Descripcion>{$this->xmlEscape($descItem)}</dte:Descripcion>
            <dte:PrecioUnitario>{$precioLinea}</dte:PrecioUnitario>
            <dte:Precio>{$precioLinea}</dte:Precio>
            <dte:Descuento>0</dte:Descuento>
            <dte:Impuestos>
              <dte:Impuesto>
                <dte:NombreCorto>IVA</dte:NombreCorto>
                <dte:CodigoUnidadGravable>1</dte:CodigoUnidadGravable>
                <dte:MontoGravable>{$montoGravable}</dte:MontoGravable>
                <dte:MontoImpuesto>{$montoImpuestoIVA}</dte:MontoImpuesto>
              </dte:Impuesto>
            </dte:Impuestos>
            <dte:Total>{$totalLinea}</dte:Total>
          </dte:Item>
        </dte:Items>
        <dte:Totales>
          <dte:TotalImpuestos>
            <dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="{$montoImpuestoIVA}"/>
          </dte:TotalImpuestos>
          <dte:GranTotal>{$granTotal}</dte:GranTotal>
        </dte:Totales>
      </dte:DatosEmision>
    </dte:DTE>
    <dte:Adenda>
      <Adicionales xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                   xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                   xmlns="Schema-totalletras">
        <TotalEnLetras>{$this->xmlEscape($totalEnLetras)}</TotalEnLetras>
      </Adicionales>
    </dte:Adenda>
  </dte:SAT>
</dte:GTDocumento>
XML;
}


/**
 * Convierte el total a letras al formato típico usado en Adenda:
 * - Exactos si no hay centavos.
 * - Si hay centavos: "QUETZALES CON nn/100".
 */
private function montoEnLetrasGT(float $monto): string
{
    $entero = (int)floor($monto + 0.0000001);
    $cent   = (int)round(($monto - $entero) * 100);

    // A: parte entera a letras
    $fmt = new \NumberFormatter('es_GT', \NumberFormatter::SPELLOUT);
    $letras = strtoupper($fmt->format($entero));

    // B: sufijo de centavos
    if ($cent === 0) {
        return "{$letras} QUETZALES EXACTOS";
    }
    $cc = str_pad((string)$cent, 2, '0', STR_PAD_LEFT);
    return "{$letras} QUETZALES CON {$cc}/100";
}




    public function g4sLookupNit(string $nit): array{
            $nit = preg_replace('/\D+/', '', $nit ?? '');
            if ($nit === '') {
                return ['ok' => false, 'nit' => null, 'nombre' => null, 'error' => 'NIT requerido'];
            }

            // Lee config
            $cfgPath   = __DIR__ . '/../../.env';
            $config    = new \Config\Config($cfgPath);
            $entity    = (string) $config->get('FEL_G4S_ENTITY', '');
            $requestor = (string) $config->get('FEL_G4S_REQUESTOR', '');
            $baseUrl   = rtrim((string) $config->get('FEL_G4S_NIT_WSDL', 'https://fel.g4sdocumenta.com/ConsultaNIT/ConsultaNIT.asmx'), '/');

            if ($entity === '' || $requestor === '') {
                return ['ok' => false, 'nit' => null, 'nombre' => null, 'error' => 'Faltan FEL_G4S_ENTITY o FEL_G4S_REQUESTOR'];
            }

            // -------- helper de parseo (sirve para SOAP y POST/GET) --------
            $parseXml = function (string $xml) {
                $sx = @simplexml_load_string($xml);
                if ($sx === false) return [false, null, null, 'XML inválido'];

                // Intento 1: respuesta POST/GET (raíz <respuesta xmlns="http://tempuri.org/">)
                $sx->registerXPathNamespace('t', 'http://tempuri.org/');
                $resNode = $sx->xpath('//t:Response');
                if (isset($resNode[0])) {
                    $ok     = ((string)($resNode[0]->Result ?? '')) === 'true' || ((string)($resNode[0]->Result ?? '')) === '1';
                    $nitOut = (string)($resNode[0]->NIT ?? '');
                    $nombre = (string)($resNode[0]->nombre ?? '');
                    $errStr = (string)($resNode[0]->error ?? '');
                    return [$ok, $nitOut ?: null, $nombre ?: null, $errStr ?: null];
                }

                // Intento 2: SOAP 1.1/1.2
                $sx->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
                $sx->registerXPathNamespace('s12', 'http://www.w3.org/2003/05/soap-envelope');
                $sx->registerXPathNamespace('t', 'http://tempuri.org/');

                $resp = $sx->xpath('//t:getNITResult/t:Response');
                if (isset($resp[0])) {
                    $ok     = ((string)($resp[0]->Result ?? '')) === 'true' || ((string)($resp[0]->Result ?? '')) === '1';
                    $nitOut = (string)($resp[0]->NIT ?? '');
                    $nombre = (string)($resp[0]->nombre ?? '');
                    $errStr = (string)($resp[0]->error ?? '');
                    return [$ok, $nitOut ?: null, $nombre ?: null, $errStr ?: null];
                }

                return [false, null, null, 'No se encontraron nodos Response'];
            };

            // -------- 1) Intento POST application/x-www-form-urlencoded (más simple) --------
            try {
                $url = $baseUrl . '/getNIT';
                $postFields = http_build_query(['vNIT' => $nit, 'Entity' => $entity, 'Requestor' => $requestor], '', '&');
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                    CURLOPT_POSTFIELDS     => $postFields,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => 20,
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                if ($resp !== false && $code >= 200 && $code < 300) {
                    [$ok, $nitOut, $nombre, $errStr] = $parseXml($resp);
                    if ($ok || $errStr) {
                        return ['ok' => $ok, 'nit' => $nitOut ?: $nit, 'nombre' => $nombre, 'error' => $errStr];
                    }
                }
            } catch (\Throwable $e) {
                // sigue con SOAP
                error_log('[G4S][POST] ' . $e->getMessage());
            }

            // -------- 2) Intento SOAP 1.1 --------
            try {
                $soapAction = 'http://tempuri.org/getNIT';
                $xml = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
        <soap:Body>
            <getNIT xmlns="http://tempuri.org/">
            <vNIT>{$nit}</vNIT>
            <Entity>{$entity}</Entity>
            <Requestor>{$requestor}</Requestor>
            </getNIT>
        </soap:Body>
        </soap:Envelope>
        XML;

        $ch = curl_init($baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "'.$soapAction.'"',
            ],
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new \RuntimeException($err ?: 'Fallo SOAP 1.1');
        if ($code < 200 || $code >= 300) throw new \RuntimeException('HTTP '.$code.' SOAP 1.1');

        [$ok, $nitOut, $nombre, $errStr] = $parseXml($resp);
        return ['ok' => $ok, 'nit' => $nitOut ?: $nit, 'nombre' => $nombre, 'error' => $errStr];
        } catch (\Throwable $e) {
            error_log('[G4S][SOAP11] ' . $e->getMessage());
        }

        // -------- 3) Intento SOAP 1.2 (último recurso) --------
        try {
            $xml = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
            <soap12:Body>
                <getNIT xmlns="http://tempuri.org/">
                <vNIT>{$nit}</vNIT>
                <Entity>{$entity}</Entity>
                <Requestor>{$requestor}</Requestor>
                </getNIT>
            </soap12:Body>
            </soap12:Envelope>
            XML;

            $ch = curl_init($baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) throw new \RuntimeException($err ?: 'Fallo SOAP 1.2');
            if ($code < 200 || $code >= 300) throw new \RuntimeException('HTTP '.$code.' SOAP 1.2');

            [$ok, $nitOut, $nombre, $errStr] = $parseXml($resp);
            return ['ok' => $ok, 'nit' => $nitOut ?: $nit, 'nombre' => $nombre, 'error' => $errStr];
        } catch (\Throwable $e) {
            error_log('[G4S][SOAP12] ' . $e->getMessage());
        }

        return ['ok' => false, 'nit' => $nit, 'nombre' => null, 'error' => 'No se pudo consultar el NIT'];
    }


/** Log helper dentro del cliente */
private function log(string $file, string $content): void
{
    $dir = __DIR__ . '/../../storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/' . $file, '['.date('c')."]\n".$content."\n\n", FILE_APPEND);
}



}
