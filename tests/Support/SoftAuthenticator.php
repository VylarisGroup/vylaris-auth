<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Service\WebAuthn\Verifier;

/** Software-Authenticator für Tests (erzeugt gültige und gezielt manipulierte WebAuthn-Antworten). */
final class SoftAuthenticator
{
    private \OpenSSLAsymmetricKey $key;
    public string $credentialId;
    public int $signCount = 0;

    public function __construct(private string $alg = 'ES256')
    {
        $this->credentialId = random_bytes(32);
        $this->key = $alg === 'ES256'
            ? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])
            : openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public function credentialIdB64(): string
    {
        return Verifier::b64uEncode($this->credentialId);
    }

    // ------------------------------------------------------------ CBOR-Encoder (nur was wir brauchen)

    private static function head(int $major, int $n): string
    {
        if ($n < 24) {
            return chr(($major << 5) | $n);
        }
        if ($n < 256) {
            return chr(($major << 5) | 24) . chr($n);
        }
        if ($n < 65536) {
            return chr(($major << 5) | 25) . pack('n', $n);
        }
        return chr(($major << 5) | 26) . pack('N', $n);
    }

    public static function cInt(int $n): string
    {
        return $n >= 0 ? self::head(0, $n) : self::head(1, -1 - $n);
    }

    public static function cBytes(string $s): string
    {
        return self::head(2, strlen($s)) . $s;
    }

    public static function cText(string $s): string
    {
        return self::head(3, strlen($s)) . $s;
    }

    /** @param array<int,array{0:string,1:string}> $pairs bereits kodierte Schlüssel/Werte */
    public static function cMap(array $pairs): string
    {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$k, $v]) {
            $out .= $k . $v;
        }
        return $out;
    }

    private function coseKey(): string
    {
        $d = openssl_pkey_get_details($this->key);
        if ($this->alg === 'ES256') {
            return self::cMap([
                [self::cInt(1), self::cInt(2)], [self::cInt(3), self::cInt(-7)], [self::cInt(-1), self::cInt(1)],
                [self::cInt(-2), self::cBytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT))],
                [self::cInt(-3), self::cBytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))],
            ]);
        }
        return self::cMap([
            [self::cInt(1), self::cInt(3)], [self::cInt(3), self::cInt(-257)],
            [self::cInt(-1), self::cBytes($d['rsa']['n'])], [self::cInt(-2), self::cBytes($d['rsa']['e'])],
        ]);
    }

    private function authData(string $rpId, int $flags, int $count, ?string $attested = null): string
    {
        return hash('sha256', $rpId, true) . chr($flags) . pack('N', $count) . ($attested ?? '');
    }

    // ------------------------------------------------------------ Antworten

    /** @param array<string,mixed> $o Überschreibungen: challenge, origin, rpId, flags, type, rawId */
    public function register(array $options, string $origin, array $o = []): array
    {
        $rpId = $o['rpId'] ?? $options['rp']['id'];
        $flags = $o['flags'] ?? (Verifier::FLAG_UP | Verifier::FLAG_UV | Verifier::FLAG_AT);
        $attested = str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->coseKey();
        $authData = $this->authData($rpId, $flags, $this->signCount, $attested);
        $attObj = self::cMap([
            [self::cText('fmt'), self::cText('none')],
            [self::cText('attStmt'), self::head(5, 0)],
            [self::cText('authData'), self::cBytes($authData)],
        ]);
        $clientData = json_encode([
            'type' => $o['type'] ?? 'webauthn.create',
            'challenge' => $o['challenge'] ?? $options['challenge'],
            'origin' => $origin,
        ]);
        return [
            'id' => $this->credentialIdB64(),
            'rawId' => $o['rawId'] ?? $this->credentialIdB64(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Verifier::b64uEncode($clientData),
                'attestationObject' => Verifier::b64uEncode($attObj),
            ],
        ];
    }

    /** @param array<string,mixed> $o Überschreibungen: challenge, origin, rpId, flags, count, tamper, userHandle, type */
    public function assertion(array $options, string $origin, ?string $userHandle = null, array $o = []): array
    {
        $rpId = $o['rpId'] ?? $options['rpId'];
        $flags = $o['flags'] ?? (Verifier::FLAG_UP | Verifier::FLAG_UV);
        $this->signCount = $o['count'] ?? $this->signCount + 1;
        $authData = $this->authData($rpId, $flags, $this->signCount);
        $clientData = json_encode([
            'type' => $o['type'] ?? 'webauthn.get',
            'challenge' => $o['challenge'] ?? $options['challenge'],
            'origin' => $origin,
        ]);
        openssl_sign($authData . hash('sha256', $clientData, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        if (!empty($o['tamper'])) {
            $sig[strlen($sig) - 1] = chr(ord($sig[strlen($sig) - 1]) ^ 0x01);
        }
        $resp = [
            'clientDataJSON' => Verifier::b64uEncode($clientData),
            'authenticatorData' => Verifier::b64uEncode($authData),
            'signature' => Verifier::b64uEncode($sig),
        ];
        if ($userHandle !== null) {
            $resp['userHandle'] = $userHandle;
        }
        return ['id' => $this->credentialIdB64(), 'rawId' => $this->credentialIdB64(), 'type' => 'public-key', 'response' => $resp];
    }
}
