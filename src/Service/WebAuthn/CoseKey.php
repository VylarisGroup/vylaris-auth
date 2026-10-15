<?php
declare(strict_types=1);

namespace Vylaris\Service\WebAuthn;

/** Wandelt COSE-Schlüssel (ES256 / RS256) in PEM um; kein eigenes Krypto – Prüfung via OpenSSL. */
final class CoseKey
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /** @return array{pem:string,alg:int} */
    public static function toPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === self::ALG_ES256) {
            $crv = $cose[-1] ?? null;
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if ($crv !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new WebAuthnException('Ungültiger ES256-Schlüssel.');
            }
            // SubjectPublicKeyInfo für prime256v1 (feste DER-Präfixbytes) + unkomprimierter Punkt
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            $pem = self::pem($der);
            if (openssl_pkey_get_public($pem) === false) {
                throw new WebAuthnException('Der öffentliche Schlüssel liegt nicht auf der Kurve.');
            }
            return ['pem' => $pem, 'alg' => self::ALG_ES256];
        }

        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e) || strlen($n) < 256 || strlen($n) > 1024 || strlen($e) < 1 || strlen($e) > 8) {
                throw new WebAuthnException('Ungültiger RS256-Schlüssel.');
            }
            $rsaKey = self::der(0x30, self::derInt($n) . self::derInt($e));
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $spki = self::der(0x30, $algId . self::der(0x03, "\x00" . $rsaKey));
            $pem = self::pem($spki);
            if (openssl_pkey_get_public($pem) === false) {
                throw new WebAuthnException('Ungültiger RSA-Schlüssel.');
            }
            return ['pem' => $pem, 'alg' => self::ALG_RS256];
        }

        throw new WebAuthnException('Nicht unterstützter Schlüsseltyp/Algorithmus.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 128) {
            $l = chr($len);
        } else {
            $b = ltrim(pack('N', $len), "\x00");
            $l = chr(0x80 | strlen($b)) . $b;
        }
        return chr($tag) . $l . $content;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return self::der(0x02, $bytes);
    }
}
