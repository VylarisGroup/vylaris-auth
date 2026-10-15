<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Core\Clock;
use Vylaris\Oidc\Jwt;
use Vylaris\Social\{HttpClient, HttpResponse};

/**
 * Simuliert externe Identitätsanbieter (Google, Microsoft, GitHub, Discord und einen generischen OIDC-Anbieter)
 * im Prozess – ohne Netzwerk. Prüft dabei wie ein echter Anbieter: client_id/-secret, redirect_uri, PKCE (S256), Einmal-Codes.
 */
final class FakeIdp implements HttpClient
{
    public const SECRET = 'geheimes-client-secret';
    public const KEY_OIDC = 'https://idp.example.org';

    /** @var array<string,array> */
    private array $codes = [];
    /** @var array<string,array> */
    private array $tokens = [];
    /** @var string[] */
    public array $log = [];
    public bool $genericUsesBasicAuth = false;
    public int $jwksFetches = 0;

    private string $privatePem;
    private array $jwk;
    private string $kid = 'k1';
    public ?string $attackerPem = null;

    public function __construct()
    {
        $this->newKey();
    }

    public function newKey(): void
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $pem);
        $this->privatePem = $pem;
        $this->kid = 'k' . bin2hex(random_bytes(3));
        $this->jwk = $this->jwkOf($k, $this->kid);
    }

    private function jwkOf($key, string $kid): array
    {
        $d = openssl_pkey_get_details($key)['rsa'];
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => Jwt::b64u($d['n']), 'e' => Jwt::b64u($d['e'])];
    }

    public static function attackerKey(): string
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $pem);
        return $pem;
    }

    /**
     * Der Benutzer meldet sich beim Anbieter an und stimmt zu: liefert die Callback-Parameter (code, state).
     * @param array $profile OIDC: Claim-Überschreibungen; GitHub: id/login/name/emails; Discord: id/username/email/verified
     */
    public function authorize(string $authUrl, array $profile, ?string $clientId = 'client-123'): array
    {
        $u = parse_url($authUrl);
        parse_str($u['query'] ?? '', $q);
        $host = $u['host'] === 'accounts.google.com' ? 'oauth2.googleapis.com' : $u['host'];
        if (($q['client_id'] ?? null) !== $clientId || ($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || empty($q['code_challenge']) || empty($q['state'])) {
            throw new \RuntimeException('Ungültige Autorisierungsanfrage: ' . $authUrl);
        }
        $code = 'code-' . bin2hex(random_bytes(8));
        $this->codes[$code] = ['host' => $host, 'client_id' => $clientId, 'redirect_uri' => $q['redirect_uri'], 'challenge' => $q['code_challenge'], 'nonce' => $q['nonce'] ?? null, 'profile' => $profile, 'scope' => $q['scope'] ?? ''];
        return ['code' => $code, 'state' => $q['state']];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->log[] = $method . ' ' . $url;
        $p = parse_url($url);
        $host = $p['host'];
        $path = $p['path'] ?? '/';

        if ($path === '/.well-known/openid-configuration' && $host === 'idp.example.org') {
            return $this->j(['issuer' => self::KEY_OIDC, 'authorization_endpoint' => self::KEY_OIDC . '/authorize', 'token_endpoint' => self::KEY_OIDC . '/token',
                'jwks_uri' => self::KEY_OIDC . '/jwks', 'token_endpoint_auth_methods_supported' => $this->genericUsesBasicAuth ? ['client_secret_basic'] : ['client_secret_post', 'client_secret_basic']]);
        }
        if (in_array($path, ['/jwks', '/oauth2/v3/certs', '/common/discovery/v2.0/keys'], true)) {
            $this->jwksFetches++;
            return $this->j(['keys' => [$this->jwk]]);
        }
        if ($method === 'POST' && (in_array($path, ['/token', '/login/oauth/access_token', '/api/oauth2/token'], true) || str_ends_with($path, '/oauth2/v2.0/token'))) {
            return $this->token($host, $headers, (string) $body);
        }
        if ($host === 'api.github.com' || ($host === 'discord.com' && $path === '/api/users/@me')) {
            return $this->api($host, $path, $headers);
        }
        return new HttpResponse(404, 'not found');
    }

    private function token(string $host, array $headers, string $body): HttpResponse
    {
        parse_str($body, $f);
        $id = $f['client_id'] ?? null;
        $secret = $f['client_secret'] ?? null;
        foreach ($headers as $h) {
            if (stripos($h, 'Authorization: Basic ') === 0) {
                [$id, $secret] = array_map('rawurldecode', explode(':', base64_decode(substr($h, 21)), 2));
            }
        }
        $code = (string) ($f['code'] ?? '');
        $c = $this->codes[$code] ?? null;
        unset($this->codes[$code]); // Einmal-Code
        $verifier = (string) ($f['code_verifier'] ?? '');
        if ($c === null || $c['host'] !== $host || $id !== $c['client_id'] || $secret !== self::SECRET || ($f['redirect_uri'] ?? '') !== $c['redirect_uri']
            || ($f['grant_type'] ?? '') !== 'authorization_code' || !hash_equals($c['challenge'], Jwt::b64u(hash('sha256', $verifier, true)))) {
            return $this->j(['error' => 'invalid_grant'], 400);
        }
        $access = 'at-' . bin2hex(random_bytes(6));
        $this->tokens[$access] = $c['profile'];
        $out = ['access_token' => $access, 'token_type' => 'Bearer'];
        if (!in_array($host, ['github.com', 'discord.com'], true)) {
            $out['id_token'] = $this->idToken($host, $c);
        }
        return $this->j($out);
    }

    private function idToken(string $host, array $c): string
    {
        $now = Clock::now();
        $profile = $c['profile'];
        $tid = $profile['tid'] ?? '11111111-2222-3333-4444-555555555555';
        $iss = match ($host) {
            'accounts.google.com', 'oauth2.googleapis.com' => 'https://accounts.google.com',
            'login.microsoftonline.com' => 'https://login.microsoftonline.com/' . $tid . '/v2.0',
            default => self::KEY_OIDC,
        };
        $claims = $profile + [
            'iss' => $iss, 'aud' => $c['client_id'], 'sub' => 'sub-1', 'iat' => $now, 'exp' => $now + 600, 'nonce' => $c['nonce'],
        ];
        if ($host === 'login.microsoftonline.com') {
            $claims += ['tid' => $tid];
        }
        $claims = array_filter($claims, static fn ($v) => $v !== null);
        $pem = $this->attackerPem ?? $this->privatePem;
        return Jwt::sign(['typ' => 'JWT', 'kid' => $this->kid], $claims, $pem);
    }

    private function api(string $host, string $path, array $headers): HttpResponse
    {
        $tok = null;
        foreach ($headers as $h) {
            if (stripos($h, 'Authorization: Bearer ') === 0) {
                $tok = substr($h, 22);
            }
        }
        $profile = $this->tokens[$tok] ?? null;
        if ($profile === null) {
            return $this->j(['message' => 'Bad credentials'], 401);
        }
        if ($host === 'api.github.com') {
            return $path === '/user'
                ? $this->j(['id' => $profile['id'], 'login' => $profile['login'] ?? 'octo', 'name' => $profile['name'] ?? null])
                : $this->j($profile['emails'] ?? []);
        }
        return $this->j(['id' => $profile['id'], 'username' => $profile['username'] ?? 'disc', 'global_name' => $profile['global_name'] ?? null, 'email' => $profile['email'] ?? null, 'verified' => $profile['verified'] ?? false]);
    }

    private function j(array $d, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($d));
    }
}
