<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/**
 * End-to-End-Test des OIDC-Flows: Authorize → Code → Token (PKCE) → UserInfo, Refresh-Rotation, Widerruf.
 */
final class OidcFlowTest extends TestCase
{
    private object $ctx;
    private string $keyDir;
    private array $a;
    private array $b;
    private int $uid;
    private const PW = 'Correct-Horse-Battery-9';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    protected function setUp(): void
    {
        $this->keyDir = dirname(__DIR__) . '/var/test-keys-' . bin2hex(random_bytes(4));
        $this->ctx = Factory::app(['oidc' => ['private_key_dir' => $this->keyDir]]);
        $this->uid = $this->ctx->app->users->create('anna@example.org', $this->ctx->app->passwords->hash(self::PW), 'Anna Muster', true);
        $this->a = $this->ctx->app->clients->create('App A', ['https://a.example/cb'], ['https://a.example/bye'], true, 'openid profile email roles offline_access', true);
        $this->b = $this->ctx->app->clients->create('App B', ['https://b.example/cb'], [], true, 'openid profile email roles', true);
    }

    public function __destruct()
    {
        foreach (glob(($this->keyDir ?? '/nonexistent') . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->keyDir ?? '/nonexistent');
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');
    }

    private function loggedIn(string $ip = '198.51.100.1'): TestBrowser
    {
        $br = new TestBrowser($this->ctx->app, $ip);
        $br->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        return $br;
    }

    /** Durchläuft /oidc/authorize und liefert den Code aus der Redirect-URL. */
    private function authorize(TestBrowser $br, array $client, string $redirect, string $scope = 'openid profile email roles', array $over = []): array
    {
        $r = $br->get('/oidc/authorize', $over + [
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $redirect, 'scope' => $scope,
            'state' => 'st-123', 'nonce' => 'nonce-abcdefgh', 'code_challenge' => $this->challenge(), 'code_challenge_method' => 'S256',
        ]);
        $this->assertSame(302, $r->status);
        $loc = $r->headers['Location'];
        $this->assertTrue(str_starts_with($loc, $redirect . '?'), $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        return $q;
    }

    private function exchange(TestBrowser $br, array $client, array $params): array
    {
        $r = $br->post('/oidc/token', $params + ['client_id' => $client['client_id'], 'client_secret' => $client['client_secret']], false);
        return [$r->status, json_decode($r->body, true) ?? []];
    }

    private function codeParams(string $code, string $redirect, string $verifier = self::VERIFIER): array
    {
        return ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier];
    }

    private function decodeIdToken(string $jwt): array
    {
        $this->assertSame(3, count(explode('.', $jwt)));
        [$h, $p, $s] = explode('.', $jwt);
        $b64 = static fn (string $x) => base64_decode(strtr($x, '-_', '+/') . str_repeat('=', (4 - strlen($x) % 4) % 4));
        $header = json_decode($b64($h), true);
        $jwks = $this->ctx->app->keys->jwks()['keys'];
        $kid = array_column($jwks, 'kid');
        $this->assertTrue(in_array($header['kid'], $kid, true), 'kid im JWKS');
        $row = $this->ctx->app->db->one('SELECT public_pem FROM oidc_keys WHERE kid = ?', [$header['kid']]);
        $this->assertSame(1, openssl_verify($h . '.' . $p, $b64($s), $row['public_pem'], OPENSSL_ALGO_SHA256), 'RS256-Signatur');
        $this->assertSame('RS256', $header['alg']);
        return json_decode($b64($p), true);
    }

    public function testFullAuthorizationCodeFlowWithPkceReturnsValidIdToken(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        $this->assertSame('st-123', $q['state']);
        $this->assertSame('https://auth.test', $q['iss']);
        $this->assertTrue(isset($q['code']));

        [$status, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->assertSame(200, $status);
        $this->assertSame('Bearer', $tok['token_type']);
        $claims = $this->decodeIdToken($tok['id_token']);
        $this->assertSame('https://auth.test', $claims['iss']);
        $this->assertSame([$this->a['client_id']], (array) $claims['aud']);
        $this->assertSame('nonce-abcdefgh', $claims['nonce']);
        $this->assertSame('anna@example.org', $claims['email']);
        $this->assertTrue($claims['exp'] > Clock::now() - 5 || $claims['exp'] > 0);
        $this->assertSame($this->ctx->app->claims->sub($this->uid), $claims['sub']);
        $this->assertFalse(isset($tok['refresh_token']), 'ohne offline_access kein Refresh Token');
    }

    public function testUserinfoWithAccessToken(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid profile');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $r = $br->get('/oidc/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok['access_token']]);
        $this->assertSame(200, $r->status);
        $info = json_decode($r->body, true);
        $this->assertSame('Anna Muster', $info['name']);
        $this->assertFalse(isset($info['email']), 'Scope email wurde nicht gewährt');
        $this->assertSame(401, $br->get('/oidc/userinfo')->status);
        $this->assertSame(401, $br->get('/oidc/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok['access_token'] . 'x'])->status);
    }

    public function testWrongPkceVerifierIsRejected(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [$status, $body] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb', 'falscher-verifier-falscher-verifier-falscher-verifier-1'));
        $this->assertSame(400, $status);
        $this->assertSame('invalid_grant', $body['error']);
    }

    public function testAuthorizationCodeCannotBeReplayedAndReplayRevokesTokens(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [$s1, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->assertSame(200, $s1);
        [$s2] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->assertSame(400, $s2);
        $this->assertSame(401, $br->get('/oidc/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok['access_token']])->status, 'Replay widerruft ausgestellte Tokens');
    }

    public function testCodeIsBoundToClientAndRedirectUri(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [$status] = $this->exchange($br, $this->b, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->assertTrue($status >= 400, 'Client B darf Code von A nicht einlösen');
        $q2 = $this->authorize($br, $this->a, 'https://a.example/cb');
        [$status2] = $this->exchange($br, $this->a, $this->codeParams($q2['code'], 'https://a.example/anders'));
        $this->assertSame(400, $status2);
    }

    public function testWrongClientSecretIsRejected(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [$status, $body] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb') + ['client_secret' => 'falsch']);
        $this->assertSame(401, $status);
        $this->assertSame('invalid_client', $body['error']);
    }

    public function testTwoIndependentAppsSignInViaSso(): void
    {
        $br = $this->loggedIn();
        $qa = $this->authorize($br, $this->a, 'https://a.example/cb');
        $qb = $this->authorize($br, $this->b, 'https://b.example/cb'); // SSO: kein erneutes Login
        [$sa, $ta] = $this->exchange($br, $this->a, $this->codeParams($qa['code'], 'https://a.example/cb'));
        [$sb, $tb] = $this->exchange($br, $this->b, $this->codeParams($qb['code'], 'https://b.example/cb'));
        $this->assertSame([200, 200], [$sa, $sb]);
        $ca = $this->decodeIdToken($ta['id_token']);
        $cb = $this->decodeIdToken($tb['id_token']);
        $this->assertSame([$this->a['client_id']], (array) $ca['aud']);
        $this->assertSame([$this->b['client_id']], (array) $cb['aud']);
        $this->assertSame($ca['sub'], $cb['sub']);
    }

    public function testRolesClaimIsPerApplication(): void
    {
        $rowA = $this->ctx->app->clients->findByClientId($this->a['client_id']);
        $this->ctx->app->clients->assignRole($this->uid, (int) $rowA['id'], $this->ctx->app->clients->createRole((int) $rowA['id'], 'editor'));
        $br = $this->loggedIn();
        $qa = $this->authorize($br, $this->a, 'https://a.example/cb');
        $qb = $this->authorize($br, $this->b, 'https://b.example/cb');
        [, $ta] = $this->exchange($br, $this->a, $this->codeParams($qa['code'], 'https://a.example/cb'));
        [, $tb] = $this->exchange($br, $this->b, $this->codeParams($qb['code'], 'https://b.example/cb'));
        $this->assertSame(['editor'], $this->decodeIdToken($ta['id_token'])['roles']);
        $this->assertSame([], $this->decodeIdToken($tb['id_token'])['roles']);
    }

    public function testRevokingSessionInvalidatesAccessToken(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $hdr = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok['access_token']];
        $this->assertSame(200, $br->get('/oidc/userinfo', [], $hdr)->status);
        $this->ctx->app->sessions->revokeAll($this->uid);
        $this->assertSame(401, $br->get('/oidc/userinfo', [], $hdr)->status);
    }

    public function testLockedUserAndRevokedClientInvalidateTokens(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->b, 'https://b.example/cb');
        [, $tok] = $this->exchange($br, $this->b, $this->codeParams($q['code'], 'https://b.example/cb'));
        $hdr = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok['access_token']];
        $this->ctx->app->users->setLocked($this->uid, true, 'x');
        $this->assertSame(401, $br->get('/oidc/userinfo', [], $hdr)->status);
        $this->ctx->app->users->setLocked($this->uid, false);
        $this->assertSame(200, $br->get('/oidc/userinfo', [], $hdr)->status);
        $rowB = $this->ctx->app->clients->findByClientId($this->b['client_id']);
        $this->ctx->app->clients->revoke((int) $rowB['id']);
        $this->assertSame(401, $br->get('/oidc/userinfo', [], $hdr)->status);
    }

    public function testRefreshTokenRotationAndReuseDetection(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid offline_access');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->assertTrue(isset($tok['refresh_token']));

        [$s1, $t2] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token']]);
        $this->assertSame(200, $s1);
        $this->assertTrue($t2['refresh_token'] !== $tok['refresh_token'], 'Rotation');
        $this->assertFalse(isset($t2['id_token']), 'kein neues ID-Token beim Refresh');

        [$s2] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token']]);
        $this->assertSame(400, $s2, 'altes Token ist ungültig');
        [$s3] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token']]);
        $this->assertSame(400, $s3, 'Wiederverwendung widerruft die gesamte Kette');
    }

    public function testRefreshTokenSurvivesSessionEndButNotPasswordReset(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid offline_access');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $this->ctx->app->sessions->revokeAll($this->uid);
        [$ok, $t2] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token']]);
        $this->assertSame(200, $ok);
        $this->ctx->app->sessions->revokeAllTokensForUser($this->uid);
        [$bad] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token']]);
        $this->assertSame(400, $bad);
    }

    public function testUnsupportedGrantsAreRejected(): void
    {
        $br = new TestBrowser($this->ctx->app);
        foreach (['password', 'client_credentials', 'implicit'] as $g) {
            [$status, $body] = $this->exchange($br, $this->a, ['grant_type' => $g, 'username' => 'a', 'password' => 'b', 'scope' => 'openid']);
            $this->assertTrue($status >= 400 && isset($body['error']), $g);
        }
    }

    public function testTokensAreStoredOnlyAsHashes(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid offline_access');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $dump = json_encode($this->ctx->app->db->all('SELECT * FROM oauth_access_tokens')) . json_encode($this->ctx->app->db->all('SELECT * FROM oauth_refresh_tokens')) . json_encode($this->ctx->app->db->all('SELECT * FROM oauth_auth_codes'));
        $this->assertStringNotContains($q['code'], $dump);
        $this->assertStringNotContains($tok['refresh_token'], $dump);
        $this->assertStringNotContains($tok['access_token'], $dump);
    }

    public function testIdTokenPassesReferenceRelyingPartyVerification(): void
    {
        require_once dirname(__DIR__) . '/examples/rp-php/lib.php';
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $claims = verify_id_token($tok['id_token'], $this->ctx->app->keys->jwks(), 'https://auth.test', $this->a['client_id'], 'nonce-abcdefgh', Clock::now());
        $this->assertSame($this->ctx->app->claims->sub($this->uid), $claims['sub']);
        $this->assertThrows(\RuntimeException::class, fn () => verify_id_token($tok['id_token'], $this->ctx->app->keys->jwks(), 'https://auth.test', $this->b['client_id'], 'nonce-abcdefgh', Clock::now()), 'Token von App A gilt nicht für App B');
        $this->assertThrows(\RuntimeException::class, fn () => verify_id_token($tok['id_token'], $this->ctx->app->keys->jwks(), 'https://auth.test', $this->a['client_id'], 'nonce-abcdefgh', Clock::now() + 4000), 'abgelaufen');
    }

    public function testAccessTokenManipulationAndExpiryAreRejected(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid profile');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        $userinfo = fn (string $t) => $br->get('/oidc/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $t])->status;
        $this->assertSame(200, $userinfo($tok['access_token']));

        [$h, $p, $sig] = explode('.', $tok['access_token']);
        $b64d = static fn (string $x) => base64_decode(strtr($x, '-_', '+/') . str_repeat('=', (4 - strlen($x) % 4) % 4));
        $b64e = static fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $claims = json_decode($b64d($p), true);
        $this->assertSame('at+jwt', json_decode($b64d($h), true)['typ']);

        $claims['scope'] = 'openid profile email roles';
        $this->assertSame(401, $userinfo($h . '.' . $b64e(json_encode($claims)) . '.' . $sig), 'Payload manipuliert');
        $none = $b64e(json_encode(['alg' => 'none', 'typ' => 'at+jwt'])) . '.' . $p . '.';
        $this->assertSame(401, $userinfo($none), 'alg=none');
        $this->assertSame(401, $userinfo($tok['id_token'] ?? ($h . '.' . $p . '.')), 'ID-Token ist kein Access Token');

        Clock::fix(Clock::now() + 601);
        $this->assertSame(401, $userinfo($tok['access_token']), 'abgelaufen');
        Clock::fix(Clock::now() - 601);
    }

    public function testRefreshTokenCannotBeUsedByAnotherClient(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb', 'openid offline_access');
        [, $tok] = $this->exchange($br, $this->a, $this->codeParams($q['code'], 'https://a.example/cb'));
        [$status] = $this->exchange($br, $this->b, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token']]);
        $this->assertSame(400, $status);
        [$ok] = $this->exchange($br, $this->a, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'scope' => 'openid profile email roles']);
        $this->assertSame(400, $ok, 'Scope-Erweiterung beim Refresh ist verboten');
    }

    public function testBasicAuthenticationAtTokenEndpoint(): void
    {
        $br = $this->loggedIn();
        $q = $this->authorize($br, $this->a, 'https://a.example/cb');
        $r = $br->post('/oidc/token', $this->codeParams($q['code'], 'https://a.example/cb'), false, ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(rawurlencode($this->a['client_id']) . ':' . rawurlencode($this->a['client_secret']))]);
        $this->assertSame(200, $r->status);
        $q2 = $this->authorize($br, $this->a, 'https://a.example/cb');
        $bad = $br->post('/oidc/token', $this->codeParams($q2['code'], 'https://a.example/cb'), false, ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($this->a['client_id'] . ':falsch')]);
        $this->assertSame(401, $bad->status);
        $this->assertStringContains('Basic', $bad->headers['WWW-Authenticate']);
    }
}
