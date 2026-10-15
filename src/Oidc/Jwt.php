<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

/** Minimale JWS-Kompaktserialisierung, ausschließlich RS256 (Signatur/Prüfung über OpenSSL). */
final class Jwt
{
    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? null : $r;
    }

    public static function sign(array $header, array $claims, string $privatePem): string
    {
        $header = ['alg' => 'RS256'] + $header;
        $input = self::b64u(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            . '.' . self::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('JWT-Signatur fehlgeschlagen.');
        }
        return $input . '.' . self::b64u($sig);
    }

    /** Zerlegt ein JWT ohne Prüfung (für die Schlüsselauswahl). @return array{0:array,1:array,2:string,3:string}|null [header, claims, signature, signingInput] */
    public static function parse(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 8192) {
            return null;
        }
        $h = self::b64uDecode($parts[0]);
        $c = self::b64uDecode($parts[1]);
        $s = self::b64uDecode($parts[2]);
        if ($h === null || $c === null || $s === null) {
            return null;
        }
        $header = json_decode($h, true);
        $claims = json_decode($c, true);
        if (!is_array($header) || !is_array($claims)) {
            return null;
        }
        return [$header, $claims, $s, $parts[0] . '.' . $parts[1]];
    }

    /** Prüft die Signatur; erlaubt nur alg=RS256. */
    public static function verifySignature(array $header, string $signature, string $signingInput, string $publicPem): bool
    {
        if (($header['alg'] ?? null) !== 'RS256') {
            return false;
        }
        return openssl_verify($signingInput, $signature, $publicPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
