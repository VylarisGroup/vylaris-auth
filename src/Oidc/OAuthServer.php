<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

use Vylaris\Core\{Clock, Config, Database, Request};
use Vylaris\Service\{AuditLogger, SessionService};

/**
 * OAuth-2.0-/OIDC-Server-Kern: Authorization-Code-Ausstellung, Token-Endpunkt (authorization_code, refresh_token)
 * und Access-Token-Prüfung. Zugrunde liegen RFC 6749, RFC 7636 (PKCE), RFC 9700 (Security BCP) und OIDC Core 1.0.
 * Codes/Refresh-Tokens sind undurchsichtige Zufallswerte (nur als SHA-256 gespeichert), Access- und ID-Token sind RS256-JWTs.
 */
final class OAuthServer
{
    public function __construct(
        private Database $db,
        private Config $config,
        private ClientService $clients,
        private KeyService $keys,
        private ClaimsService $claims,
        private SessionService $sessions,
        private AuditLogger $audit,
    ) {
    }

    private function ttl(string $key, int $default): int
    {
        return max(10, (int) $this->config->get('oidc.' . $key, $default));
    }

    private static function random(int $bytes = 32): string
    {
        return Jwt::b64u(random_bytes($bytes));
    }

    // ------------------------------------------------------------------ Authorization Code

    /** @param string[] $scopes */
    public function issueCode(array $client, int $userId, array $scopes, string $redirectUri, string $codeChallenge, ?string $nonce, ?string $sessionHash, int $authTime): string
    {
        $code = self::random();
        $this->db->insert('oauth_auth_codes', [
            'id' => hash('sha256', $code),
            'client_pk' => (int) $client['id'],
            'user_id' => $userId,
            'session_id' => $sessionHash,
            'scopes' => implode(' ', $scopes),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'nonce' => $nonce !== '' ? $nonce : null,
            'auth_time' => Clock::db($authTime),
            'expires_at' => Clock::db(Clock::now() + $this->ttl('auth_code_ttl', 60)),
        ]);
        return $code;
    }

    // ------------------------------------------------------------------ Token Endpoint

    /** @return array{0:int,1:array,2:array} [HTTP-Status, JSON-Body, Header] */
    public function token(Request $q): array
    {
        $headers = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
        $err = static fn (string $e, string $d, int $s = 400, array $h = []) => [$s, ['error' => $e, 'error_description' => $d], $h + $headers];

        $grant = $q->str('grant_type');
        [$client, $authError] = $this->authenticateClient($q);
        if ($client === null) {
            $this->audit->log('oidc.token_rejected', null, ['error' => 'invalid_client', 'grant' => mb_substr($grant, 0, 40)], null, mb_substr($q->str('client_id'), 0, 80) ?: null);
            return $err('invalid_client', 'Client-Authentifizierung fehlgeschlagen.', 401, $authError);
        }
        if (!$this->clients->isUsable($client)) {
            return $err('invalid_client', 'Client ist deaktiviert.', 401);
        }

        $result = match ($grant) {
            'authorization_code' => $this->grantCode($q, $client),
            'refresh_token' => $this->grantRefresh($q, $client),
            default => ['unsupported_grant_type', 'Der Grant-Typ wird nicht unterstützt.'],
        };
        if (isset($result[0]) && is_string($result[0])) {
            $this->audit->log('oidc.token_rejected', null, ['error' => $result[0], 'grant' => mb_substr($grant, 0, 40)], null, $client['client_id']);
            return $err($result[0], $result[1]);
        }
        $this->audit->log('oidc.token_issued', null, ['grant' => $grant], null, $client['client_id']);
        return [200, $result, $headers];
    }

