<?php
declare(strict_types=1);

namespace Vylaris\Social;

/**
 * Produktiver HTTP-Client: ausschließlich HTTPS, TLS-Prüfung immer an, keine Redirects,
 * kurze Timeouts, Antwortgröße begrenzt. Nutzt cURL, sonst PHP-Streams.
 */
final class CurlHttpClient implements HttpClient
{
    private const MAX_BYTES = 1048576; // 1 MiB

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Nur HTTPS-URLs sind erlaubt.');
        }
        $hasUa = false;
        foreach ($headers as $h) {
            if (stripos($h, 'user-agent:') === 0) {
                $hasUa = true;
            }
        }
        if (!$hasUa) {
            $headers[] = 'User-Agent: VylarisAuth'; // Aufrufer können einen eigenen Wert setzen (z. B. Discord)
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $ips = $this->publicAddresses($host);
        return function_exists('curl_init') ? $this->viaCurl($method, $url, $headers, $body, $host, $ips) : $this->viaStream($method, $url, $headers, $body);
    }

    /**
     * SSRF-Schutz: der Host muss ausschließlich auf öffentliche Adressen auflösen.
     * Die geprüften Adressen werden bei cURL fest verdrahtet (kein DNS-Rebinding zwischen Prüfung und Verbindung).
     * @return string[]
     */
    private function publicAddresses(string $host): array
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new \RuntimeException('Host nicht auflösbar.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                throw new \RuntimeException('Ziel liegt in einem nicht öffentlichen Adressbereich.');
            }
        }
        return $ips;
    }

    private function viaCurl(string $method, string $url, array $headers, ?string $body, string $host, array $ips): HttpResponse
    {
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . implode(',', $ips)],
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$buf): int {
                $buf .= $chunk;
                return strlen($buf) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $err);
        }
        return new HttpResponse($status, $buf);
    }

    private function viaStream(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        if ($data === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return new HttpResponse($status, $data);
    }
}
