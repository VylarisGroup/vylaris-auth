<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Oidc\{AuthorizeRequest, AuthorizeValidator};

/** OpenID Connect Provider: Discovery, JWKS, Authorization Code Flow (PKCE), Token, UserInfo, Logout. */
final class OidcController extends BaseController
{
    private AuthorizeValidator $validator;

    public function __construct(\Vylaris\Core\App $app)
    {
        parent::__construct($app);
        $this->validator = new AuthorizeValidator($app->clients, (bool) $app->config->get('oidc.require_nonce', true));
    }

    public function routes(Router $r): void
    {
        $r->get('/.well-known/openid-configuration', fn (Request $q) => $this->discovery($q));
        // Alias für Hoster, die Pfade mit führendem Punkt blockieren (403): in Clients als „Metadata-URL“ angeben.
        $r->get('/oidc/discovery', fn (Request $q) => $this->discovery($q));
        $r->get('/oidc/jwks', fn (Request $q) => $this->jwks($q));
        $r->get('/oidc/authorize', fn (Request $q) => $this->authorize($q));
        $r->post('/oidc/consent', fn (Request $q) => $this->consent($q));
        $r->post('/oidc/token', fn (Request $q) => $this->token($q));
        $r->get('/oidc/userinfo', fn (Request $q) => $this->userinfo($q));
        $r->post('/oidc/userinfo', fn (Request $q) => $this->userinfo($q));
        $r->get('/oidc/logout', fn (Request $q) => $this->logoutPage($q));
        $r->post('/oidc/logout', fn (Request $q) => $this->logout($q));
        foreach (['/oidc/token', '/oidc/userinfo', '/oidc/jwks', '/oidc/discovery', '/.well-known/openid-configuration'] as $p) {
            $r->add('OPTIONS', $p, fn (Request $q) => $this->preflight($q));
        }
        // Verbundene Anwendungen im Konto
        $r->get('/account/apps', fn (Request $q) => $this->apps($q));
        $r->post('/account/apps/revoke', fn (Request $q) => $this->revokeApp($q));
    }

    // ------------------------------------------------------------------ Metadaten

