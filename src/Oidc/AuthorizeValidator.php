<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

/**
 * Vorprüfung des Authorization-Requests (unabhängig von der OAuth-Bibliothek):
 * Client + exakte Redirect-URI zuerst (fatal), danach PKCE S256, state, nonce, Scopes, prompt.
 * Die Protokollabwicklung (Code, Token, PKCE-Verifier) übernimmt OAuthServer.
 */
final class AuthorizeValidator
{
    public function __construct(private ClientService $clients, private bool $requireNonce = true)
    {
    }

    public function validate(array $q): AuthorizeRequest
    {
        $clientId = is_string($q['client_id'] ?? null) ? $q['client_id'] : '';
        $client = $clientId !== '' ? $this->clients->findByClientId($clientId) : null;
        if ($client === null || !$this->clients->isUsable($client)) {
            return new AuthorizeRequest(fatal: 'Unbekannte oder deaktivierte Anwendung.');
        }
        $redirect = is_string($q['redirect_uri'] ?? null) ? $q['redirect_uri'] : '';
        if ($redirect === '' || !RedirectUri::matches($redirect, $this->clients->uris((int) $client['id'], 'redirect'))) {
            return new AuthorizeRequest(client: $client, fatal: 'Die Redirect-URI ist für diese Anwendung nicht registriert.');
        }

        $state = is_string($q['state'] ?? null) ? $q['state'] : '';
        $fail = static fn (string $err, string $desc) => new AuthorizeRequest(client: $client, redirectUri: $redirect, state: $state, error: $err, errorDescription: $desc);

        if (isset($q['request']) || isset($q['request_uri'])) {
            return $fail('request_not_supported', 'Request-Objekte werden nicht unterstützt.');
        }
        if (($q['response_type'] ?? null) !== 'code') {
            return $fail('unsupported_response_type', 'Nur response_type=code wird unterstützt.');
        }
        if ($state === '' || strlen($state) > 512) {
            return $fail('invalid_request', 'Der Parameter state ist erforderlich (max. 512 Zeichen).');
        }
        $challenge = $q['code_challenge'] ?? null;
        if (!is_string($challenge) || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
            return $fail('invalid_request', 'PKCE ist erforderlich: code_challenge fehlt oder ist ungültig.');
        }
        if (($q['code_challenge_method'] ?? null) !== 'S256') {
            return $fail('invalid_request', 'Nur code_challenge_method=S256 wird unterstützt.');
        }
        $nonce = is_string($q['nonce'] ?? null) ? $q['nonce'] : '';
        if ($this->requireNonce && ($nonce === '' || strlen($nonce) > 255)) {
            return $fail('invalid_request', 'Der Parameter nonce ist erforderlich (max. 255 Zeichen).');
        }
        if (strlen($nonce) > 255) {
            return $fail('invalid_request', 'nonce ist zu lang.');
        }

        $scopes = preg_split('/\s+/', trim((string) ($q['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = array_values(array_unique($scopes));
        if (!in_array('openid', $scopes, true)) {
            return $fail('invalid_scope', 'Der Scope openid ist erforderlich.');
        }
        $allowed = explode(' ', (string) $client['allowed_scopes']);
        $bad = array_values(array_diff($scopes, $allowed));
        if ($bad) {
            return $fail('invalid_scope', 'Nicht erlaubte Scopes: ' . implode(' ', array_map(static fn ($s) => mb_substr($s, 0, 40), $bad)));
        }

        $prompt = preg_split('/\s+/', trim((string) ($q['prompt'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($prompt as $p) {
            if (!in_array($p, ['none', 'login', 'consent', 'select_account'], true)) {
                return $fail('invalid_request', 'Ungültiger prompt-Wert.');
            }
        }
        if (in_array('none', $prompt, true) && count($prompt) > 1) {
            return $fail('invalid_request', 'prompt=none darf nicht kombiniert werden.');
        }

        $maxAge = null;
        if (isset($q['max_age'])) {
            if (!is_string($q['max_age']) || !ctype_digit($q['max_age']) || strlen($q['max_age']) > 9) {
                return $fail('invalid_request', 'Ungültiges max_age.');
            }
            $maxAge = (int) $q['max_age'];
        }

        return new AuthorizeRequest($client, $redirect, $scopes, $state, $nonce, $prompt, $maxAge);
    }

    /** Fehler-Redirect-URL (query), ergänzt um iss (RFC 9207). */
    public static function errorRedirect(string $redirectUri, string $error, ?string $description, string $state, string $issuer): string
    {
        $params = ['error' => $error];
        if ($description !== null) {
            $params['error_description'] = $description;
        }
        if ($state !== '') {
            $params['state'] = $state;
        }
        $params['iss'] = $issuer;
        return self::appendQuery($redirectUri, $params);
    }

    public static function appendQuery(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
