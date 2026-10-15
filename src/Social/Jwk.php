<?php
declare(strict_types=1);

namespace Vylaris\Social;

use Vylaris\Oidc\Jwt;

/** Wandelt ein RSA-JWK (kty=RSA) in einen PEM-Public-Key (SubjectPublicKeyInfo) um. */
final class Jwk
{
    public static function rsaToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            return null;
        }
        $n = Jwt::b64uDecode($jwk['n']);
        $e = Jwt::b64uDecode($jwk['e']);
        if ($n === null || $e === null || strlen($n) < 256) { // mind. 2048 Bit
            return null;
        }
        $rsaKey = self::seq(self::int($n) . self::int($e));
        $algo = self::seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::seq($algo . "\x03" . self::len(strlen($rsaKey) + 1) . "\x00" . $rsaKey);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function len(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function seq(string $c): string
    {
        return "\x30" . self::len(strlen($c)) . $c;
    }

    private static function int(string $v): string
    {
        $v = ltrim($v, "\x00");
        if ($v === '' || (ord($v[0]) & 0x80)) {
            $v = "\x00" . $v;
        }
        return "\x02" . self::len(strlen($v)) . $v;
    }
}
