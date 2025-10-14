<?php
declare(strict_types=1);

namespace App\Services;

use Config\Config;

class G4SClient
{
    private Config $config;
    private string $storageDir;
    private ?int $lastHttpStatus = null;
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
    public function submitInvoice(array $payload) {
        $xmlDte = $this->buildGuatemalaDTE($payload);
        @file_put_contents(__DIR__.'/../../storage/last_dte.xml', $xmlDte);

        $encode = strtolower((string)$this->config->get('FEL_G4S_DATAX_ENCODE','base64'));
        $data1  = ($encode==='base64') ? base64_encode($xmlDte) : $xmlDte;

        $respXml = $this->requestTransaction([
            'Transaction' => 'TIMBRAR',
            'Data1'       => $data1,
            'Data2'       => $this->config->get('FEL_G4S_PASS',''),
        ]);

        $uuid = null;
        if (preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/', $respXml, $m)) $uuid = $m[1];
        return [
            'ok'         => (bool)$uuid,
            'uuid'       => $uuid,
            'raw'        => $respXml,
            'httpStatus' => $this->getLastHttpStatus(),
        ];
    }

    /* ====================== SecureTransaction helpers ====================== */

    public function requestTransaction(array $params): string{
        $url    = $this->validateSoapUrl((string)$this->config->get('FEL_G4S_SOAP_URL'));
        $action = 'http://www.fact.com.mx/schema/ws/RequestTransaction';

        // Campos base (revisa .env)
        $requestor = $this->validateGuid((string)$this->config->get('FEL_G4S_REQUESTOR', ''), 'FEL_G4S_REQUESTOR');
        $country   = $this->validateCountry((string)$this->config->get('FEL_G4S_COUNTRY', 'GT'));
        $entity    = $this->validateEntity((string)$this->config->get('FEL_G4S_ENTITY', ''));
        $user      = $this->validateGuid((string)$this->config->get('FEL_G4S_USER', $requestor), 'FEL_G4S_USER');
        $username  = $this->requireNonEmpty((string)$this->config->get('FEL_G4S_USERNAME', ''), 'FEL_G4S_USERNAME');

        $transaction = $this->normalizeTransaction($params['Transaction'] ?? 'BASE');
        $data1       = (string)($params['Data1'] ?? '');
        $data2       = trim((string)($params['Data2'] ?? ''));
        $data3       = strtoupper(trim((string)($params['Data3'] ?? (string)$this->config->get('FEL_G4S_MODE', ''))));

        $this->validateTransactionPayload($transaction, $data1, $data2, $data3);

        // Sobre SOAP 1.1 literal, tal cual WSDL (sin prefijos propios)
        $soapBody = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
        <soap:Body>
            <RequestTransaction xmlns="http://www.fact.com.mx/schema/ws">
            <Requestor>{$this->xmlEscape($requestor)}</Requestor>
            <Transaction>{$this->xmlEscape($transaction)}</Transaction>
            <Country>{$this->xmlEscape($country)}</Country>
            <Entity>{$this->xmlEscape($entity)}</Entity>
            <User>{$this->xmlEscape($user)}</User>
            <UserName>{$this->xmlEscape($username)}</UserName>
            <Data1>{$this->xmlEscape($data1)}</Data1>
            <Data2>{$this->xmlEscape($data2)}</Data2>
            <Data3>{$this->xmlEscape($data3)}</Data3>
            </RequestTransaction>
        </soap:Body>
        </soap:Envelope>
        XML;

        // Guardar request para depuración
        @file_put_contents($this->storageDir.'/last_request_tx.xml', $soapBody);

        $headers = "Content-Type: text/xml; charset=utf-8\r\n"
                . "SOAPAction: \"{$action}\"\r\n";

        $resp = $this->postSoapRequest($url, $soapBody, $headers, 60, 'No se pudo conectar con G4S (RequestTransaction)');

        @file_put_contents($this->storageDir.'/last_request_tx_resp.xml', (string)$resp);
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

    public function getLastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
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
    private function buildGuatemalaDTE(array $doc): string{
        // Fecha Guatemala
        $tzGT  = new \DateTimeZone('America/Guatemala');
        $fecha = (new \DateTime('now', $tzGT))->format('Y-m-d\TH:i:sP'); // ej. 2025-10-10T13:45:12-06:00

        // Emisor
        $nitEmisor      = trim($doc['emisor']['nit'] ?? '81491514');
        $nombreEmisor   = $doc['emisor']['nombre'] ?? 'PARQUEO OBELISCO REFORMA';
        $correoEmisor   = trim($doc['emisor']['correo'] ?? '');
        $codEst         = $this->config->get('FEL_G4S_ESTABLECIMIENTO', '4');

        // Receptor
        $nitReceptor    = trim($doc['receptor']['nit'] ?? 'CF');
        $nombreReceptor = $doc['receptor']['nombre'] ?? ($nitReceptor === 'CF' ? 'Consumidor Final' : 'Receptor');
        $correoReceptor = trim($doc['receptor']['correo'] ?? '');

        // Doc
        $moneda     = $doc['documento']['moneda'] ?? 'GTQ';
        $totalBruto = (float)($doc['documento']['total'] ?? 0);
        if ($totalBruto <= 0) {
            throw new \InvalidArgumentException('Total debe ser > 0 para construir DTE');
        }

        // Un ítem (total IVA incluido)
        $desc = $doc['documento']['items'][0]['descripcion'] ?? 'Servicio';

        // Base + IVA
        $base = round($totalBruto / 1.12, 6);
        $iva  = round($totalBruto - $base, 6);

        $f6 = fn($n) => number_format((float)$n, 6, '.', '');
        $f2 = fn($n) => number_format((float)$n, 2, '.', '');

        $precioUnitario   = $f6($base);
        $precioLinea      = $f6($base);
        $descuento        = $f6(0);
        $montoGravable    = $f6($base);
        $montoImpuestoIVA = $f6($iva);
        $totalLinea       = $f6($base);         // SIN IVA
        $granTotal        = $f2($base + $iva);  // CON IVA

        // Direcciones mínimas
        $dirEmisor = <<<XML
        <dte:DireccionEmisor>
        <dte:Direccion>Ciudad</dte:Direccion>
        <dte:CodigoPostal>01001</dte:CodigoPostal>
        <dte:Municipio>Guatemala</dte:Municipio>
        <dte:Departamento>Guatemala</dte:Departamento>
        <dte:Pais>GT</dte:Pais>
        </dte:DireccionEmisor>
        XML;

            $dirReceptor = <<<XML
        <dte:DireccionReceptor>
        <dte:Direccion>Ciudad</dte:Direccion>
        <dte:CodigoPostal>01001</dte:CodigoPostal>
        <dte:Municipio>Guatemala</dte:Municipio>
        <dte:Departamento>Guatemala</dte:Departamento>
        <dte:Pais>GT</dte:Pais>
        </dte:DireccionReceptor>
        XML;

        // Atributos opcionales: si no hay correo, no se envían
        $attrCorreoEmisor   = $correoEmisor   !== '' ? ' CorreoEmisor="'.$this->xmlEscape($correoEmisor).'"'     : '';
        $attrCorreoReceptor = $correoReceptor !== '' ? ' CorreoReceptor="'.$this->xmlEscape($correoReceptor).'"' : '';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <dte:GTDocumento
            xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            Version="0.4">
        <dte:SAT ClaseDocumento="dte">
            <dte:DTE ID="DatosCertificados">
            <dte:DatosEmision ID="DatosEmision">
                <dte:DatosGenerales FechaHoraEmision="{$fecha}" Tipo="FACT" CodigoMoneda="{$this->xmlEscape($moneda)}"/>
                <dte:Emisor NITEmisor="{$this->xmlEscape($nitEmisor)}"
                            NombreEmisor="{$this->xmlEscape($nombreEmisor)}"
                            AfiliacionIVA="GEN"
                            CodigoEstablecimiento="{$this->xmlEscape($codEst)}"{$attrCorreoEmisor}>
                {$dirEmisor}
                </dte:Emisor>
                <dte:Receptor IDReceptor="{$this->xmlEscape($nitReceptor)}"
                            NombreReceptor="{$this->xmlEscape($nombreReceptor)}"{$attrCorreoReceptor}>
                {$dirReceptor}
                </dte:Receptor>
                <dte:Frases>
                <dte:Frase TipoFrase="1" CodigoEscenario="1"/>
                </dte:Frases>
                <dte:Items>
                <dte:Item NumeroLinea="1" BienOServicio="S">
                    <dte:Cantidad>1</dte:Cantidad>
                    <dte:UnidadMedida>UNI</dte:UnidadMedida>
                    <dte:Descripcion>{$this->xmlEscape($desc)}</dte:Descripcion>
                    <dte:PrecioUnitario>{$precioUnitario}</dte:PrecioUnitario>
                    <dte:Precio>{$precioLinea}</dte:Precio>
                    <dte:Descuento>{$descuento}</dte:Descuento>
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
        </dte:SAT>
        </dte:GTDocumento>
        XML;
    }


}
