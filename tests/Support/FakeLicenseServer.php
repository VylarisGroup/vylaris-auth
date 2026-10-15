<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Core\Clock;
use Vylaris\Social\{HttpClient, HttpResponse};

/** Simulierter TRS-License-Server (signierte Antworten, Gerätelimit, Sperren, Ausfall). */
final class FakeLicenseServer implements HttpClient
{
    public string $publicKeyPem;
    private string $secret;
    public string $publicRaw;
    /** @var array<string,array<string,mixed>> */
    public array $licenses = [];
    public bool $down = false;
    public bool $badSignature = false;
    public int $skew = 0;
    /** @var string[] */
    public array $calls = [];

    public function __construct(public string $product = 'vylarisauth')
    {
        $kp = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($kp);
        $this->publicRaw = sodium_crypto_sign_publickey($kp);
        $this->publicKeyPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . $this->publicRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public function add(string $key, int $maxDevices = 2, array $features = ['social_login', 'passkeys'], ?int $expires = null, string $product = ''): void
    {
        $this->licenses[$key] = ['product' => $product ?: $this->product, 'plan' => 'Pro', 'maxDevices' => $maxDevices, 'features' => $features, 'expires' => $expires, 'status' => 'active', 'devices' => []];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if ($this->down) {
            throw new \RuntimeException('Verbindung fehlgeschlagen');
        }
        $endpoint = basename($url);
        $in = json_decode((string) $body, true) ?? [];
        $key = (string) ($in['key'] ?? '');
        $hwid = (string) ($in['hwid'] ?? '');
        $this->calls[] = $endpoint . ':' . $key . ($hwid !== '' ? ':' . $hwid : '');
        $l = $this->licenses[$key] ?? null;
        $code = 'OK';
        if ($l === null) {
            $code = 'NOT_FOUND';
        } elseif (($in['product'] ?? '') !== '' && $in['product'] !== $l['product']) {
            $code = 'WRONG_PRODUCT';
        } elseif ($l['status'] === 'blocked') {
            $code = 'BLOCKED';
        } elseif ($l['expires'] !== null && $l['expires'] <= Clock::now()) {
            $code = 'EXPIRED';
        } elseif ($endpoint !== 'validate' && $hwid === '') {
            $code = 'HWID_REQUIRED';
        } elseif ($endpoint === 'activate') {
            if (!isset($l['devices'][$hwid]) && count($l['devices']) >= $l['maxDevices']) {
                $code = 'DEVICE_LIMIT';
            } else {
                $l['devices'][$hwid] = $in['name'] ?? '';
            }
        } elseif ($endpoint === 'deactivate') {
            unset($l['devices'][$hwid]);
        } elseif (($endpoint === 'heartbeat' || ($endpoint === 'validate' && $hwid !== '')) && !isset($l['devices'][$hwid])) {
            $code = 'NOT_ACTIVATED';
        }
        if ($l !== null) {
            $this->licenses[$key] = $l;
        }
        $lic = null;
        if ($l !== null && $code !== 'WRONG_PRODUCT') {
            $lic = ['key' => $key, 'product' => $l['product'], 'plan' => $l['plan'], 'trial' => false,
                'expiresAt' => $l['expires'] !== null ? gmdate('Y-m-d\TH:i:s.000\Z', $l['expires']) : null,
                'maxDevices' => $l['maxDevices'], 'devices' => count($l['devices']), 'features' => $l['features']];
        }
        $payload = json_encode(['valid' => $code === 'OK', 'code' => $code, 'license' => $lic, 'ts' => Clock::now() + $this->skew]);
        $sig = sodium_crypto_sign_detached($payload, $this->secret);
        if ($this->badSignature) {
            $sig[0] = $sig[0] ^ "\x01";
        }
        return new HttpResponse(200, json_encode(['valid' => $code === 'OK', 'code' => $code, 'license' => $lic, 'payload' => $payload, 'signature' => base64_encode($sig)]));
    }
}