    /** @return array{0:?array,1:array} [Client, WWW-Authenticate-Header] */
    private function authenticateClient(Request $q): array
    {
        $id = $q->str('client_id');
        $secret = $q->str('client_secret');
        $viaBasic = false;
        $h = $q->header('Authorization') ?? ($q->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (is_string($h) && preg_match('/^Basic\s+([A-Za-z0-9+\/]+=*)$/', $h, $m)) {
            $pair = base64_decode($m[1], true);
            if ($pair !== false && str_contains($pair, ':')) {
                [$bid, $bsecret] = explode(':', $pair, 2);
                $id = rawurldecode($bid);
                $secret = rawurldecode($bsecret);
                $viaBasic = true;
            }
        }
        $fail = [null, $viaBasic ? ['WWW-Authenticate' => 'Basic realm="VylarisAuth"'] : []];
        if ($id === '' || mb_strlen($id) > 80) {
            return $fail;
        }
        $client = $this->clients->findByClientId($id);
        if ($client === null) {
            $this->clients->verifySecret(['is_confidential' => 1, 'secret_hash' => ''], $secret); // konstanter Aufwand
            return $fail;
        }
        if ((int) $client['is_confidential'] === 1 && $secret === '') {
            return $fail;
        }
        return $this->clients->verifySecret($client, $secret) ? [$client, []] : $fail;
    }

    private function grantCode(Request $q, array $client): array
    {
        $code = $q->str('code');
        $verifier = $q->str('code_verifier');
        if ($code === '' || strlen($code) > 200) {
            return ['invalid_request', 'code fehlt.'];
        }
        $row = $this->db->one('SELECT * FROM oauth_auth_codes WHERE id = ?', [hash('sha256', $code)]);
        if ($row === null || (int) $row['client_pk'] !== (int) $client['id']) {
            return ['invalid_grant', 'Der Autorisierungscode ist ungültig.'];
        }
        // Einmalverwendung atomar durchsetzen; Replay widerruft alles, was aus diesem Code entstanden ist (RFC 6749 §4.1.2).
        $consumed = $this->db->run('UPDATE oauth_auth_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [Clock::db(), $row['id']])->rowCount();
        if ($consumed !== 1) {
            $now = Clock::db();
            $this->db->run('UPDATE oauth_access_tokens SET revoked_at = ? WHERE code_id = ? AND revoked_at IS NULL', [$now, $row['id']]);
            $this->db->run('UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE code_id = ? AND revoked_at IS NULL', [$now, $row['id']]);
            $this->audit->log('oidc.code_replay', (int) $row['user_id'], [], null, $client['client_id']);
            return ['invalid_grant', 'Der Autorisierungscode ist ungültig.'];
        }
        if (Clock::fromDb($row['expires_at']) <= Clock::now()) {
            return ['invalid_grant', 'Der Autorisierungscode ist abgelaufen.'];
        }
        if (!hash_equals($row['redirect_uri'], $q->str('redirect_uri'))) {
            return ['invalid_grant', 'redirect_uri stimmt nicht überein.'];
        }
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)
            || !hash_equals((string) $row['code_challenge'], Jwt::b64u(hash('sha256', $verifier, true)))) {
            return ['invalid_grant', 'PKCE-Prüfung fehlgeschlagen.'];
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if ($user === null || $this->isLocked($user)
            || ($row['session_id'] !== null && !$this->sessions->isActive($row['session_id'], (int) $row['user_id']))) {
            return ['invalid_grant', 'Die Anmeldung ist nicht mehr gültig.'];
        }

        $scopes = array_values(array_filter(explode(' ', (string) $row['scopes'])));
        $access = $this->newAccessToken($client, $user, $scopes, $row['session_id'], $row['id']);
        $body = ['token_type' => 'Bearer', 'expires_in' => $access['ttl'], 'access_token' => $access['jwt'], 'scope' => implode(' ', $scopes)];
        if (in_array('openid', $scopes, true)) {
            $body['id_token'] = $this->idToken($client, $user, $scopes, $row['nonce'], Clock::fromDb($row['auth_time']));
        }
        if (in_array('offline_access', $scopes, true)) {
            $body['refresh_token'] = $this->newRefreshToken($client, (int) $user['id'], $scopes, $access['id'], $row['id']);
        }
        return $body;
    }

    private function grantRefresh(Request $q, array $client): array
    {
        $token = $q->str('refresh_token');
        if ($token === '' || strlen($token) > 200) {
            return ['invalid_request', 'refresh_token fehlt.'];
        }
        $row = $this->db->one('SELECT * FROM oauth_refresh_tokens WHERE id = ?', [hash('sha256', $token)]);
        if ($row === null || (int) $row['client_pk'] !== (int) $client['id']) {
            return ['invalid_grant', 'Das Refresh Token ist ungültig.'];
        }
        if ($row['revoked_at'] !== null) {
            // Wiederverwendung eines rotierten Tokens: Diebstahl annehmen, alle Tokens dieses Benutzers für den Client widerrufen.
            $now = Clock::db();
            foreach (['oauth_refresh_tokens', 'oauth_access_tokens'] as $t) {
                $this->db->run("UPDATE $t SET revoked_at = ? WHERE user_id = ? AND client_pk = ? AND revoked_at IS NULL", [$now, $row['user_id'], $row['client_pk']]);
            }
            $this->audit->log('oidc.refresh_reuse_detected', (int) $row['user_id'], [], null, $client['client_id']);
            return ['invalid_grant', 'Das Refresh Token ist ungültig.'];
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (Clock::fromDb($row['expires_at']) <= Clock::now() || $user === null || $this->isLocked($user)) {
            return ['invalid_grant', 'Das Refresh Token ist ungültig.'];
        }
        $granted = array_values(array_filter(explode(' ', (string) $row['scopes'])));
        $requested = array_values(array_filter(explode(' ', $q->str('scope'))));
        if ($requested && array_diff($requested, $granted)) {
            return ['invalid_scope', 'Der angeforderte Scope übersteigt die ursprüngliche Freigabe.'];
        }
        $scopes = $requested ?: $granted;

        // Rotation: altes Token atomar entwerten (parallele Nutzung gewinnt genau einmal).
        $new = self::random();
        $done = $this->db->run('UPDATE oauth_refresh_tokens SET revoked_at = ?, replaced_by = ? WHERE id = ? AND revoked_at IS NULL', [Clock::db(), hash('sha256', $new), $row['id']])->rowCount();
        if ($done !== 1) {
            return ['invalid_grant', 'Das Refresh Token ist ungültig.'];
        }
        $access = $this->newAccessToken($client, $user, $scopes, null, $row['code_id']);
        $this->storeRefresh($client, (int) $user['id'], $granted, $access['id'], $row['code_id'], $new);
        return ['token_type' => 'Bearer', 'expires_in' => $access['ttl'], 'access_token' => $access['jwt'], 'refresh_token' => $new, 'scope' => implode(' ', $scopes)];
    }

    private function isLocked(array $user): bool
    {
        return $user['status'] === 'locked' || ($user['locked_until'] !== null && Clock::fromDb($user['locked_until']) > Clock::now());
    }

    // ------------------------------------------------------------------ Token-Ausstellung

    /** @return array{jwt:string,id:string,ttl:int} */
    private function newAccessToken(array $client, array $user, array $scopes, ?string $sessionHash, ?string $codeId): array
    {
        $key = $this->keys->ensureActiveKey();
        $ttl = $this->ttl('access_token_ttl', 600);
        $jti = bin2hex(random_bytes(16));
        $now = Clock::now();
        $jwt = Jwt::sign(['typ' => 'at+jwt', 'kid' => $key['kid']], [
            'iss' => $this->config->baseUrl(), 'sub' => $this->claims->sub((int) $user['id']), 'aud' => $client['client_id'],
            'client_id' => $client['client_id'], 'scope' => implode(' ', $scopes), 'jti' => $jti, 'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl,
        ], $this->keys->privatePem($key));
        $id = hash('sha256', $jti);
        $this->db->insert('oauth_access_tokens', [
            'id' => $id, 'client_pk' => (int) $client['id'], 'user_id' => (int) $user['id'], 'session_id' => $sessionHash, 'code_id' => $codeId,
            'scopes' => implode(' ', $scopes), 'expires_at' => Clock::db($now + $ttl), 'created_at' => Clock::db($now),
        ]);
        return ['jwt' => $jwt, 'id' => $id, 'ttl' => $ttl];
    }

    private function newRefreshToken(array $client, int $userId, array $scopes, string $accessId, ?string $codeId): string
    {
        $token = self::random();
        $this->storeRefresh($client, $userId, $scopes, $accessId, $codeId, $token);
        return $token;
    }

    private function storeRefresh(array $client, int $userId, array $scopes, string $accessId, ?string $codeId, string $token): void
    {
        $this->db->insert('oauth_refresh_tokens', [
            'id' => hash('sha256', $token), 'access_token_id' => $accessId, 'client_pk' => (int) $client['id'], 'user_id' => $userId,
            // offline_access: bewusst nicht an die Browser-Sitzung gebunden; Widerruf über "Verbundene Anwendungen",
            // Passwortänderung/-reset, Kontosperre oder Client-Widerruf.
            'session_id' => null, 'code_id' => $codeId, 'scopes' => implode(' ', $scopes),
            'expires_at' => Clock::db(Clock::now() + $this->ttl('refresh_token_ttl', 2592000)), 'created_at' => Clock::db(),
        ]);
    }

    private function idToken(array $client, array $user, array $scopes, ?string $nonce, int $authTime): string
    {
        $key = $this->keys->ensureActiveKey();
        $now = Clock::now();
        $claims = [
            'iss' => $this->config->baseUrl(), 'aud' => $client['client_id'], 'iat' => $now, 'nbf' => $now,
            'exp' => $now + $this->ttl('id_token_ttl', 600), 'jti' => bin2hex(random_bytes(16)), 'auth_time' => $authTime,
        ] + $this->claims->forUser($user, $scopes, $client);
        if ($nonce !== null && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }
        return Jwt::sign(['typ' => 'JWT', 'kid' => $key['kid']], $claims, $this->keys->privatePem($key));
    }

    // ------------------------------------------------------------------ Access-Token-Prüfung (UserInfo, API)

    /** @return array{user:array,client:array,scopes:string[]}|null null = ungültig (Signatur, Ablauf, Widerruf, Sperre, ...) */
    public function validateAccessToken(string $jwt): ?array
    {
        $parsed = Jwt::parse($jwt);
        if ($parsed === null) {
            return null;
        }
        [$header, $claims, $sig, $input] = $parsed;
        $kid = (string) ($header['kid'] ?? '');
        $ok = false;
        foreach ($this->keys->verificationKeys() as $k) {
            if (hash_equals($k['kid'], $kid) && Jwt::verifySignature($header, $sig, $input, $k['public_pem'])) {
                $ok = true;
                break;
            }
        }
        $now = Clock::now();
        if (!$ok || ($claims['iss'] ?? null) !== $this->config->baseUrl() || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_string($claims['jti'] ?? null) || ($header['typ'] ?? '') !== 'at+jwt') {
            return null;
        }
        $row = $this->db->one('SELECT * FROM oauth_access_tokens WHERE id = ?', [hash('sha256', $claims['jti'])]);
        if ($row === null || $row['revoked_at'] !== null || Clock::fromDb($row['expires_at']) <= $now) {
            return null;
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        $client = $this->clients->findByPk((int) $row['client_pk']);
        if ($user === null || $client === null || $this->isLocked($user) || !$this->clients->isUsable($client)
            || !hash_equals((string) $client['client_id'], (string) ($claims['aud'] ?? ''))) {
            return null;
        }
        return ['user' => $user, 'client' => $client, 'scopes' => array_values(array_filter(explode(' ', (string) $row['scopes'])))];
    }
}
