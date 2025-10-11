<?php
declare(strict_types=1);

namespace App\Services;

use Config\Config;

class G4SClient
{
    private Config $config;
    private string $storageDir;

    public function __construct(Config $config){
        $this->config = $config;
        $this->storageDir = __DIR__ . '/../../storage';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    /**
     * Enviar factura a G4S (TIMBRAR) usando SecureTransaction + DataExchange (XML dentro de CDATA).
     * Devuelve array con: ok(bool), uuid(?string), raw(string XML SOAP), inner(?string)
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
            'Data3'       => '',
        ]);

        $uuid = null;
        if (preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/', $respXml, $m)) $uuid = $m[1];
        return ['ok'=>(bool)$uuid, 'uuid'=>$uuid, 'raw'=>$respXml];
    }


    /**
     * (Opcional) Consulta RequestTransaction “clásico” (no Secure). Úsalo si lo necesitas.
     * Retorna el XML SOAP de respuesta como string.
     */
    public function requestTransaction(array $params): string{
        $url    = $this->config->get('FEL_G4S_SOAP_URL');
        $action = 'http://www.fact.com.mx/schema/ws/RequestTransaction';

        // Campos base
        $requestor = $this->config->get('FEL_G4S_REQUESTOR', '');
        $country   = $this->config->get('FEL_G4S_COUNTRY', 'GT');
        $entity    = $this->config->get('FEL_G4S_ENTITY', '');
        $user      = $this->config->get('FEL_G4S_USER', $requestor);
        $username  = $this->config->get('FEL_G4S_USERNAME', '');

        $transaction = $params['Transaction'] ?? 'BASE';
        $data1 = $params['Data1'] ?? '';
        $data2 = $params['Data2'] ?? '';
        $data3 = $params['Data3'] ?? '';

        $soapBody = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
        <soap:Body>
            <sch:RequestTransaction xmlns:sch="http://www.fact.com.mx/schema/ws">
            <sch:Requestor>{$this->xmlEscape($requestor)}</sch:Requestor>
            <sch:Transaction>{$this->xmlEscape($transaction)}</sch:Transaction>
            <sch:Country>{$this->xmlEscape($country)}</sch:Country>
            <sch:Entity>{$this->xmlEscape($entity)}</sch:Entity>
            <sch:User>{$this->xmlEscape($user)}</sch:User>
            <sch:UserName>{$this->xmlEscape($username)}</sch:UserName>
            <sch:Data1>{$this->xmlEscape($data1)}</sch:Data1>
            <sch:Data2>{$this->xmlEscape($data2)}</sch:Data2>
            <sch:Data3>{$this->xmlEscape($data3)}</sch:Data3>
            </sch:RequestTransaction>
        </soap:Body>
        </soap:Envelope>
        XML;

        $headers = "Content-Type: text/xml; charset=utf-8\r\n"
                 . "SOAPAction: \"{$action}\"\r\n";

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => $headers,
            'content' => $soapBody,
            'timeout' => 45,
        ]]);

        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException("No se pudo conectar con G4S (RequestTransaction)");
        }
        return $resp;
    }

    /* ====================== SecureTransaction helpers ====================== */

    public function requestTransaction(array $params): string{
        $url    = $this->config->get('FEL_G4S_SOAP_URL');
        $action = 'http://www.fact.com.mx/schema/ws/RequestTransaction';

        // Campos base (revisa .env)
        $requestor = $this->config->get('FEL_G4S_REQUESTOR', '');       // GUID
        $country   = $this->config->get('FEL_G4S_COUNTRY', 'GT');       // GT
        $entity    = $this->config->get('FEL_G4S_ENTITY', '');          // 81491514
        $user      = $this->config->get('FEL_G4S_USER', $requestor);    // GUID (usa el mismo del requestor)
        $username  = $this->config->get('FEL_G4S_USERNAME', '');        // ADMINISTRADOR

        $transaction = $params['Transaction'] ?? 'BASE';
        $data1       = $params['Data1'] ?? '';
        $data2       = $params['Data2'] ?? '';
        $data3       = $params['Data3'] ?? '';

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

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => $headers,
            'content' => $soapBody,
            'timeout' => 60,
        ]]);

        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException("No se pudo conectar con G4S (RequestTransaction)");
        }

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
        $url    = $this->config->get('FEL_G4S_SOAP_URL');
        $entity = $this->config->get('FEL_G4S_ENTITY');

        // --- SOAP 1.1 ---
        $soap11 = $this->buildSecureSoapEnvelope11($entity, $dataExchange);
        @file_put_contents($this->storageDir.'/last_secure_req_11.xml', $soap11);

        $headers11 = "Content-Type: text/xml; charset=utf-8\r\n"
                   . "SOAPAction: \"http://www.fact.com.mx/schema/ws/SecureTransaction\"\r\n";
        $ctx11 = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => $headers11,
            'content' => $soap11,
            'timeout' => 45,
        ]]);
        $resp11 = @file_get_contents($url, false, $ctx11);
        if ($resp11 !== false) {
            @file_put_contents($this->storageDir.'/last_secure_resp_11.xml', $resp11);
            if (strpos($resp11, '<SecureTransactionResult>') !== false) {
                return $resp11;
            }
        }

        // --- SOAP 1.2 (fallback) ---
        $soap12 = $this->buildSecureSoapEnvelope12($entity, $dataExchange);
        @file_put_contents($this->storageDir.'/last_secure_req_12.xml', $soap12);

        $headers12 = "Content-Type: application/soap+xml; charset=utf-8\r\n";
        $ctx12 = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => $headers12,
            'content' => $soap12,
            'timeout' => 45,
        ]]);
        $resp12 = @file_get_contents($url, false, $ctx12);
        if ($resp12 === false) {
            throw new \RuntimeException('No se pudo conectar con G4S (SecureTransaction SOAP 1.2)');
        }
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
