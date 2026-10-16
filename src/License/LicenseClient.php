<?php
declare(strict_types=1);

namespace Vylaris\License;

use Vylaris\Core\Clock;
use Vylaris\Social\HttpClient;

/**
 * Client der TRS-License-Prüf-API (siehe docs/LIZENZ.md).
 *
 * Jede Antwort wird mit dem fest konfigurierten Ed25519-Schlüssel geprüft (libsodium); ausgewertet werden
 * ausschließlich die Daten aus dem signierten "payload", nie die unsignierten Felder daneben. Antworten, deren
 * Zeitstempel mehr als 5 Minuten abweicht, und Antworten zu einem anderen als dem angefragten Schlüssel werden abgelehnt.
 */
final class LicenseClient
{
    private const MAX_SKEW = 300;
    private ?string $rawKey = null;

    public function __construct(private HttpClient $http, private string $baseUrl, private string $publicKeyPem, private string $product)
    {
    }

    public function configured(): bool
    {
        return trim($this->publicKeyPem) !== '' && $this->baseUrl !== '';
    }

    /** Kurzer Fingerabdruck des konfigurierten öffentlichen Schlüssels (zur Kontrolle im Admin-Bereich). */
    public function fingerprint(): string
    {
        try {
            return substr(hash('sha256', $this->publicKey()), 0, 16);
        } catch (LicenseException) {
            return '';
        }
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function product(): string
    {
        return $this->product;
    }

    /** @throws LicenseException UNAVAILABLE / NOT_CONFIGURED */
    public function validate(string $key, ?string $hwid = null): LicenseReply
    {
        return $this->call('validate', $key, $hwid, null);
    }

    public function activate(string $key, string $hwid, string $name): LicenseReply
    {
        return $this->call('activate', $key, $hwid, $name);
    }

    public function heartbeat(string $key, string $hwid): LicenseReply
    {
        return $this->call('heartbeat', $key, $hwid, null);
    }

    public function deactivate(string $key, string $hwid): LicenseReply
    {
        return $this->call('deactivate', $key, $hwid, null);
    }

    /** Rohe 32 Byte des Ed25519-Schlüssels aus PEM (SPKI) oder reinem Base64. */
    private function publicKey(): string
    {
        if ($this->rawKey !== null) {
            return $this->rawKey;
        }
        $b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->publicKeyPem) ?? '';
        $der = base64_decode($b64, true);
        if ($der === false) {
            throw new LicenseException('Der eingebaute Lizenz-Schlüssel ist ungültig.', 'NOT_CONFIGURED');
        }
        if (strlen($der) === 44 && str_starts_with($der, "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00")) {
            $der = substr($der, 12);
        }
        if (strlen($der) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new LicenseException('Der eingebaute Lizenz-Schlüssel ist kein Ed25519-Schlüssel.', 'NOT_CONFIGURED');
        }
        return $this->rawKey = $der;
    }

    private function call(string $endpoint, string $key, ?string $hwid, ?string $name): LicenseReply
    {
        if (!$this->configured()) {
            throw new LicenseException('Das Lizenzsystem ist nicht eingerichtet (Lizenz-Schlüssel fehlt).', 'NOT_CONFIGURED');
        }
        $pub = $this->publicKey();
        $body = ['key' => $key, 'product' => $this->product];
        if ($hwid !== null) {
            $body['hwid'] = $hwid;
        }
        if ($name !== null) {
            $body['name'] = mb_substr($name, 0, 80);
        }
        try {
            $res = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/api/v1/' . $endpoint, ['Content-Type: application/json', 'Accept: application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new LicenseException('Der Lizenzserver ist nicht erreichbar. Bitte später erneut versuchen.');
        }
        $j = $res->json();
        if ($res->status !== 200 || $j === null) {
            throw new LicenseException($res->status === 429 ? 'Der Lizenzserver ist überlastet. Bitte später erneut versuchen.' : 'Der Lizenzserver hat unerwartet geantwortet.');
        }
        $payload = $j['payload'] ?? null;
        $sig = base64_decode((string) ($j['signature'] ?? ''), true);
        if (!is_string($payload) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $payload, $pub)) {
            throw new LicenseException('Die Antwort des Lizenzservers hat keine gültige Signatur.', 'BAD_SIGNATURE');
        }
        $d = json_decode($payload, true);
        if (!is_array($d) || !isset($d['ts']) || !is_numeric($d['ts']) || abs(Clock::now() - (int) $d['ts']) > self::MAX_SKEW) {
            throw new LicenseException('Die Antwort des Lizenzservers ist veraltet oder fehlerhaft (Uhrzeit des Servers prüfen).', 'STALE_RESPONSE');
        }
        $code = (string) ($d['code'] ?? '');
        $lic = is_array($d['license'] ?? null) ? $d['license'] : null;
        if ($lic !== null && strcasecmp((string) ($lic['key'] ?? ''), $key) !== 0) {
            // Signierte Antwort zu einem anderen Schlüssel (Replay) -> nicht verwenden.
            throw new LicenseException('Die Antwort des Lizenzservers passt nicht zur Anfrage.', 'BAD_SIGNATURE');
        }
        $features = [];
        foreach ((array) ($lic['features'] ?? []) as $f) {
            if (is_string($f) && preg_match('/^[a-z0-9_.-]{1,40}$/i', $f)) {
                $features[] = strtolower($f);
            }
        }
        return new LicenseReply(
            ($d['valid'] ?? false) === true && $code === 'OK',
            $code,
            $key,
            mb_substr((string) ($lic['plan'] ?? ''), 0, 80),
            array_values(array_unique($features)),
            isset($lic['expiresAt']) && is_string($lic['expiresAt']) ? $lic['expiresAt'] : null,
            isset($lic['maxDevices']) ? (int) $lic['maxDevices'] : null,
            isset($lic['devices']) ? (int) $lic['devices'] : null,
            ($lic['trial'] ?? false) === true,
        );
    }
}
