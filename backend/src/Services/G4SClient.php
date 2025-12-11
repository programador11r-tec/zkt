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
    public function submitInvoice(array $payload): array
    {
        $logFile = __DIR__ . '/../../storage/fel_submit_invoice_pdf.log';
        \App\Utils\Logger::log("=== [SUBMIT INVOICE (PDF) START] ===", $logFile);
        \App\Utils\Logger::log("Payload: " . json_encode($payload, JSON_UNESCAPED_UNICODE), $logFile);

        // 1) Validaciones m├¡nimas
        $total = isset($payload['total']) ? (float)$payload['total'] : 0.0;
        if ($total <= 0) {
            $msg = "ÔØî Total debe ser > 0 para construir DTE (valor={$total})";
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

        // 3) Construir DTE (usa tu builder; reconoce 'descripcion')
        $xmlDte = $this->buildGuatemalaDTE([
            'emisor' => [
                'nit'             => $this->config->get('FEL_G4S_ENTITY', '81491514'),
                'nombre'          => $this->config->get('EMISOR_NOMBRE', 'KAVOD, SOCIEDAD ANONIMA'),
                'comercial'       => $this->config->get('EMISOR_COMERCIAL', 'PARQUEO OBELISCO REFORMA'),
                'establecimiento' => $this->config->get('FEL_G4S_ESTABLECIMIENTO', '4'),
                'direccion'       => [
                    'direccion'    => $this->config->get('EMISOR_DIR', '8 AVENIDA 15-46 ZONA 9, CIUDAD DE GUATEMALA'),
                    'postal'       => $this->config->get('EMISOR_POSTAL', '01001'),
                    'municipio'    => $this->config->get('EMISOR_MUNI', 'GUATEMALA'),
                    'departamento' => $this->config->get('EMISOR_DEPTO', 'GUATEMALA'),
                    'pais'         => 'GT',
                ],
            ],
            'receptor' => [
                'nit'    => $nit,
                'nombre' => $nit === 'CF' ? 'Consumidor Final' : ($payload['receptor_nombre'] ?? 'Receptor'),
                'direccion' => ['direccion'=>'CIUDAD','postal'=>'01005','municipio'=>'.','departamento'=>'.','pais'=>'GT'],
            ],
            'documento' => [
                'moneda' => 'GTQ',
                'total'  => $total,
                'items'  => [
                    ['descripcion' => $payload['descripcion'] ?? 'Servicio de parqueo'],
                ],
            ],
        ]);

        // Guarda XML para inspecci├│n
        @file_put_contents(__DIR__ . '/../../storage/last_dte_pdf.xml', $xmlDte);

        // 4) SYSTEM_REQUEST con POST_DOCUMENT_SAT_PDF
        $reference = (string)($payload['ticket_no'] ?? ('FACT'.date('YmdHis')));
        $xmlNoBom  = preg_replace('/^\xEF\xBB\xBF/', '', $xmlDte);
        $xmlB64    = str_replace(["\r","\n"," "], '', base64_encode($xmlNoBom));

        $params = [
            'Transaction' => 'SYSTEM_REQUEST',
            'Data1'       => 'POST_DOCUMENT_SAT_PDF', // ÔåÉ como tu ejemplo
            'Data2'       => $xmlB64,                 // ÔåÉ XML en Base64
            'Data3'       => $reference,              // opcional, referencia
            // Si tu emisor exige clave, col├│cala en Data3 o Data4 seg├║n su variante
        ];
        \App\Utils\Logger::log("ÔåÆ SYSTEM_REQUEST POST_DOCUMENT_SAT_PDF (XML b64 len=".strlen($xmlB64).", ref={$reference})", $logFile);

        try {
            $respXml = $this->requestTransaction($params); // usa tu helper existente
        } catch (\Throwable $e) {
            \App\Utils\Logger::log("ÔØî Error en requestTransaction(): " . $e->getMessage(), $logFile);
            throw $e;
        }

        \App\Utils\Logger::log("SOAP Response:\n" . $respXml, $logFile);

        // 5) Extraer UUID (ResponseData2) y PDF base64 (ResponseData3)
        $uuid   = null;
        $pdfB64 = null;

        try {
            $sx = @simplexml_load_string($respXml);
            if ($sx) {
                // UUID directo en <ResponseData2>
                $rd2 = $sx->xpath('//ResponseData2');
                if ($rd2 && isset($rd2[0])) {
                    $uCandidate = trim((string)$rd2[0]);
                    if ($uCandidate !== '') $uuid = $uCandidate;
                }
                // PDF directo en <ResponseData3>
                $rd3 = $sx->xpath('//ResponseData3');
                if ($rd3 && isset($rd3[0])) {
                    $pCandidate = trim((string)$rd3[0]);
                    if ($pCandidate !== '') $pdfB64 = $pCandidate;
                }

                // XML interno en <ns0:RequestTransactionResult>
                $sx->registerXPathNamespace('ns0','http://www.fact.com.mx/schema/ws');
                $node = $sx->xpath('//ns0:RequestTransactionResult');
                if ($node && isset($node[0])) {
                    $inner = (string)$node[0];
                    $ix = @simplexml_load_string($inner);
                    if ($ix) {
                        if (!$uuid) {
                            $uuid = (string)($ix->Response->UUID ?? '');
                            if ($uuid === '' && isset($ix->DocumentGUID)) $uuid = (string)$ix->DocumentGUID;
                            if ($uuid === '' && isset($ix->UUID))         $uuid = (string)$ix->UUID;
                        }
                        if (!$pdfB64) {
                            $cands = [
                                (string)($ix->Response->PDF ?? ''),
                                (string)($ix->Response->Pdf ?? ''),
                                (string)($ix->PDF ?? ''),
                                (string)($ix->Pdf ?? ''),
                                (string)($ix->Response->PDFBase64 ?? ''),
                                (string)($ix->Response->Data1 ?? ''),
                            ];
                            foreach ($cands as $c) { $c = trim($c); if ($c !== '') { $pdfB64 = $c; break; } }
                            if (!$pdfB64 && isset($ix->Document)) {
                                $name = strtoupper((string)($ix->Document->Name ?? ''));
                                $data = (string)($ix->Document->Data ?? '');
                                if ($name === 'PDF' && $data !== '') $pdfB64 = trim($data);
                            }
                        }
                    } else {
                        if (!$uuid && preg_match('/<UUID>([^<]+)<\/UUID>/i', $inner, $m)) $uuid = $m[1];
                        if (!$uuid && preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/i', $inner, $m)) $uuid = $m[1];
                        if (!$pdfB64 && preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/\1>/i', $inner, $m)) {
                            $pdfB64 = trim($m[2]);
                        }
                        if (!$pdfB64 && preg_match('/<Data1>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data1>/i', $inner, $m)) {
                            $pdfB64 = trim($m[1]);
                        }
                        if (!$pdfB64 && preg_match('/<Name>\s*PDF\s*<\/Name>.*?<Data>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data>/is', $inner, $m)) {
                            $pdfB64 = trim($m[1]);
                        }
                    }
                }
            }

            // Fallbacks sobre todo el SOAP
            if (!$uuid && preg_match('/<ResponseData2>\s*([^<]+)\s*<\/ResponseData2>/i', $respXml, $m)) $uuid = trim($m[1]);
            if (!$uuid && preg_match('/<UUID>([^<]+)<\/UUID>/i', $respXml, $m)) $uuid = $m[1];
            if (!$uuid && preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/i', $respXml, $m)) $uuid = $m[1];
            if (!$pdfB64 && preg_match('/<ResponseData3>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/ResponseData3>/i', $respXml, $m)) {
                $pdfB64 = trim($m[1]);
            }
            if (!$pdfB64 && preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/\1>/i', $respXml, $m)) {
                $pdfB64 = trim($m[2]);
            }
        } catch (\Throwable $e) {
            \App\Utils\Logger::log("Warn parse UUID/PDF: ".$e->getMessage(), $logFile);
        }

        // Limpieza y verificaci├│n b├ísica de PDF
        if ($pdfB64) $pdfB64 = str_replace(["\r","\n"," "], '', $pdfB64);
        $isPdf = false;
        if ($pdfB64) {
            $bin = base64_decode($pdfB64, true);
            $isPdf = (is_string($bin) && strncmp($bin, '%PDF', 4) === 0);
        }
        if ($isPdf) {
            \App\Utils\Logger::log("PDF base64 len=".strlen($pdfB64)." (ok)", $logFile);
        } else {
            if ($pdfB64) \App\Utils\Logger::log("ÔÜá´©Å PDF capturado pero no valida como PDF.", $logFile);
            else         \App\Utils\Logger::log("ÔÜá´©Å No se encontr├│ PDF en la respuesta.", $logFile);
            $pdfB64 = null;
        }

        $result = [
            'ok'         => (bool)$uuid,
            'uuid'       => $uuid ?: null,
            'pdf_base64' => $pdfB64,                 // ÔåÉ listo para guardar en BD
            'raw'        => $respXml,
            'httpStatus' => $this->getLastHttpStatus(),
            'reference'  => $reference,
        ];
        \App\Utils\Logger::log("Resultado final (PDF): " . json_encode($result, JSON_UNESCAPED_UNICODE), $logFile);
        \App\Utils\Logger::log("=== [SUBMIT INVOICE (PDF) END] ===", $logFile);

        return $result;
    }

    /** ­ƒº® Helper para loggear request/response SOAP */
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

    public function requestTransaction(array $params): string
    {
        $soapUrl   = (string)$this->config->get('FEL_G4S_SOAP_URL', 'https://fel.g4sdocumenta.com/webservicefront/factwsfront.asmx');
        $soapAction= 'http://www.fact.com.mx/schema/ws/RequestTransaction';

        // Campos obligatorios seg├║n docs G4S
        $requestor = (string)$this->config->get('FEL_G4S_REQUESTOR', '');
        $country   = (string)$this->config->get('FEL_G4S_COUNTRY', 'GT');
        $entity    = (string)$this->config->get('FEL_G4S_ENTITY', '');
        $user      = (string)$this->config->get('FEL_G4S_USER', $requestor);
        $username  = (string)$this->config->get('FEL_G4S_USERNAME', 'TEMP');

        // Fallbacks defensivos: si vienen vacÃ­os del .env, usa valores conocidos por defecto
        if ($requestor === '') { $requestor = '425C5714-AA9E-4212-B4AA-75BD70328030'; }
        if ($entity === '')    { $entity    = '81491514'; }
        if ($user === '')      { $user      = $requestor; }

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

        // Logging (├║til para depurar)
        $logFile = __DIR__ . '/../../storage/fel_submit_invoice.log';
        \App\Utils\Logger::log("SOAP Request ÔåÆ\n".$envelope, $logFile);

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

    private function xmlEscape(string $s): string{
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
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

    /* ========================= DTE FEL Guatemala ========================= */

    /**
     * Builder m├¡nimo v├ílido (FACT, IVA 12%, 1 ├¡tem).
     * Ajusta campos de direcci├│n/correos/raz├│n social seg├║n tu emisor.
     */
    private function buildGuatemalaDTE(array $doc): string
    {
        // Fecha GT sin offset
        $tzGT   = new \DateTimeZone('America/Guatemala');
        $fechaG = new \DateTime('now', $tzGT);
        $fecha  = $fechaG->format('Y-m-d\TH:i:s');

        // ===== Emisor
        $nitEmisor    = trim($doc['emisor']['nit']     ?? '81491514');
        $nomEmisor    = $doc['emisor']['nombre']       ?? 'KAVOD, SOCIEDAD ANONIMA';
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
                // contin├║a con los datos originales si falla
            }
            if (!$nomRec) $nomRec = 'Receptor';
        }

        // ===== Documento / ├ìtem
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
        $granTotal        = $f6($totalBruto); // 6 decimales como en tu XML ÔÇ£perfectoÔÇØ

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
     * Convierte el total a letras al formato t├¡pico usado en Adenda:
     * - Exactos si no hay centavos.
     * - Si hay centavos: "QUETZALES CON nn/100".
     */
    /**
     * Convierte el total a letras para la Adenda.
     * Usa intl si existe; si no, usa un fallback nativo (0..999,999,999).
     */
    private function montoEnLetrasGT(float $monto): string
    {
        $entero = (int)floor($monto + 0.0000001);
        $cent   = (int)round(($monto - $entero) * 100);

        // A) Con intl (si est├í disponible)
        if (class_exists(\NumberFormatter::class)) {
            $fmt    = new \NumberFormatter('es_GT', \NumberFormatter::SPELLOUT);
            $letras = strtoupper($fmt->format($entero));
        } else {
            // B) Fallback sin intl
            $letras = strtoupper($this->spelloutEs($entero));
        }

        if ($cent === 0) {
            return "{$letras} QUETZALES EXACTOS";
        }
        $cc = str_pad((string)$cent, 2, '0', STR_PAD_LEFT);
        return "{$letras} QUETZALES CON {$cc}/100";
    }

    /** Fallback simple: n├║mero entero a letras en espa├▒ol (0..999,999,999) */
    private function spelloutEs(int $n): string
    {
        if ($n === 0) return 'cero';
        $u = ['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez','once','doce','trece','catorce','quince',
            'diecis├®is','diecisiete','dieciocho','diecinueve'];
        $d = ['','diez','veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
        $c = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];

        $numTo99 = function(int $x) use ($u,$d): string {
            if ($x < 20) return $u[$x];
            if ($x < 30) return $x === 20 ? 'veinte' : 'veinti'.str_replace('uno','├║n',$u[$x-20]); // veintiunoÔåÆveinti├║n (lo ajustamos abajo)
            $t = intdiv($x,10); $r = $x % 10;
            return $r ? $d[$t].' y '.$u[$r] : $d[$t];
        };

        $numTo999 = function(int $x) use ($c,$numTo99): string {
            if ($x == 100) return 'cien';
            $h = intdiv($x,100); $r = $x % 100;
            $head = $h ? $c[$h].($r?' ':'') : '';
            return $head.($r?$numTo99($r):'');
        };

        $parts = [];
        $millones = intdiv($n, 1_000_000); $resto = $n % 1_000_000;
        $miles    = intdiv($resto, 1_000); $unos  = $resto % 1_000;

        if ($millones) {
            $parts[] = ($millones===1 ? 'un mill├│n' : $this->fixUno($numTo999($millones)).' millones');
        }
        if ($miles) {
            $parts[] = ($miles===1 ? 'mil' : $this->fixUno($numTo999($miles)).' mil');
        }
        if ($unos) {
            $parts[] = $this->fixUno($numTo999($unos));
        }

        return implode(' ', $parts);
    }

    /** Ajustes ortogr├íficos simples: "uno"ÔåÆ"un" en posiciones compuestas, veintiunoÔåÆveinti├║n */
    private function fixUno(string $s): string
    {
        // veintiuno ÔåÆ veinti├║n
        $s = preg_replace('/\bveintiuno\b/u', 'veinti├║n', $s);
        // ... y "uno" final ÔåÆ "un"
        $s = preg_replace('/\buno\b$/u', 'un', $s);
        return $s;
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
                if ($sx === false) return [false, null, null, 'XML inv├ílido'];

                // Intento 1: respuesta POST/GET (ra├¡z <respuesta xmlns="http://tempuri.org/">)
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

            // -------- 1) Intento POST application/x-www-form-urlencoded (m├ís simple) --------
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

        // -------- 3) Intento SOAP 1.2 (├║ltimo recurso) --------
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

    public function submitInvoiceWithPdf(array $payload): array
    {
        $logFile = __DIR__ . '/../../storage/fel_submit_invoice_pdf.log';
        \App\Utils\Logger::log("=== [SUBMIT INVOICE (PDF) START] ===", $logFile);
        \App\Utils\Logger::log("Payload recibido: " . json_encode($payload, JSON_UNESCAPED_UNICODE), $logFile);

        // 1) Validaciones m├¡nimas
        $total = isset($payload['total']) ? (float)$payload['total'] : 0.0;
        if ($total <= 0) {
            $msg = "ÔØî Total debe ser > 0 para construir DTE (valor={$total})";
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

        // 3) Construir DTE (reconoce 'descripcion')
        try {
            $xmlDte = $this->buildGuatemalaDTE([
                'emisor' => [
                    'nit'             => $this->config->get('FEL_G4S_ENTITY', '81491514'),
                    'nombre'          => $this->config->get('EMISOR_NOMBRE', 'KAVOD, SOCIEDAD ANONIMA'),
                    'comercial'       => $this->config->get('EMISOR_COMERCIAL', 'PARQUEO OBELISCO REFORMA'),
                    'establecimiento' => $this->config->get('FEL_G4S_ESTABLECIMIENTO', '4'),
                    'direccion'       => [
                        'direccion'    => $this->config->get('EMISOR_DIR', '8 AVENIDA 15-46 ZONA 9, CIUDAD DE GUATEMALA'),
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
            \App\Utils\Logger::log("ÔØî Error generando XML DTE: " . $e->getMessage(), $logFile);
            throw $e;
        }

        // 4) Guarda XML para inspecci├│n
        $xmlPath = __DIR__ . '/../../storage/last_dte_pdf.xml';
        @file_put_contents($xmlPath, $xmlDte);
        \App\Utils\Logger::log("XML generado guardado en: {$xmlPath}", $logFile);

        // 5) SYSTEM_REQUEST con POST_DOCUMENT_SAT_PDF
        $reference = (string)($payload['ticket_no'] ?? ('FACT'.date('YmdHis')));

        $xmlNoBom = preg_replace('/^\xEF\xBB\xBF/', '', $xmlDte);
        $dataB64  = str_replace(["\r", "\n"], '', base64_encode($xmlNoBom));

        $params = [
            'Transaction' => 'SYSTEM_REQUEST',
            'Data1'       => 'POST_DOCUMENT_SAT_PDF',
            'Data2'       => $dataB64,
            'Data3'       => $reference,
        ];

        \App\Utils\Logger::log("ÔåÆ SYSTEM_REQUEST params: Data1=POST_DOCUMENT_SAT_PDF, Data2(Base64)=".strlen($dataB64)." bytes, Data3={$reference}", $logFile);

        try {
            $respXml = $this->requestTransaction($params);
        } catch (\Throwable $e) {
            \App\Utils\Logger::log("ÔØî Error en requestTransaction(): " . $e->getMessage(), $logFile);
            throw $e;
        }

        \App\Utils\Logger::log("SOAP Response:\n" . $respXml, $logFile);

        // 6) Extraer UUID y PDF base64
        $uuid   = null;
        $pdfB64 = null;

        try {
            // --- NUEVO: primero leer ResponseData1/2/3 del SOAP externo ---
            $sx = @simplexml_load_string($respXml);
            if ($sx) {
                // sin namespace expl├¡cito, buscar por nombre tambi├®n
                // 6.1 UUID en ResponseData2 (muchas respuestas lo traen all├¡)
                $respData2 = $sx->xpath('//ResponseData2');
                if ($respData2 && isset($respData2[0])) {
                    $uuidCandidate = trim((string)$respData2[0]);
                    if ($uuidCandidate !== '') {
                        $uuid = $uuidCandidate;
                    }
                }
                // 6.2 PDF en ResponseData3 (POST_DOCUMENT_SAT_PDF devuelve el PDF aqu├¡)
                $respData3 = $sx->xpath('//ResponseData3');
                if ($respData3 && isset($respData3[0])) {
                    $pdfB64Candidate = trim((string)$respData3[0]);
                    if ($pdfB64Candidate !== '') {
                        $pdfB64 = $pdfB64Candidate;
                    }
                }

                // Adem├ís, algunas implementaciones anidan XML en RequestTransactionResult
                $sx->registerXPathNamespace('ns0','http://www.fact.com.mx/schema/ws');
                $node = $sx->xpath('//ns0:RequestTransactionResult');
                if ($node && isset($node[0])) {
                    $inner = (string)$node[0];            // XML interno
                    $ix    = @simplexml_load_string($inner);

                    if ($ix) {
                        // UUID posibles
                        if (!$uuid) {
                            $uuid = (string)($ix->Response->UUID ?? '');
                            if ($uuid === '' && isset($ix->DocumentGUID)) $uuid = (string)$ix->DocumentGUID;
                            if ($uuid === '' && isset($ix->UUID))         $uuid = (string)$ix->UUID;
                        }

                        // Candidatos de PDF dentro del XML interno
                        if (!$pdfB64) {
                            $cands = [
                                (string)($ix->Response->PDF ?? ''),
                                (string)($ix->Response->Pdf ?? ''),
                                (string)($ix->PDF ?? ''),
                                (string)($ix->Pdf ?? ''),
                                (string)($ix->Response->PDFBase64 ?? ''),
                                (string)($ix->Response->Data1 ?? ''),
                            ];
                            foreach ($cands as $c) { $c = trim($c); if ($c !== '') { $pdfB64 = $c; break; } }
                            if (!$pdfB64 && isset($ix->Document)) {
                                $name = strtoupper((string)($ix->Document->Name ?? ''));
                                $data = (string)($ix->Document->Data ?? '');
                                if ($name === 'PDF' && $data !== '') $pdfB64 = trim($data);
                            }
                        }
                    } else {
                        // Regex sobre XML interno
                        if (!$uuid && preg_match('/<UUID>([^<]+)<\/UUID>/i', $inner, $m)) $uuid = $m[1];
                        if (!$uuid && preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/i', $inner, $m)) $uuid = $m[1];

                        if (!$pdfB64 && preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/\1>/i', $inner, $m)) {
                            $pdfB64 = trim($m[2]);
                        }
                        if (!$pdfB64 && preg_match('/<Data1>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data1>/i', $inner, $m)) {
                            $pdfB64 = trim($m[1]);
                        }
                        if (!$pdfB64 && preg_match('/<Data1>\s*<Value>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Value>\s*<\/Data1>/is', $inner, $m)) {
                            $pdfB64 = trim($m[1]);
                        }
                        if (!$pdfB64 && preg_match('/<Name>\s*PDF\s*<\/Name>.*?<Data>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data>/is', $inner, $m)) {
                            $pdfB64 = trim($m[1]);
                        }
                    }
                }
            }

            // Fallbacks sobre TODO el SOAP
            if (!$uuid && preg_match('/<UUID>([^<]+)<\/UUID>/i', $respXml, $m)) $uuid = $m[1];
            if (!$uuid && preg_match('/<DocumentGUID>([^<]+)<\/DocumentGUID>/i', $respXml, $m)) $uuid = $m[1];
            // ­ƒöº NUEVO: si no se captur├│ antes, intentar UUID directo desde <ResponseData2>
            if (!$uuid && preg_match('/<ResponseData2>\s*([^<]+)\s*<\/ResponseData2>/i', $respXml, $m)) {
                $uuid = trim($m[1]);
            }
            // ­ƒöº NUEVO: PDF directo en <ResponseData3>
            if (!$pdfB64 && preg_match('/<ResponseData3>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/ResponseData3>/i', $respXml, $m)) {
                $pdfB64 = trim($m[1]);
            }

            // Otros fallbacks ya existentes
            if (!$pdfB64) {
                if (preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/\1>/i', $respXml, $m)) {
                    $pdfB64 = trim($m[2]);
                }
                if (!$pdfB64 && preg_match('/<Data1>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data1>/i', $respXml, $m)) {
                    $pdfB64 = trim($m[1]);
                }
                if (!$pdfB64 && preg_match('/<Data1>\s*<Value>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Value>\s*<\/Data1>/is', $respXml, $m)) {
                    $pdfB64 = trim($m[1]);
                }
                if (!$pdfB64 && preg_match('/<Name>\s*PDF\s*<\/Name>.*?<Data>\s*([A-Za-z0-9+\/=\r\n]+)\s*<\/Data>/is', $respXml, $m)) {
                    $pdfB64 = trim($m[1]);
                }
            }
        } catch (\Throwable $e) {
            \App\Utils\Logger::log("Warn parse UUID/PDF: ".$e->getMessage(), $logFile);
        }

        // Limpieza de base64
        if ($pdfB64) $pdfB64 = str_replace(["\r","\n"," "], '', $pdfB64);

        // Si no vino PDF o no es PDF real, intenta descargarlo por GUID
        if ($uuid && !$this->isBase64Pdf($pdfB64)) {
            try {
                $pdfB64 = $this->fetchPdfByGuid($uuid, 'GET_DOCUMENT_SAT_PDF');
                if (!$this->isBase64Pdf($pdfB64)) {
                    $pdfB64 = $this->fetchPdfByGuid($uuid, 'GET_DOCUMENT_PDF');
                }
                if (!$this->isBase64Pdf($pdfB64)) {
                    $pdfB64 = $this->fetchPdfByGuid($uuid, 'GET_DOCUMENT');
                }
            } catch (\Throwable $e) {
                \App\Utils\Logger::log("PDF fallback error: ".$e->getMessage(), $logFile);
            }
        }

        // Ô£à Guarda el PDF localmente si vino en base64
        if ($uuid && $this->isBase64Pdf($pdfB64)) {
            $tzGT = new \DateTimeZone('America/Guatemala');
            $now  = new \DateTime('now', $tzGT);
            $dir  = sprintf('%s/fel/%s/%s',
                rtrim((string)$this->config->get('STORAGE_PATH', __DIR__.'/../../storage'), '/'),
                $now->format('Y'), $now->format('m')
            );
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $pdfPath = $dir.'/'.$uuid.'.pdf';
            @file_put_contents($pdfPath, base64_decode($pdfB64));
            \App\Utils\Logger::log("Ô£à PDF guardado en: {$pdfPath}", $logFile);
        }

        $result = [
            'ok'         => (bool)$uuid,
            'uuid'       => $uuid ?: null,
            'pdf_base64' => $pdfB64 ?: null,
            'raw'        => $respXml,
            'httpStatus' => $this->getLastHttpStatus(),
            'reference'  => $reference,
        ];
        \App\Utils\Logger::log("Resultado final (PDF): " . json_encode($result, JSON_UNESCAPED_UNICODE), $logFile);
        \App\Utils\Logger::log("=== [SUBMIT INVOICE (PDF) END] ===", $logFile);

        return $result;
    }

    // ┬┐es un PDF real?
    private function isBase64Pdf(?string $b64): bool {
        if (!$b64) return false;
        $s = preg_replace('/\s+/', '', $b64);
        if ($s === '') return false;
        $bin = base64_decode($s, true);
        if ($bin === false) return false;
        return strncmp($bin, "%PDF-", 5) === 0; // %PDF-
    }

    // Saca PDF por UUID probando varias ÔÇ£dialectosÔÇØ de G4S
    private function fetchPdfByGuid(string $uuid, string $prefer = 'GET_DOCUMENT_SAT_PDF'): ?string {
        $cands = [
            ['tx'=>'SYSTEM_REQUEST', 'd1'=>$prefer,               'd2'=>$uuid, 'd3'=>''],
            ['tx'=>'SYSTEM_REQUEST', 'd1'=>'GET_DOCUMENT_PDF',    'd2'=>$uuid, 'd3'=>''],
            ['tx'=>'SYSTEM_REQUEST', 'd1'=>'GET_DOCUMENT',        'd2'=>$uuid, 'd3'=>'PDF'],
            ['tx'=>'GET_DOCUMENT_SAT_PDF', 'd1'=>$uuid, 'd2'=>'',    'd3'=>''],
            ['tx'=>'GET_DOCUMENT_PDF',     'd1'=>$uuid, 'd2'=>'',    'd3'=>''],
            ['tx'=>'GET_DOCUMENT',         'd1'=>$uuid, 'd2'=>'PDF', 'd3'=>''],
        ];
        foreach ($cands as $c) {
            try {
                $resp = $this->requestTransaction([
                    'Transaction'=>$c['tx'],
                    'Data1'=>$c['d1'], 'Data2'=>$c['d2'], 'Data3'=>$c['d3'],
                ]);
                $b64 = $this->extractPdfBase64FromSoap($resp);
                if ($this->isBase64Pdf($b64)) return preg_replace('/\s+/', '', $b64);
            } catch (\Throwable $e) {}
        }
        return null;
    }

    // Extrae PDF base64 desde distintas variantes de SOAP G4S
    private function extractPdfBase64FromSoap(string $soap): ?string {
        $sx = @simplexml_load_string($soap);
        if ($sx) {
            $sx->registerXPathNamespace('ns0','http://www.fact.com.mx/schema/ws');
            $nodes = $sx->xpath('//ns0:RequestTransactionResult');
            if ($nodes && isset($nodes[0])) {
                $inner = (string)$nodes[0];
                $ix = @simplexml_load_string($inner);
                if ($ix) {
                    $cands = [
                        (string)($ix->Response->PDF ?? ''),
                        (string)($ix->Response->Pdf ?? ''),
                        (string)($ix->Response->PDFBase64 ?? ''),
                        (string)($ix->PDF ?? ''),
                        (string)($ix->Pdf ?? ''),
                        (string)($ix->Response->Data1 ?? ''),
                    ];
                    foreach ($cands as $c) {
                        $c = preg_replace('/\s+/', '', $c);
                        if ($this->isBase64Pdf($c)) return $c;
                    }
                    $d1nodes = $ix->xpath('//Data1|//Response/Data1');
                    foreach ($d1nodes ?: [] as $n) {
                        $val = preg_replace('/\s+/', '', (string)($n->Value ?? ''));
                        if ($this->isBase64Pdf($val)) return $val;
                    }
                    if (isset($ix->Document)) {
                        $name = strtoupper((string)($ix->Document->Name ?? ''));
                        $data = preg_replace('/\s+/', '', (string)($ix->Document->Data ?? ''));
                        if ($name === 'PDF' && $this->isBase64Pdf($data)) return $data;
                    }
                } else {
                    if (preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/\1>/i', $inner, $m)) {
                        $b = preg_replace('/\s+/', '', $m[2]);
                        if ($this->isBase64Pdf($b)) return $b;
                    }
                    if (preg_match('/<Data1>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Data1>/i', $inner, $m)) {
                        $b = preg_replace('/\s+/', '', $m[1]);
                        if ($this->isBase64Pdf($b)) return $b;
                    }
                    if (preg_match('/<Data1>\s*<Value>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Value>\s*<\/Data1>/is', $inner, $m)) {
                        $b = preg_replace('/\s+/', '', $m[1]);
                        if ($this->isBase64Pdf($b)) return $b;
                    }
                    if (preg_match('/<Name>\s*PDF\s*<\/Name>.*?<Data>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Data>/is', $inner, $m)) {
                        $b = preg_replace('/\s+/', '', $m[1]);
                        if ($this->isBase64Pdf($b)) return $b;
                    }
                }
            }
        }
        if (preg_match('/<(PDF|PDFBase64|Pdf)>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/\1>/i', $soap, $m)) {
            $b = preg_replace('/\s+/', '', $m[2]);
            if ($this->isBase64Pdf($b)) return $b;
        }
        if (preg_match('/<Data1>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Data1>/i', $soap, $m)) {
            $b = preg_replace('/\s+/', '', $m[1]);
            if ($this->isBase64Pdf($b)) return $b;
        }
        if (preg_match('/<Data1>\s*<Value>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Value>\s*<\/Data1>/is', $soap, $m)) {
            $b = preg_replace('/\s+/', '', $m[1]);
            if ($this->isBase64Pdf($b)) return $b;
        }
        if (preg_match('/<Name>\s*PDF\s*<\/Name>.*?<Data>\s*([A-Za-z0-9+\/=\r\n ]+)\s*<\/Data>/is', $soap, $m)) {
            $b = preg_replace('/\s+/', '', $m[1]);
            if ($this->isBase64Pdf($b)) return $b;
        }
        return null;
    }

    public function pdfFromUuidViaG4S(string $uuid): ?string
    {
        // Algunas instalaciones aceptan UUID en Data2:
        $params = [
            'Transaction' => 'SYSTEM_REQUEST',
            'Data1'       => 'POST_DOCUMENT_SAT_PDF', // literal que te mostr├│ G4S
            'Data2'       => $uuid,                    // UUID directo
            'Data3'       => (string)$this->config->get('FEL_G4S_DATA3', ''), // clave si aplica
        ];
        $soap = $this->requestTransaction($params);
        return $this->extractPdfBase64FromSoap($soap);
    }

    public function pdfFromXmlViaG4S(string $xml): ?string
    {
        $xmlNoBom = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        $b64      = str_replace(["\r","\n"], '', base64_encode($xmlNoBom));

        $params = [
            'Transaction' => 'SYSTEM_REQUEST',
            'Data1'       => 'POST_DOCUMENT_SAT_PDF',  // seg├║n tu captura
            'Data2'       => $b64,                      // XML en Base64
            'Data3'       => (string)$this->config->get('FEL_G4S_DATA3', ''), // clave/ref si tu emisor la pide
        ];
        $soap = $this->requestTransaction($params);
        return $this->extractPdfBase64FromSoap($soap);
    }

    public function g4sGetDocumentStatus(string $uuid): array
    {
        $uuid = trim($uuid ?? '');
        if ($uuid === '') {
            return [
                'ok'              => false,
                'uuid'            => null,
                'document_status' => null,
                'anulado'         => null,
                'error'           => 'UUID requerido',
            ];
        }

        // === Lee config directo, SIN getConfig() ===
        $config    = new \Config\Config(__DIR__ . '/../../.env');
        $entity    = (string) $config->get('FEL_G4S_ENTITY', '');
        $requestor = (string) $config->get('FEL_G4S_REQUESTOR', '');

        // Puede venir con ?wsdl, lo limpiamos
        $baseUrlRaw = (string) $config->get(
            'FEL_G4S_STATUS_WSDL',
            'https://fel.g4sdocumenta.com/WSConsultaDTE/WSConsultaDTE.asmx?wsdl'
        );

        $baseUrlNoQuery = preg_replace('~\?.*$~', '', $baseUrlRaw);
        $baseUrl        = rtrim($baseUrlNoQuery, '/');

        // Logs de depuraci├│n
        error_log('[G4S][STATUS][BASE_URL_RAW] ' . $baseUrlRaw);
        error_log('[G4S][STATUS][BASE_URL] ' . $baseUrl);

        if ($entity === '' || $requestor === '') {
            return [
                'ok'              => false,
                'uuid'            => $uuid,
                'document_status' => null,
                'anulado'         => null,
                'error'           => 'Faltan FEL_G4S_ENTITY o FEL_G4S_REQUESTOR',
            ];
        }

        // --- helper gen├®rico para parsear GET_INFODTE ---
        $parseXml = function (string $xml) {
            $sx = @simplexml_load_string($xml);
            if ($sx === false) {
                return [false, null, null, null, 'XML inv├ílido'];
            }

            error_log('[G4S][STATUS][RAW_XML] ' . substr($xml, 0, 500));

            // Buscar nodos *Response* por nombre local
            $respNodes = $sx->xpath('//*[contains(local-name(), "Response")]');
            if (!isset($respNodes[0])) {
                $respNodes = $sx->xpath('//*[contains(local-name(), "Result")]/*[contains(local-name(), "Response")]');
            }
            if (!isset($respNodes[0])) {
                return [false, null, null, null, 'No se encontraron nodos Response'];
            }

            $resp = $respNodes[0];

            $ok       = false;
            $uuidOut  = null;
            $estado   = null;
            $anulado  = null;
            $errStr   = null;

            foreach ($resp->children() as $child) {
                $name = strtoupper((string) $child->getName());
                $val  = trim((string) $child);

                if (strpos($name, 'RESULT') !== false) {
                    $ok = ($val === 'true' || $val === '1');
                }
                if (strpos($name, 'UUID') !== false) {
                    $uuidOut = $val;
                }
                if (strpos($name, 'ESTAD') !== false || strpos($name, 'STATUS') !== false) {
                    $estado = $val;
                }
                if (strpos($name, 'ANUL') !== false || strpos($name, 'ANULAC') !== false) {
                    $anulado = $val;
                }
                if (strpos($name, 'ERROR') !== false) {
                    $errStr = $val;
                }
            }

            return [$ok, $uuidOut ?: null, $estado ?: null, $anulado ?: null, $errStr ?: null];
        };

        // -------- 1) SOAP 1.1 --------
        try {
            $soapAction = 'http://tempuri.org/GET_INFODTE';
            $xmlReq = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                        xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                        xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
            <soap:Body>
                <GET_INFODTE xmlns="http://tempuri.org/">
                <UUID>{$uuid}</UUID>
                <Entity>{$entity}</Entity>
                <Requestor>{$requestor}</Requestor>
                </GET_INFODTE>
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
                CURLOPT_POSTFIELDS     => $xmlReq,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                throw new \RuntimeException($err ?: 'Fallo SOAP 1.1');
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('HTTP '.$code.' SOAP 1.1');
            }

            [$ok, $uuidOut, $estado, $anulado, $errStr] = $parseXml($resp);

            return [
                'ok'              => $ok,
                'uuid'            => $uuidOut ?: $uuid,
                'document_status' => $estado,
                'anulado'         => $anulado,
                'error'           => $errStr,
                'raw_xml'         => $resp,
            ];
        } catch (\Throwable $e) {
            error_log('[G4S][STATUS][SOAP11] ' . $e->getMessage());
        }

        // -------- 2) SOAP 1.2 --------
        try {
            $xmlReq = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                            xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
            <soap12:Body>
                <GET_INFODTE xmlns="http://tempuri.org/">
                <UUID>{$uuid}</UUID>
                <Entity>{$entity}</Entity>
                <Requestor>{$requestor}</Requestor>
                </GET_INFODTE>
            </soap12:Body>
            </soap12:Envelope>
            XML;

            $ch = curl_init($baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
                CURLOPT_POSTFIELDS     => $xmlReq,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                throw new \RuntimeException($err ?: 'Fallo SOAP 1.2');
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('HTTP '.$code.' SOAP 1.2');
            }

            [$ok, $uuidOut, $estado, $anulado, $errStr] = $parseXml($resp);

            return [
                'ok'              => $ok,
                'uuid'            => $uuidOut ?: $uuid,
                'document_status' => $estado,
                'anulado'         => $anulado,
                'error'           => $errStr,
                'raw_xml'         => $resp,
            ];
        } catch (\Throwable $e) {
            error_log('[G4S][STATUS][SOAP12] ' . $e->getMessage());
        }

        return [
            'ok'              => false,
            'uuid'            => $uuid,
            'document_status' => null,
            'anulado'         => null,
            'error'           => 'No se pudo consultar el estado del DTE',
        ];
    }
    
    public function syncInvoiceStatus(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $uuid = trim((string)($_GET['uuid'] ?? ''));
    error_log('[G4S][STATUS_SYNC][START] uuid=' . $uuid);

    if ($uuid === '') {
        error_log('[G4S][STATUS_SYNC][ERROR] UUID vac├¡o');
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'Par├ímetro uuid es requerido',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        // 1) Consultar estado en G4S
        $res = $this->g4sGetDocumentStatus($uuid);
        error_log('[G4S][STATUS_SYNC][G4S_RES] ' . json_encode($res, JSON_UNESCAPED_UNICODE));

        $docStatus  = strtoupper(trim((string)($res['document_status'] ?? '')));
        $anuladoRaw = trim((string)($res['anulado'] ?? ''));
        $errorMsg   = $res['error'] ?? null;

        if (!$res['ok'] && $docStatus === '') {
            error_log('[G4S][STATUS_SYNC][ERROR] G4S no devolvi├│ estado v├ílido. error=' . $errorMsg);
            echo json_encode([
                'ok'              => false,
                'uuid'            => $uuid,
                'document_status' => $docStatus,
                'anulado'         => $anuladoRaw,
                'error'           => $errorMsg ?: 'No se pudo obtener el estado',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2) Determinar si est├í anulado
        $anulado = in_array(strtoupper($anuladoRaw), ['1','TRUE','SI','S','Y','ANULADO'], true)
                || (strpos($docStatus, 'ANUL') !== false);

        // 3) Mapear al campo status de nuestra tabla invoices
        $invoiceStatus = 'PENDING';
        if ($anulado || in_array($docStatus, ['ANULADO','RECHAZADO','ERROR'], true)) {
            $invoiceStatus = 'ERROR';
        } elseif (in_array($docStatus, ['EMITIDO','ACEPTADO','CERTIFICADO','VIGENTE'], true)) {
            $invoiceStatus = 'OK';
        }

        error_log(sprintf(
            '[G4S][STATUS_SYNC][MAP] uuid=%s doc_status=%s anulado=%s mapped_status=%s',
            $uuid,
            $docStatus,
            $anulado ? '1' : '0',
            $invoiceStatus
        ));

        // 4) Actualizar BD
        $dbUpdated = false;
        try {
            $pdo = \App\DB::getConnection();
            error_log('[G4S][STATUS_SYNC][DB] Conexi├│n OK, actualizando invoices...');

            $sql = <<<SQL
UPDATE invoices
   SET status          = :status,
       document_status = :doc_status,
       updated_at      = NOW()
 WHERE fel_uuid = :uuid
    OR uuid     = :uuid
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':status'     => $invoiceStatus,
                ':doc_status' => $docStatus,
                ':uuid'       => $uuid,
            ]);
            $dbUpdated = $stmt->rowCount() > 0;

            error_log(sprintf(
                '[G4S][STATUS_SYNC][DB] rowCount=%d dbUpdated=%s',
                $stmt->rowCount(),
                $dbUpdated ? '1' : '0'
            ));
        } catch (\Throwable $e) {
            error_log('[G4S][STATUS_SYNC][DB_ERROR] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok'              => false,
                'uuid'            => $uuid,
                'document_status' => $docStatus,
                'invoice_status'  => $invoiceStatus,
                'anulado'         => $anulado,
                'db_updated'      => false,
                'error'           => 'Estado obtenido pero no se pudo actualizar la BD: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 5) Respuesta final OK
        error_log(sprintf(
            '[G4S][STATUS_SYNC][DONE] uuid=%s doc_status=%s invoice_status=%s anulado=%s db_updated=%s',
            $uuid,
            $docStatus,
            $invoiceStatus,
            $anulado ? '1' : '0',
            $dbUpdated ? '1' : '0'
        ));

        echo json_encode([
            'ok'              => true,
            'uuid'            => $uuid,
            'document_status' => $docStatus,
            'invoice_status'  => $invoiceStatus,
            'anulado'         => $anulado,
            'db_updated'      => $dbUpdated,
            'error'           => $errorMsg,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        // Cualquier excepci├│n inesperada en todo el flujo
        error_log('[G4S][STATUS_SYNC][FATAL] ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'uuid'  => $uuid,
            'error' => 'Excepci├│n en syncInvoiceStatus: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}


}

