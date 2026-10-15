<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Oidc\{AuthorizeValidator, ClaimsService, ClientService, KeyService, RedirectUri, Subject};
use Vylaris\Tests\Support\{Factory, TestCase};

final class OidcCoreTest extends TestCase
{
    private object $s;
    private ClientService $clients;
    private KeyService $keys;
    private string $keyDir;

    protected function setUp(): void
    {
        $this->keyDir = dirname(__DIR__) . '/var/test-keys-' . bin2hex(random_bytes(4));
        $this->s = Factory::auth(['oidc' => ['private_key_dir' => $this->keyDir]]);
        $this->clients = new ClientService($this->s->db, $this->s->audit, $this->s->sessions);
        $this->keys = new KeyService($this->s->db, $this->s->config);
    }

    public function __destruct()
    {
        foreach (glob($this->keyDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->keyDir);
    }

    private function client(array $over = []): array
    {
        $r = $this->clients->create(
            $over['name'] ?? 'Demo App', $over['redirects'] ?? ['https://app.example/cb'], $over['logout'] ?? ['https://app.example/bye'],
            $over['confidential'] ?? true, $over['scopes'] ?? 'openid profile email roles', $over['skip'] ?? false, $over['policy'] ?? 'all'
        );
        return $r + ['row' => $this->clients->findByPk($r['id'])];
    }

    private function query(array $c, array $over = []): array
    {
        return $over + [
            'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => 'https://app.example/cb',
            'scope' => 'openid profile', 'state' => 'abc123', 'nonce' => 'n-0S6_WzA2Mj',
            'code_challenge' => str_repeat('A', 43), 'code_challenge_method' => 'S256',
        ];
    }

    // ------------------------------------------------------------ Schlüssel / JWKS

    public function testKeyGenerationJwksAndPermissions(): void
    {
        $k = $this->keys->ensureActiveKey();
        $this->assertSame($k['kid'], $this->keys->ensureActiveKey()['kid'], 'idempotent');
        $jwks = $this->keys->jwks();
        $this->assertSame(1, count($jwks['keys']));
        $jwk = $jwks['keys'][0];
        $this->assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($jwk));
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('AQAB', $jwk['e']);
        $this->assertStringNotContains('PRIVATE', json_encode($jwks));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->keys->privatePath($k))), -4));
        $this->assertStringContains('BEGIN PRIVATE KEY', $this->keys->privatePem($k));
    }

    public function testMissingKeyFileIsRecoveredWithNewKey(): void
    {
        $old = $this->keys->ensureActiveKey();
        unlink($this->keys->privatePath($old));
        $new = $this->keys->ensureActiveKey();
        $this->assertTrue($old['kid'] !== $new['kid'], 'neuer Schlüssel');
        $this->assertStringContains('BEGIN PRIVATE KEY', $this->keys->privatePem($new));
        $this->assertSame($new['kid'], $this->keys->active()['kid']);
        $this->assertSame($new['kid'], $this->keys->ensureActiveKey()['kid'], 'danach stabil');
    }

    public function testKeyRotationKeepsOldKeyInJwksUntilGraceEnds(): void
    {
        $old = $this->keys->ensureActiveKey();
        $new = $this->keys->rotate();
        $this->assertTrue($old['kid'] !== $new['kid']);
        $this->assertSame($new['kid'], $this->keys->active()['kid']);
        $kids = array_column($this->keys->jwks()['keys'], 'kid');
        $this->assertSame(2, count($kids));
        Clock::advance(KeyService::RETIRED_GRACE_SECONDS + 5);
        $this->assertSame([$new['kid']], array_column($this->keys->jwks()['keys'], 'kid'));
        $this->assertSame(1, $this->keys->purgeRetired());
        $this->assertFalse(is_file($this->keys->privatePath($old)));
    }

    public function testJwkMatchesPublicKeyForSignatureVerification(): void
    {
        $k = $this->keys->ensureActiveKey();
        $sig = '';
        openssl_sign('hallo', $sig, $this->keys->privatePem($k), OPENSSL_ALGO_SHA256);
        $this->assertSame(1, openssl_verify('hallo', $sig, $k['public_pem'], OPENSSL_ALGO_SHA256));
    }

    // ------------------------------------------------------------ Redirect-URIs

    public function testRedirectUriValidation(): void
    {
        foreach (['https://app.example/cb', 'https://app.example/cb?x=1', 'http://localhost:8080/cb', 'http://127.0.0.1/cb', 'http://[::1]:3000/cb'] as $ok) {
            $this->assertNull(RedirectUri::validate($ok), $ok);
        }
        foreach (['http://app.example/cb', 'https://*.example/cb', 'https://app.example/cb#frag', 'javascript:alert(1)', '/relativ', 'https://u:p@app.example/', 'https://app.example\\evil', 'https://app.example/a b', ''] as $bad) {
            $this->assertNotNull(RedirectUri::validate($bad), $bad);
        }
    }

    public function testRedirectUriMatchingIsExact(): void
    {
        $reg = ['https://app.example/cb'];
        $this->assertTrue(RedirectUri::matches('https://app.example/cb', $reg));
        foreach (['https://app.example/cb/', 'https://app.example/cb?x=1', 'https://app.example/CB', 'https://app.example.evil/cb', 'https://app.example/cb2', 'http://app.example/cb'] as $bad) {
            $this->assertFalse(RedirectUri::matches($bad, $reg), $bad);
        }
    }

    // ------------------------------------------------------------ Clients

    public function testClientCreationStoresOnlySecretHash(): void
    {
        $c = $this->client();
        $this->assertTrue(str_starts_with($c['client_id'], 'vy_'));
        $this->assertTrue(strlen($c['client_secret']) >= 43);
        $row = $c['row'];
        $this->assertStringNotContains($c['client_secret'], json_encode($row));
        $this->assertTrue($this->clients->verifySecret($row, $c['client_secret']));
        $this->assertFalse($this->clients->verifySecret($row, 'falsch'));
        $this->assertFalse($this->clients->verifySecret($row, null));
        $this->assertFalse($this->clients->verifySecret($row, ''));
    }

    public function testPublicClientHasNoSecret(): void
    {
        $c = $this->client(['confidential' => false]);
        $this->assertNull($c['client_secret']);
        $this->assertNull($c['row']['secret_hash']);
    }

    public function testClientCreationRejectsBadInput(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->clients->create('X', ['https://a.example/cb']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->clients->create('App', []));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->clients->create('App', ['http://evil.example/cb']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->clients->create('App', ['https://a.example/cb'], [], true, 'openid admin'));
    }

    public function testSecretRotationInvalidatesOldSecret(): void
    {
        $c = $this->client();
        $new = $this->clients->rotateSecret($c['id']);
        $row = $this->clients->findByPk($c['id']);
        $this->assertFalse($this->clients->verifySecret($row, $c['client_secret']));
        $this->assertTrue($this->clients->verifySecret($row, $new));
    }

    public function testRevokingClientRevokesAllItsTokensAndConsents(): void
    {
        $c = $this->client();
        $uid = Factory::user($this->s);
        $exp = Clock::db(Clock::now() + 600);
        $this->s->db->insert('oauth_access_tokens', ['id' => 'a1', 'client_pk' => $c['id'], 'user_id' => $uid, 'scopes' => 'openid', 'expires_at' => $exp, 'created_at' => Clock::db()]);
        $this->s->db->insert('oauth_refresh_tokens', ['id' => 'r1', 'access_token_id' => 'a1', 'client_pk' => $c['id'], 'user_id' => $uid, 'scopes' => 'openid', 'expires_at' => $exp, 'created_at' => Clock::db()]);
        $this->clients->grantConsent($uid, $c['row'], ['openid']);
        $this->clients->revoke($c['id']);
        $this->assertNotNull($this->s->db->scalar('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', ['a1']));
        $this->assertNotNull($this->s->db->scalar('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', ['r1']));
        $this->assertSame(0, count($this->clients->connectedApps($uid)));
        $this->assertFalse($this->clients->isUsable($this->clients->findByPk($c['id'])));
    }

    public function testDeactivatedClientCannotAuthorize(): void
    {
        $c = $this->client();
        $v = new AuthorizeValidator($this->clients);
        $this->assertTrue($v->validate($this->query($c))->ok());
        $this->clients->setActive($c['id'], false);
        $this->assertTrue($v->validate($this->query($c))->isFatal());
        $this->clients->setActive($c['id'], true);
        $this->assertTrue($v->validate($this->query($c))->ok());
    }

    public function testConsentTracking(): void
    {
        $c = $this->client();
        $uid = Factory::user($this->s);
        $this->assertFalse($this->clients->hasConsent($uid, $c['row'], ['openid', 'profile']));
        $this->clients->grantConsent($uid, $c['row'], ['openid', 'profile']);
        $this->assertTrue($this->clients->hasConsent($uid, $c['row'], ['openid']));
        $this->assertFalse($this->clients->hasConsent($uid, $c['row'], ['openid', 'email']), 'neuer Scope braucht neue Einwilligung');
        $first = $this->client(['name' => 'Intern', 'skip' => true]);
        $this->assertTrue($this->clients->hasConsent($uid, $first['row'], ['openid', 'email']));
        $this->clients->revokeConsent($uid, $c['id']);
        $this->assertFalse($this->clients->hasConsent($uid, $c['row'], ['openid']));
    }

    public function testAppRolesAreScopedToTheirApplication(): void
    {
        $a = $this->client(['name' => 'App A']);
        $b = $this->client(['name' => 'App B', 'redirects' => ['https://b.example/cb']]);
        $uid = Factory::user($this->s);
        $editor = $this->clients->createRole($a['id'], 'editor');
        $this->assertTrue($this->clients->assignRole($uid, $a['id'], $editor));
        $this->assertFalse($this->clients->assignRole($uid, $b['id'], $editor), 'Rolle gehört zu App A');
        $this->assertSame(['editor'], $this->clients->userRoles($uid, $a['id']));
        $this->assertSame([], $this->clients->userRoles($uid, $b['id']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->clients->createRole($a['id'], 'Bad Role!'));
    }

    public function testAccessPolicyAssignedRequiresRole(): void
    {
        $c = $this->client(['policy' => 'assigned']);
        $uid = Factory::user($this->s);
        $this->assertFalse($this->clients->userMayAccess($uid, $c['row']));
        $this->clients->assignRole($uid, $c['id'], $this->clients->createRole($c['id'], 'member'));
        $this->assertTrue($this->clients->userMayAccess($uid, $c['row']));
    }

    // ------------------------------------------------------------ AuthorizeValidator

    public function testValidAuthorizeRequest(): void
    {
        $c = $this->client();
        $r = (new AuthorizeValidator($this->clients))->validate($this->query($c));
        $this->assertTrue($r->ok());
        $this->assertSame(['openid', 'profile'], $r->scopes);
        $this->assertSame('abc123', $r->state);
    }

    public function testUnknownClientAndBadRedirectAreFatalNotRedirected(): void
    {
        $c = $this->client();
        $v = new AuthorizeValidator($this->clients);
        $this->assertTrue($v->validate($this->query($c, ['client_id' => 'vy_unbekannt']))->isFatal());
        foreach (['https://evil.example/cb', 'https://app.example/cb/', 'https://app.example/cb?x=1', ''] as $bad) {
            $r = $v->validate($this->query($c, ['redirect_uri' => $bad]));
            $this->assertTrue($r->isFatal(), "redirect_uri=$bad");
            $this->assertFalse($r->isRedirectError());
        }
        $q = $this->query($c);
        unset($q['redirect_uri']);
        $this->assertTrue($v->validate($q)->isFatal(), 'redirect_uri ist Pflicht');
    }

    public function testRedirectErrorsForProtocolViolations(): void
    {
        $c = $this->client();
        $v = new AuthorizeValidator($this->clients);
        $cases = [
            [['response_type' => 'token'], 'unsupported_response_type'],
            [['response_type' => 'id_token'], 'unsupported_response_type'],
            [['state' => ''], 'invalid_request'],
            [['code_challenge' => ''], 'invalid_request'],
            [['code_challenge' => 'zu-kurz'], 'invalid_request'],
            [['code_challenge_method' => 'plain'], 'invalid_request'],
            [['code_challenge_method' => ''], 'invalid_request'],
            [['nonce' => ''], 'invalid_request'],
            [['scope' => 'profile email'], 'invalid_scope'],
            [['scope' => 'openid admin'], 'invalid_scope'],
            [['prompt' => 'none login'], 'invalid_request'],
            [['prompt' => 'bogus'], 'invalid_request'],
            [['max_age' => '-5'], 'invalid_request'],
            [['request' => 'eyJ...'], 'request_not_supported'],
            [['request_uri' => 'https://evil.example/r'], 'request_not_supported'],
        ];
        foreach ($cases as [$over, $err]) {
            $r = $v->validate($this->query($c, $over));
            $this->assertTrue($r->isRedirectError(), json_encode($over));
            $this->assertSame($err, $r->error, json_encode($over));
        }
        $q = $this->query($c);
        unset($q['code_challenge'], $q['code_challenge_method']);
        $this->assertSame('invalid_request', $v->validate($q)->error, 'PKCE ist Pflicht');
    }

    public function testScopesAreLimitedToWhatTheClientMayRequest(): void
    {
        $c = $this->client(['scopes' => 'openid']);
        $r = (new AuthorizeValidator($this->clients))->validate($this->query($c, ['scope' => 'openid email']));
        $this->assertSame('invalid_scope', $r->error);
    }

    public function testNonceOptionalWhenConfigured(): void
    {
        $c = $this->client();
        $q = $this->query($c);
        unset($q['nonce']);
        $this->assertTrue((new AuthorizeValidator($this->clients, false))->validate($q)->ok());
    }

    public function testErrorRedirectUrlCarriesStateAndIssuer(): void
    {
        $u = AuthorizeValidator::errorRedirect('https://app.example/cb', 'access_denied', 'Nein', 'st&te', 'https://auth.test');
        parse_str(parse_url($u, PHP_URL_QUERY), $q);
        $this->assertSame(['error' => 'access_denied', 'error_description' => 'Nein', 'state' => 'st&te', 'iss' => 'https://auth.test'], $q);
        $u2 = AuthorizeValidator::errorRedirect('https://app.example/cb?x=1', 'e', null, '', 'https://auth.test');
        $this->assertStringContains('cb?x=1&error=e&iss=', $u2);
    }

    // ------------------------------------------------------------ Claims

    public function testClaimsDependOnScopesAndAreAppSpecific(): void
    {
        $a = $this->client(['name' => 'App A']);
        $b = $this->client(['name' => 'App B', 'redirects' => ['https://b.example/cb']]);
        $uid = Factory::user($this->s);
        $this->clients->assignRole($uid, $a['id'], $this->clients->createRole($a['id'], 'editor'));
        $claims = new ClaimsService($this->clients, 'geheim');
        $user = $this->s->users->findById($uid);

        $minimal = $claims->forUser($user, ['openid'], $a['row']);
        $this->assertSame(['sub'], array_keys($minimal));
        $full = $claims->forUser($user, ['openid', 'profile', 'email', 'roles'], $a['row']);
        $this->assertSame('Anna Muster', $full['name']);
        $this->assertSame('anna@example.org', $full['email']);
        $this->assertTrue($full['email_verified']);
        $this->assertSame(['editor'], $full['roles']);
        $this->assertSame([], $claims->forUser($user, ['openid', 'roles'], $b['row'])['roles']);
        $this->assertTrue(str_starts_with($full['sub'], 'u_') && !str_contains($full['sub'], (string) $uid . '='));
        $this->assertSame($full['sub'], Subject::forUser($uid, 'geheim'));
        $this->assertTrue(Subject::forUser($uid, 'anderes-secret') !== $full['sub']);
    }
}
