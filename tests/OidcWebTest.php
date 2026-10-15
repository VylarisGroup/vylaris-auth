<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** Endpunkte, die ohne league/oauth2-server auskommen (Discovery, JWKS, Vorprüfung, Consent, Logout). */
final class OidcWebTest extends TestCase
{
    private object $ctx;
    private array $client;
    private string $keyDir;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $this->keyDir = dirname(__DIR__) . '/var/test-keys-' . bin2hex(random_bytes(4));
        $this->ctx = Factory::app(['oidc' => ['private_key_dir' => $this->keyDir]]);
        $this->ctx->app->users->create('anna@example.org', $this->ctx->app->passwords->hash(self::PW), 'Anna Muster', true);
        $this->client = $this->ctx->app->clients->create('Wiki', ['https://wiki.example/cb'], ['https://wiki.example/bye'], true, 'openid profile email roles');
    }

    public function __destruct()
    {
        foreach (glob($this->keyDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->keyDir);
    }

    private function authQuery(array $over = []): array
    {
        return $over + [
            'response_type' => 'code', 'client_id' => $this->client['client_id'], 'redirect_uri' => 'https://wiki.example/cb',
            'scope' => 'openid profile', 'state' => 'xyz', 'nonce' => 'nonce-12345678',
            'code_challenge' => str_repeat('B', 43), 'code_challenge_method' => 'S256',
        ];
    }

    private function login(TestBrowser $b): void
    {
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
    }

    public function testDiscoveryDocument(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/.well-known/openid-configuration');
        $this->assertSame(200, $r->status);
        $d = json_decode($r->body, true);
        $this->assertSame('https://auth.test', $d['issuer']);
        $this->assertSame('https://auth.test/oidc/authorize', $d['authorization_endpoint']);
        $this->assertSame(['code'], $d['response_types_supported']);
        $this->assertSame(['S256'], $d['code_challenge_methods_supported']);
        $this->assertSame(['RS256'], $d['id_token_signing_alg_values_supported']);
        $this->assertTrue($d['authorization_response_iss_parameter_supported']);
        $this->assertFalse($d['request_parameter_supported']);
        foreach (['token_endpoint', 'userinfo_endpoint', 'jwks_uri', 'end_session_endpoint'] as $k) {
            $this->assertTrue(str_starts_with($d[$k], 'https://auth.test/'), $k);
        }
    }

    public function testJwksPublishesOnlyPublicKeys(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/oidc/jwks');
        $this->assertSame(200, $r->status);
        $jwks = json_decode($r->body, true);
        $this->assertSame(1, count($jwks['keys']));
        $this->assertStringNotContains('PRIVATE', $r->body);
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $secretField) {
            $this->assertFalse(array_key_exists($secretField, $jwks['keys'][0]), $secretField);
        }
    }

    public function testAuthorizeWithBadRedirectShowsErrorAndNeverRedirects(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/oidc/authorize', $this->authQuery(['redirect_uri' => 'https://evil.example/steal']));
        $this->assertSame(400, $r->status);
        $this->assertFalse(isset($r->headers['Location']));
        $this->assertSame(400, $b->get('/oidc/authorize', $this->authQuery(['client_id' => 'vy_nope']))->status);
    }

    public function testAuthorizeProtocolErrorsRedirectBackWithStateAndIssuer(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/oidc/authorize', $this->authQuery(['code_challenge_method' => 'plain']));
        $this->assertSame(302, $r->status);
        $loc = $r->headers['Location'];
        $this->assertTrue(str_starts_with($loc, 'https://wiki.example/cb?'));
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('invalid_request', $q['error']);
        $this->assertSame('xyz', $q['state']);
        $this->assertSame('https://auth.test', $q['iss']);
    }

    public function testAnonymousUserIsSentToLoginAndBackToAuthorize(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/oidc/authorize', $this->authQuery());
        $this->assertSame(302, $r->status);
        $this->assertStringContains('/login?next=', $r->headers['Location']);
        parse_str(parse_url($r->headers['Location'], PHP_URL_QUERY), $q);
        $this->assertTrue(str_starts_with($q['next'], '/oidc/authorize?'));
        $this->assertStringContains('code_challenge=', $q['next']);
        // Login schickt zurück zur Authorize-URL
        $after = $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW, 'next' => $q['next']]);
        $this->assertSame($q['next'], $after->headers['Location']);
    }

    public function testPromptNoneWithoutSessionReturnsLoginRequired(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/oidc/authorize', $this->authQuery(['prompt' => 'none']));
        parse_str(parse_url($r->headers['Location'], PHP_URL_QUERY), $q);
        $this->assertSame('login_required', $q['error']);
        $this->assertSame('xyz', $q['state']);
    }

    public function testConsentScreenAppearsForThirdPartyAndDenyRedirectsWithAccessDenied(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $r = $b->get('/oidc/authorize', $this->authQuery());
        $this->assertSame(200, $r->status);
        $this->assertStringContains('Wiki', $r->body);
        $this->assertStringContains('Ihren Namen sehen', $r->body);

        $deny = $b->post('/oidc/consent', ['decision' => 'deny']);
        parse_str(parse_url($deny->headers['Location'], PHP_URL_QUERY), $q);
        $this->assertSame('access_denied', $q['error']);
        $this->assertSame('xyz', $q['state']);
        // Pending ist verbraucht: erneutes Absenden führt nirgends hin.
        $again = $b->post('/oidc/consent', ['decision' => 'allow']);
        $this->assertSame('/account', $again->headers['Location']);
    }

    public function testConsentPostRequiresCsrfAndOwnSession(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $b->get('/oidc/authorize', $this->authQuery());
        $this->assertSame(403, $b->post('/oidc/consent', ['decision' => 'allow'], false)->status);
    }

    public function testPolicyAssignedBlocksUsersWithoutRole(): void
    {
        $c = $this->ctx->app->clients->create('Intern', ['https://intern.example/cb'], [], true, 'openid', true, 'assigned');
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $r = $b->get('/oidc/authorize', $this->authQuery(['client_id' => $c['client_id'], 'redirect_uri' => 'https://intern.example/cb', 'scope' => 'openid']));
        parse_str(parse_url($r->headers['Location'], PHP_URL_QUERY), $q);
        $this->assertSame('access_denied', $q['error']);
    }

    public function testRevokedClientCannotBeUsed(): void
    {
        $row = $this->ctx->app->clients->findByClientId($this->client['client_id']);
        $this->ctx->app->clients->revoke((int) $row['id']);
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(400, $b->get('/oidc/authorize', $this->authQuery())->status);
    }

    public function testLogoutRequiresConfirmationAndHonoursOnlyRegisteredRedirect(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $page = $b->get('/oidc/logout', ['client_id' => $this->client['client_id'], 'post_logout_redirect_uri' => 'https://wiki.example/bye', 'state' => 's1']);
        $this->assertSame(200, $page->status, 'GET darf nie abmelden (CSRF)');
        $this->assertSame(200, $b->get('/account')->status);
        $this->assertSame(403, $b->post('/oidc/logout', [], false)->status);

        $out = $b->post('/oidc/logout', ['client_id' => $this->client['client_id'], 'post_logout_redirect_uri' => 'https://wiki.example/bye', 'state' => 's1']);
        $this->assertSame('https://wiki.example/bye?state=s1', $out->headers['Location']);
        $this->assertSame(302, $b->get('/account')->status);
    }

    public function testLogoutIgnoresUnregisteredRedirect(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $out = $b->post('/oidc/logout', ['client_id' => $this->client['client_id'], 'post_logout_redirect_uri' => 'https://evil.example/']);
        $this->assertSame('/login', $out->headers['Location']);
    }

    public function testCorsOnlyForRegisteredOrigins(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $ok = $b->get('/oidc/jwks', [], ['HTTP_ORIGIN' => 'https://wiki.example']);
        $this->assertSame('https://wiki.example', $ok->headers['Access-Control-Allow-Origin']);
        $bad = $b->get('/oidc/jwks', [], ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertFalse(isset($bad->headers['Access-Control-Allow-Origin']));
    }

    public function testTokenEndpointRejectsAnonymousAndGarbage(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->post('/oidc/token', ['grant_type' => 'authorization_code'], false);
        $this->assertSame(401, $r->status);
        $this->assertSame('invalid_client', json_decode($r->body, true)['error']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame(401, $b->get('/oidc/userinfo')->status);
        $this->assertSame(401, $b->get('/oidc/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer a.b.c'])->status);
    }

    public function testRevokingConnectedAppRemovesConsent(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $b->get('/oidc/authorize', $this->authQuery());
        $row = $this->ctx->app->clients->findByClientId($this->client['client_id']);
        $uid = (int) $this->ctx->app->users->findByEmail('anna@example.org')['id'];
        $this->ctx->app->clients->grantConsent($uid, $row, ['openid', 'profile']);
        $this->assertStringContains('Wiki', $b->get('/account/apps')->body);
        $b->post('/account/apps/revoke', ['client' => (string) $row['id']]);
        $this->assertFalse($this->ctx->app->clients->hasConsent($uid, $row, ['openid']));
    }

    public function testDiscoveryAndJwksAreSessionlessPlainJson(): void
    {
        foreach (['/.well-known/openid-configuration', '/oidc/discovery', '/oidc/jwks'] as $path) {
            $b = new TestBrowser($this->ctx->app);
            $r = $b->get($path);
            $this->assertSame(200, $r->status, $path);
            $this->assertStringContains('application/json', $r->headers['Content-Type']);
            $this->assertTrue(!isset($r->headers['Set-Cookie']), 'kein Cookie');
            $this->assertStringContains('public', $r->headers['Cache-Control']);
            $this->assertTrue(is_array(json_decode($r->body, true)), 'gültiges JSON');
        }
        $d = json_decode((new TestBrowser($this->ctx->app))->get('/.well-known/openid-configuration')->body, true);
        $this->assertSame('https://auth.test', $d['issuer']);
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM sessions'));
    }

    public function testServerErrorsOnMachineEndpointsAreJsonWithReference(): void
    {
        $ctx = Factory::app(['oidc' => ['private_key_dir' => '/proc/no/such/dir'], 'paths' => ['log_dir' => sys_get_temp_dir() . '/vlog-' . bin2hex(random_bytes(4))]]);
        $r = (new TestBrowser($ctx->app))->get('/oidc/jwks');
        $this->assertSame(500, $r->status);
        $this->assertStringContains('application/json', $r->headers['Content-Type']);
        $d = json_decode($r->body, true);
        $this->assertSame('server_error', $d['error']);
        $this->assertTrue((bool) preg_match('/Referenz ([0-9a-f]{6})/', $d['error_description'], $m));
        $log = (string) file_get_contents($ctx->config->get('paths.log_dir') . '/error-' . gmdate('Y-m-d') . '.log');
        $this->assertStringContains('#' . $m[1], $log);
    }
}
