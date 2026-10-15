<?php
declare(strict_types=1);

/**
 * Minimaler OIDC-Relying-Party-Baustein (ohne Bibliotheken) – Referenz für die Integration.
 * In Produktion empfiehlt sich eine geprüfte OIDC-Client-Bibliothek der jeweiligen Plattform;
 * dieser Code zeigt, welche Prüfungen zwingend sind.
 */

function b64u_decode(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) {
        throw new RuntimeException('Ungültiges Base64url');
    }
    return $r;
}

function b64u_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function pkce_pair(): array
{
    $verifier = b64u_encode(random_bytes(48));
    return [$verifier, b64u_encode(hash('sha256', $verifier, true))];
}

/** DER-Länge kodieren. */
function der_len(int $n): string
{
    return $n < 128 ? chr($n) : (($n < 256) ? "\x81" . chr($n) : "\x82" . chr($n >> 8) . chr($n & 255));
}

function der_int(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 127) {
        $bin = "\0" . $bin;
    }
    return "\x02" . der_len(strlen($bin)) . $bin;
}

/** RSA-JWK (n, e) → PEM (SubjectPublicKeyInfo). */
function jwk_to_pem(array $jwk): string
{
    if (($jwk['kty'] ?? '') !== 'RSA') {
        throw new RuntimeException('Nur RSA-Schlüssel unterstützt');
    }
    $seq = der_int(b64u_decode($jwk['n'])) . der_int(b64u_decode($jwk['e']));
    $rsaKey = "\x30" . der_len(strlen($seq)) . $seq;
    $bit = "\x03" . der_len(strlen($rsaKey) + 1) . "\0" . $rsaKey;
    $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . der_len(strlen($algo . $bit)) . $algo . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Prüft ein ID-Token vollständig: Format, Algorithmus (nur RS256), Signatur gegen JWKS (per kid),
 * iss, aud, exp, iat/nbf (mit Toleranz), nonce und optional azp.
 *
 * @param array $jwks JWKS-Dokument ({"keys":[...]})
 * @return array Claims
 * @throws RuntimeException bei jeder Abweichung
 */
function verify_id_token(string $jwt, array $jwks, string $issuer, string $clientId, string $nonce, ?int $now = null, int $leeway = 60): array
{
    $now ??= time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Kein JWT');
    }
    $header = json_decode(b64u_decode($parts[0]), true);
    $claims = json_decode(b64u_decode($parts[1]), true);
    if (!is_array($header) || !is_array($claims)) {
        throw new RuntimeException('JWT nicht lesbar');
    }
    if (($header['alg'] ?? '') !== 'RS256') {          // blockt "none" und HS256-Verwechslung
        throw new RuntimeException('Algorithmus nicht erlaubt');
    }
    $jwk = null;
    foreach ($jwks['keys'] ?? [] as $k) {
        if (($k['kid'] ?? null) === ($header['kid'] ?? '') && ($k['use'] ?? 'sig') === 'sig') {
            $jwk = $k;
        }
    }
    if ($jwk === null) {
        throw new RuntimeException('Unbekannte Schlüssel-ID');
    }
    $ok = openssl_verify($parts[0] . '.' . $parts[1], b64u_decode($parts[2]), jwk_to_pem($jwk), OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        throw new RuntimeException('Signatur ungültig');
    }
    if (!hash_equals($issuer, (string) ($claims['iss'] ?? ''))) {
        throw new RuntimeException('Issuer ungültig');
    }
    $aud = (array) ($claims['aud'] ?? []);
    if (!in_array($clientId, $aud, true)) {
        throw new RuntimeException('Audience ungültig');
    }
    if (count($aud) > 1 && ($claims['azp'] ?? '') !== $clientId) {
        throw new RuntimeException('azp ungültig');
    }
    if (!isset($claims['exp']) || (int) $claims['exp'] + $leeway < $now) {
        throw new RuntimeException('Token abgelaufen');
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] - $leeway > $now) {
        throw new RuntimeException('Token noch nicht gültig');
    }
    if (!isset($claims['iat']) || (int) $claims['iat'] - $leeway > $now) {
        throw new RuntimeException('iat ungültig');
    }
    if ($nonce === '' || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        throw new RuntimeException('Nonce ungültig');
    }
    if (empty($claims['sub'])) {
        throw new RuntimeException('sub fehlt');
    }
    return $claims;
}

function http_json(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $opts['headers'] ?? ['Accept: application/json'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    if (isset($opts['userpwd'])) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $opts['userpwd']);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($data) ? $data : []];
}
