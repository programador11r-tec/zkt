<?php
declare(strict_types=1);

namespace App\Services;

use Config\Config;
use App\Utils\Logger;

class ZKTecoClient {
    private string $baseUrl;
    private string $appKey;
    private string $appSecret;

    public function __construct(Config $config) {
        $this->baseUrl = rtrim($config->get('ZKTECO_BASE_URL', ''), '/');
        $this->appKey = (string)$config->get('ZKTECO_APP_KEY', '');
        $this->appSecret = (string)$config->get('ZKTECO_APP_SECRET', '');
    }

    public function getDummyAttendance(): array {
        // Placeholder for real API call
        Logger::info('ZKTecoClient.getDummyAttendance called');
        return [
            ['userId' => 101, 'name' => 'Ana Gomez', 'checkIn' => '2025-09-25T08:01:00-06:00', 'checkOut' => '2025-09-25T17:12:00-06:00'],
            ['userId' => 102, 'name' => 'Luis Perez', 'checkIn' => '2025-09-25T08:15:00-06:00', 'checkOut' => '2025-09-25T16:55:00-06:00'],
        ];
    }

    // Example for future real request
    public function request(string $path, array $query = []): array {
        $url = $this->baseUrl . $path . ($query ? ('?' . http_build_query($query)) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-App-Key: ' . $this->appKey,
                'X-App-Signature: ' . hash_hmac('sha256', $url, $this->appSecret),
            ]
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            Logger::error('ZKTeco request failed', ['error' => curl_error($ch)]);
            return [];
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $data = json_decode($res, true);
            return is_array($data) ? $data : [];
        }
        Logger::error('ZKTeco non-2xx', ['code' => $code, 'body' => $res]);
        return [];
    }
    
    public function listEntries(?string $sinceIso = null): array {
        $path = '/api/v1/parking/tickets'; // p.ej. tickets de entrada/salida
        $params = [];
        if ($sinceIso) $params['since'] = $sinceIso;

        $data = $this->request('GET', $path, $params);
        // Normalizar a nuestra estructura
        $out = [];
        foreach (($data['data'] ?? $data ?? []) as $row) {
            $out[] = [
                'ticket_no'   => $row['ticketNo'] ?? $row['code'] ?? null,
                'plate'       => $row['plateNo'] ?? $row['plate'] ?? null,
                'entry_time'  => $row['inTime'] ?? $row['entryTime'] ?? null,
                'exit_time'   => $row['outTime'] ?? $row['exitTime'] ?? null,
                'status'      => $row['status'] ?? 'OPEN',
                'source_id'   => $row['id'] ?? null,
                'raw_payload' => $row,
            ];
        }
        return $out;
    }

    public function listPayments(?string $sinceIso = null): array {
        // EJEMPLO de endpoint (ajústalo a tu API real)
        $path = '/api/v1/parking/payments';
        $params = [];
        if ($sinceIso) $params['since'] = $sinceIso;

        $data = $this->request('GET', $path, $params);
        $out = [];
        foreach (($data['data'] ?? $data ?? []) as $row) {
            $out[] = [
                'ticket_no'   => $row['ticketNo'] ?? null,
                'amount'      => $row['amount'] ?? 0,
                'method'      => $row['method'] ?? null,
                'paid_at'     => $row['paidTime'] ?? $row['payTime'] ?? null,
                'raw_payload' => $row,
            ];
        }
        return $out;
    }

}