    public function discovery(Request $q): Response
    {
        $iss = $this->app->config->baseUrl();
        return $this->cors($q, Response::json([
            'issuer' => $iss,
            'authorization_endpoint' => $iss . '/oidc/authorize',
            'token_endpoint' => $iss . '/oidc/token',
            'userinfo_endpoint' => $iss . '/oidc/userinfo',
            'jwks_uri' => $iss . '/oidc/jwks',
            'end_session_endpoint' => $iss . '/oidc/logout',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'roles', 'offline_access'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'updated_at', 'email', 'email_verified', 'roles'],
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'authorization_response_iss_parameter_supported' => true,
            'prompt_values_supported' => ['none', 'login', 'consent'],
        ], 200, ['Cache-Control' => 'public, max-age=300']));
    }

    public function jwks(Request $q): Response
    {
        $this->app->keys->ensureActiveKey();
        return $this->cors($q, Response::json($this->app->keys->jwks(), 200, ['Cache-Control' => 'public, max-age=300']));
    }

    // ------------------------------------------------------------------ CORS (nur registrierte Redirect-Origins)

    private function allowedOrigin(Request $q): ?string
    {
        $origin = $q->header('Origin');
        if ($origin === null || !preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $origin)) {
            return null;
        }
        $rows = $this->app->db->all("SELECT u.uri FROM oauth_client_uris u JOIN oauth_clients c ON c.id = u.client_pk WHERE u.type = 'redirect' AND c.is_active = 1 AND c.revoked_at IS NULL");
        foreach ($rows as $row) {
            $p = parse_url($row['uri']);
            $o = ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
            if (hash_equals($o, $origin)) {
                return $origin;
            }
        }
        return null;
    }

    private function cors(Request $q, Response $r): Response
    {
        if (($o = $this->allowedOrigin($q)) !== null) {
            $r->headers['Access-Control-Allow-Origin'] = $o;
            $r->headers['Vary'] = 'Origin';
        }
        return $r;
    }

    private function preflight(Request $q): Response
    {
        $r = new Response(204);
        if (($o = $this->allowedOrigin($q)) !== null) {
            $r->headers += [
                'Access-Control-Allow-Origin' => $o,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ];
        }
        return $r;
    }

    // ------------------------------------------------------------------ Authorization Endpoint

    private function authorize(Request $q): Response
    {
        $req = $this->validator->validate($q->query);
        if ($req->isFatal()) {
            $this->app->audit->log('oidc.authorize_rejected', null, ['reason' => $req->fatal, 'client' => (string) ($q->query['client_id'] ?? '')]);
            return $this->render('error', ['title' => 'Anmeldung nicht möglich', 'message' => $req->fatal . ' Bitte wenden Sie sich an den Betreiber der Anwendung.'], 400);
        }
        if ($req->isRedirectError()) {
            return $this->errorRedirect($req, (string) $req->error, $req->errorDescription);
        }

        $session = $this->app->session();
        $user = $this->app->currentUser();

        // Erneute Anmeldung erzwingen (prompt=login bzw. max_age überschritten).
        if ($user !== null) {
            $authTime = (int) $session->get('auth_time', 0);
            $stale = $req->maxAge !== null && (\Vylaris\Core\Clock::now() - $authTime) > $req->maxAge;
            if (in_array('login', $req->prompt, true) || $stale) {
                if (in_array('none', $req->prompt, true)) {
                    return $this->errorRedirect($req, 'login_required', 'Erneute Anmeldung erforderlich.');
                }
                $this->app->auth->logout($session);
                $user = null;
            }
        }

        if ($user === null) {
            if (in_array('none', $req->prompt, true)) {
                return $this->errorRedirect($req, 'login_required', 'Der Benutzer ist nicht angemeldet.');
            }
            $params = $q->query;
            $params['prompt'] = trim(implode(' ', array_diff($req->prompt, ['login'])));
            if ($params['prompt'] === '') {
                unset($params['prompt']);
            }
            unset($params['max_age']);
            return $this->redirect('/login?next=' . rawurlencode('/oidc/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)));
        }

        if (!$this->app->clients->userMayAccess((int) $user['id'], $req->client)) {
            $this->app->audit->log('oidc.access_denied_policy', (int) $user['id'], [], null, $req->client['client_id']);
            return $this->errorRedirect($req, 'access_denied', 'Für diese Anwendung besteht keine Berechtigung.');
        }

        $needsConsent = !$this->app->clients->hasConsent((int) $user['id'], $req->client, $req->scopes)
            || (in_array('consent', $req->prompt, true) && (int) $req->client['skip_consent'] !== 1);
        if ($needsConsent) {
            if (in_array('none', $req->prompt, true)) {
                return $this->errorRedirect($req, 'consent_required', 'Einwilligung erforderlich.');
            }
            $session->set('oidc_pending', ['query' => $q->query, 'uid' => (int) $user['id']]);
            return $this->render('oidc/consent', ['client' => $req->client, 'scopes' => $this->describeScopes($req->scopes)]);
        }
        return $this->complete($q, $req, $user);
    }

    private function consent(Request $q): Response
    {
        $session = $this->app->session();
        $user = $this->app->currentUser();
        $pending = $session->get('oidc_pending');
        $session->forget('oidc_pending');
        if ($user === null || !is_array($pending) || (int) ($pending['uid'] ?? 0) !== (int) $user['id']) {
            return $this->redirect('/account');
        }
        $req = $this->validator->validate($pending['query']);
        if (!$req->ok()) {
            return $this->render('error', ['title' => 'Anfrage ungültig', 'message' => 'Die Anmeldeanfrage ist nicht mehr gültig.'], 400);
        }
        if ($q->str('decision') !== 'allow') {
            $this->app->audit->log('oidc.consent_denied', (int) $user['id'], [], null, $req->client['client_id']);
            return $this->errorRedirect($req, 'access_denied', 'Der Benutzer hat den Zugriff abgelehnt.');
        }
        $this->app->clients->grantConsent((int) $user['id'], $req->client, $req->scopes);
        return $this->complete(new Request('GET', '/oidc/authorize', $pending['query'], [], $q->server), $req, $user);
    }

    private function describeScopes(array $scopes): array
    {
        $labels = [
            'openid' => 'Sie mit Ihrem ' . $this->app->brand->get('org') . '-Konto anmelden',
            'profile' => 'Ihren Namen sehen',
            'email' => 'Ihre E-Mail-Adresse sehen',
            'roles' => 'Ihre Rollen in dieser Anwendung sehen',
            'offline_access' => 'Auch dann Zugriff behalten, wenn Sie nicht angemeldet sind',
        ];
        return array_map(static fn ($s) => $labels[$s] ?? $s, $scopes);
    }

    /** Schließt die Autorisierung ab: Code ausstellen (an Client, Redirect-URI, PKCE-Challenge und Sitzung gebunden) und zurückleiten. */
    private function complete(Request $q, AuthorizeRequest $req, array $user): Response
    {
        $session = $this->app->session();
        $code = $this->app->oauth->issueCode(
            $req->client, (int) $user['id'], $req->scopes, $req->redirectUri, (string) ($q->query['code_challenge'] ?? ''),
            $req->nonce !== '' ? $req->nonce : null, $session->idHash(), (int) $session->get('auth_time', \Vylaris\Core\Clock::now())
        );
        $this->app->audit->log('oidc.code_issued', (int) $user['id'], ['scopes' => implode(' ', $req->scopes)], null, $req->client['client_id']);
        $params = ['code' => $code];
        if ($req->state !== '') {
            $params['state'] = $req->state;
        }
        $params['iss'] = $this->app->config->baseUrl();
        return $this->redirect(AuthorizeValidator::appendQuery($req->redirectUri, $params));
    }

    private function errorRedirect(AuthorizeRequest $req, string $error, ?string $description): Response
    {
        return $this->redirect(AuthorizeValidator::errorRedirect($req->redirectUri, $error, $description, $req->state, $this->app->config->baseUrl()));
    }

    // ------------------------------------------------------------------ Token Endpoint

    private function token(Request $q): Response
    {
        if (!$this->app->limiter->hit('token-ip:' . $q->ip(), 300, 900)) {
            return $this->cors($q, Response::json(['error' => 'temporarily_unavailable', 'error_description' => 'Zu viele Anfragen.'], 429, ['Pragma' => 'no-cache']));
        }
        [$status, $body, $headers] = $this->app->oauth->token($q);
        return $this->cors($q, Response::json($body, $status, $headers));
    }

    // ------------------------------------------------------------------ UserInfo

    private function userinfo(Request $q): Response
    {
        $unauthorized = fn (string $err) => $this->cors($q, Response::json(['error' => $err], 401, ['WWW-Authenticate' => 'Bearer error="' . $err . '"']));
        $jwt = $q->bearerToken();
        if ($jwt === null) {
            return $unauthorized('invalid_request');
        }
        $t = $this->app->oauth->validateAccessToken($jwt);
        if ($t === null || !in_array('openid', $t['scopes'], true)) {
            return $unauthorized('invalid_token');
        }
        return $this->cors($q, Response::json($this->app->claims->forUser($t['user'], $t['scopes'], $t['client'])));
    }

    // ------------------------------------------------------------------ Logout (RP-Initiated Logout 1.0)

    /** Gültige Logout-Parameter: Client und post_logout_redirect_uri müssen exakt registriert sein. */
    private function logoutTarget(Request $q): array
    {
        $clientId = $q->str('client_id');
        $hint = $q->str('id_token_hint');
        if ($hint !== '' && class_exists(\Lcobucci\JWT\Configuration::class)) {
            $clientId = $this->clientFromHint($hint) ?? $clientId;
        }
        $client = $clientId !== '' ? $this->app->clients->findByClientId($clientId) : null;
        $uri = $q->str('post_logout_redirect_uri');
        $valid = $client !== null && $uri !== '' && \Vylaris\Oidc\RedirectUri::matches($uri, $this->app->clients->uris((int) $client['id'], 'post_logout'));
        return ['client' => $client, 'uri' => $valid ? $uri : '', 'state' => mb_substr($q->str('state'), 0, 512)];
    }

    private function clientFromHint(string $jwt): ?string
    {
        try {
            $parser = new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder());
            $token = $parser->parse($jwt);
            $kid = $token->headers()->get('kid');
            foreach ($this->app->keys->verificationKeys() as $k) {
                if ($k['kid'] !== $kid) {
                    continue;
                }
                $ok = (new \Lcobucci\JWT\Validation\Validator())->validate(
                    $token,
                    new \Lcobucci\JWT\Validation\Constraint\SignedWith(new \Lcobucci\JWT\Signer\Rsa\Sha256(), \Lcobucci\JWT\Signer\Key\InMemory::plainText($k['public_pem'])),
                    new \Lcobucci\JWT\Validation\Constraint\IssuedBy($this->app->config->baseUrl())
                );
                $aud = $token->claims()->get('aud');
                return $ok && is_array($aud) && count($aud) === 1 ? (string) $aud[0] : null;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function logoutPage(Request $q): Response
    {
        $t = $this->logoutTarget($q);
        if ($this->app->currentUser() === null) {
            return $this->redirect($this->logoutRedirect($t));
        }
        return $this->render('oidc/logout', ['client' => $t['client'], 'uri' => $t['uri'], 'state' => $t['state']]);
    }

    private function logout(Request $q): Response
    {
        $t = $this->logoutTarget($q);
        $this->app->auth->logout($this->app->session());
        return $this->redirect($this->logoutRedirect($t));
    }

    private function logoutRedirect(array $t): string
    {
        if ($t['uri'] === '') {
            return '/login';
        }
        return $t['state'] !== '' ? AuthorizeValidator::appendQuery($t['uri'], ['state' => $t['state']]) : $t['uri'];
    }

    // ------------------------------------------------------------------ Verbundene Anwendungen

    private function apps(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        return $this->render('oidc/apps', ['apps' => $this->app->clients->connectedApps((int) $user['id']), 'flash' => $this->app->session()->flash('ok')]);
    }

    private function revokeApp(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $this->app->clients->revokeConsent((int) $user['id'], (int) $q->str('client'));
        $this->app->session()->flash('ok', 'Zugriff widerrufen.');
        return $this->redirect('/account/apps');
    }
}
