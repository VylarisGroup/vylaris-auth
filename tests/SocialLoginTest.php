<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Tests\Support\{Factory, FakeIdp, TestBrowser, TestCase};

/** Social Login: OAuth2-/OIDC-Client gegen simulierte Anbieter (ohne Netzwerk). */
final class SocialLoginTest extends TestCase
{
    private object $ctx;
    private FakeIdp $idp;
    private int $admin;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $this->idp = new FakeIdp();
        $this->ctx = Factory::app([], $this->idp);
        $a = $this->ctx->app;
        $this->admin = $a->users->create('admin@example.org', $a->passwords->hash(self::PW), 'Admin', true);
        $a->users->assignRole($this->admin, 'superadmin');
        foreach ([['google', 'google'], ['github', 'github'], ['discord', 'discord'], ['microsoft', 'microsoft'], ['oidc', 'oidc']] as [$type, $key]) {
            $a->social->saveProvider($this->admin, [
                'type' => $type, 'provider_key' => $key, 'label' => ucfirst($type), 'client_id' => 'client-123', 'client_secret' => FakeIdp::SECRET,
                'issuer' => $type === 'oidc' ? 'https://idp.example.org' : '', 'enabled' => true, 'allow_signup' => true,
            ]);
        }
    }

    private function google(array $claims = []): array
    {
        return $claims + ['sub' => 'g-100', 'email' => 'Nina@Example.org', 'email_verified' => true, 'name' => 'Nina Neu'];
    }

    /** Kompletter Flow: Start -> Anbieter -> Callback. Gibt die Callback-Antwort zurück. */
    private function flow(TestBrowser $b, string $provider, array $profile, string $startQuery = '', ?callable $beforeCallback = null): \Vylaris\Core\Response
    {
        $start = $b->get('/social/' . $provider . '/login', $startQuery === '' ? [] : ['next' => $startQuery]);
        $this->assertSame(302, $start->status);
        $params = $this->idp->authorize($start->headers['Location'], $profile);
        if ($beforeCallback) {
            $params = $beforeCallback($params);
        }
        return $b->get('/social/' . $provider . '/callback', $params);
    }

    private function user(string $email): ?array
    {
        return $this->ctx->app->users->findByEmail($email);
    }

    private function enableTotp(int $uid): string
    {
        $a = $this->ctx->app;
        $setup = $a->mfa->beginTotpSetup($uid, 'x@example.org');
        $a->mfa->confirmTotp($uid, $a->totp->codeAt($setup['secret'], $a->totp->currentStep()));
        Clock::advance(60);
        return $setup['secret'];
    }

    // ------------------------------------------------------------ Anbieter-Verwaltung

    public function testClientSecretIsEncryptedAndNeverListed(): void
    {
        $raw = (string) $this->ctx->db->scalar("SELECT client_secret_enc FROM social_providers WHERE provider_key = 'google'");
        $this->assertStringNotContains(FakeIdp::SECRET, $raw);
        $this->assertTrue($raw !== '');
        foreach ($this->ctx->app->social->providers() as $p) {
            $this->assertFalse(isset($p['client_secret_enc']));
        }
    }

    public function testEditKeepsSecretWhenLeftBlank(): void
    {
        $before = $this->ctx->db->scalar("SELECT client_secret_enc FROM social_providers WHERE provider_key = 'github'");
        $this->ctx->app->social->saveProvider($this->admin, ['label' => 'GH', 'client_id' => 'client-123', 'client_secret' => '', 'enabled' => true], 'github');
        $this->assertSame($before, $this->ctx->db->scalar("SELECT client_secret_enc FROM social_providers WHERE provider_key = 'github'"));
        $this->assertSame('GH', $this->ctx->app->social->provider('github')['label']);
    }

    public function testProviderValidation(): void
    {
        $s = $this->ctx->app->social;
        $base = ['type' => 'oidc', 'provider_key' => 'kc', 'client_id' => 'c', 'client_secret' => 's'];
        foreach (['http://idp.example.org', 'https://localhost', 'https://127.0.0.1', 'https://10.0.0.5', 'https://idp.example.org:8443', 'https://user:pw@idp.example.org', 'ftp://x.org', ''] as $bad) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, $base + ['issuer' => $bad]), $bad);
        }
        $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, ['type' => 'nope', 'client_id' => 'c', 'client_secret' => 's']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, ['type' => 'google', 'client_id' => 'c', 'client_secret' => 's', 'provider_key' => 'google']), 'doppelter Schlüssel');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, ['type' => 'github', 'provider_key' => 'Bad Key!', 'client_id' => 'c', 'client_secret' => 's']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, ['type' => 'github', 'provider_key' => 'gh2', 'client_id' => 'c', 'client_secret' => '']));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $s->saveProvider($this->admin, ['type' => 'microsoft', 'provider_key' => 'ms2', 'client_id' => 'c', 'client_secret' => 's', 'issuer' => 'evil.example']));
    }

    public function testLoginPageShowsOnlyEnabledProviders(): void
    {
        $this->ctx->app->social->setEnabled($this->admin, 'discord', false);
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('/social/google/login', $html);
        $this->assertStringContains('/social/github/login', $html);
        $this->assertStringNotContains('/social/discord/login', $html);
    }

    public function testDisabledOrUnknownProviderIsUnavailable(): void
    {
        $this->ctx->app->social->setEnabled($this->admin, 'discord', false);
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(404, $b->get('/social/discord/login')->status);
        $this->assertSame(404, $b->get('/social/gibtsnicht/login')->status);
        $this->assertSame(404, $b->get('/social/%3Cscript%3E/login')->status);
    }

    // ------------------------------------------------------------ Autorisierungsanfrage

    public function testAuthorizationRequestHasStatePkceNonceAndExactRedirectUri(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $loc = $b->get('/social/google/login')->headers['Location'];
        $this->assertTrue(str_starts_with($loc, 'https://accounts.google.com/o/oauth2/v2/auth?'));
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('https://auth.test/social/google/callback', $q['redirect_uri']);
        $this->assertTrue(strlen($q['state']) >= 40 && strlen($q['nonce']) >= 40 && strlen($q['code_challenge']) >= 40);
        $this->assertStringContains('openid', $q['scope']);
        // GitHub (kein OIDC) bekommt keine nonce
        parse_str((string) parse_url($b->get('/social/github/login')->headers['Location'], PHP_URL_QUERY), $g);
        $this->assertFalse(isset($g['nonce']));
    }

    // ------------------------------------------------------------ Registrierung / Login (Google)

    public function testSignupViaGoogleCreatesVerifiedPasswordlessAccountAndLogsIn(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $before = $b->session()->id();
        $r = $this->flow($b, 'google', $this->google());
        $this->assertSame(302, $r->status);
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertTrue($before !== $b->session()->id(), 'Session-ID muss rotieren');

        $u = $this->user('nina@example.org');
        $this->assertNotNull($u);
        $this->assertNotNull($u['email_verified_at']);
        $this->assertSame(0, (int) $u['password_set']);
        $this->assertSame('Nina Neu', $u['display_name']);
        $this->assertSame(['fed'], $b->session()->get('amr'));
        $this->assertStringContains('Nina Neu', $b->get('/account')->body);
        $this->assertSame(1, count($this->ctx->app->social->identitiesFor((int) $u['id'])));
        // Konto ohne Passwort: kein Passwort-Formular, Passwort-Login unmöglich
        $this->assertStringNotContains('name="current_password"', $b->get('/account')->body);
        $other = new TestBrowser($this->ctx->app, '203.0.113.9');
        $this->assertSame(401, $other->post('/login', ['email' => 'nina@example.org', 'password' => ''])->status);
    }

    public function testSecondLoginUsesExistingIdentityEvenIfEmailChanges(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $b = new TestBrowser($this->ctx->app, '203.0.113.20');
        $r = $this->flow($b, 'google', $this->google(['email' => 'ganz-andere@example.org']));
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertNull($this->user('ganz-andere@example.org'));
        $this->assertSame(2, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM users'), 'Admin + Nina, keine Dubletten');
        $this->assertStringContains('Nina Neu', $b->get('/account')->body);
    }

    public function testNextParameterIsHonouredButOpenRedirectIsNot(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame('/account/sessions', $this->flow($b, 'google', $this->google(), '/account/sessions')->headers['Location']);
        $b2 = new TestBrowser($this->ctx->app, '203.0.113.30');
        $this->assertSame('/account', $this->flow($b2, 'google', $this->google(), '//evil.example/x')->headers['Location']);
        $b3 = new TestBrowser($this->ctx->app, '203.0.113.31');
        $this->assertSame('/account', $this->flow($b3, 'google', $this->google(), 'https://evil.example')->headers['Location']);
    }

    // ------------------------------------------------------------ Konto-Übernahme verhindern

    public function testUnverifiedEmailCannotSignUp(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $this->flow($b, 'google', $this->google(['email_verified' => false]));
        $this->assertSame(400, $r->status);
        $this->assertNull($this->user('nina@example.org'));
        $this->assertNull($b->session()->userId());
    }

    public function testExistingEmailIsNeverAutoLinked(): void
    {
        $a = $this->ctx->app;
        $uid = $a->users->create('nina@example.org', $a->passwords->hash(self::PW), 'Echte Nina', true);
        $b = new TestBrowser($a);
        $r = $this->flow($b, 'google', $this->google());
        $this->assertSame(400, $r->status);
        $this->assertStringContains('existiert bereits ein Konto', $r->body);
        $this->assertNull($b->session()->userId());
        $this->assertSame(0, count($a->social->identitiesFor($uid)));
    }

    public function testUnknownIdentityWithSignupDisabledIsRefused(): void
    {
        $this->ctx->app->social->saveProvider($this->admin, ['label' => 'Google', 'client_id' => 'client-123', 'client_secret' => '', 'enabled' => true, 'allow_signup' => false], 'google');
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(400, $this->flow($b, 'google', $this->google())->status);
        $this->assertNull($this->user('nina@example.org'));
    }

    public function testGlobalRegistrationSwitchAlsoBlocksSocialSignup(): void
    {
        $ctx = Factory::app(['security' => ['allow_registration' => false]], $this->idp);
        $ctx->app->social->saveProvider($this->admin, ['type' => 'google', 'client_id' => 'client-123', 'client_secret' => FakeIdp::SECRET, 'enabled' => true, 'allow_signup' => true]);
        $b = new TestBrowser($ctx->app);
        $start = $b->get('/social/google/login');
        $params = $this->idp->authorize($start->headers['Location'], $this->google());
        $this->assertSame(400, $b->get('/social/google/callback', $params)->status);
    }

    public function testLockedAccountCannotLoginViaProvider(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $u = $this->user('nina@example.org');
        $this->ctx->app->users->setLocked((int) $u['id'], true, 'test');
        $b = new TestBrowser($this->ctx->app, '203.0.113.40');
        $r = $this->flow($b, 'google', $this->google());
        $this->assertSame(400, $r->status);
        $this->assertNull($b->session()->userId());
    }

    public function testUnverifiedLocalAccountNeedsMatchingVerifiedProviderEmail(): void
    {
        $a = $this->ctx->app;
        // Konto per Admin angelegt, E-Mail noch unbestätigt, später verknüpft
        $uid = $a->users->create('nina@example.org', $a->passwords->hash(self::PW), 'Nina', false);
        $this->ctx->db->insert('social_identities', ['user_id' => $uid, 'provider_key' => 'google', 'subject' => 'g-100', 'created_at' => Clock::db()]);
        $b = new TestBrowser($a);
        $this->assertSame('/account', $this->flow($b, 'google', $this->google())->headers['Location']);
        $this->assertNotNull($a->users->findById($uid)['email_verified_at'], 'Anbieter bestätigt dieselbe Adresse');

        $uid2 = $a->users->create('bob@example.org', $a->passwords->hash(self::PW), 'Bob', false);
        $this->ctx->db->insert('social_identities', ['user_id' => $uid2, 'provider_key' => 'google', 'subject' => 'g-200', 'created_at' => Clock::db()]);
        $b2 = new TestBrowser($a, '203.0.113.50');
        $r = $this->flow($b2, 'google', $this->google(['sub' => 'g-200', 'email' => 'jemand-anders@example.org']));
        $this->assertSame(400, $r->status);
        $this->assertNull($b2->session()->userId());
    }

    // ------------------------------------------------------------ Protokoll-Prüfungen

    public function testStateMismatchAndReplayAreRejected(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $this->flow($b, 'google', $this->google(), '', fn (array $p) => ['state' => 'falsch'] + $p);
        $this->assertSame(400, $r->status);
        $this->assertNull($this->user('nina@example.org'));

        // Replay: derselbe Callback ein zweites Mal
        $b2 = new TestBrowser($this->ctx->app, '203.0.113.60');
        $start = $b2->get('/social/google/login');
        $params = $this->idp->authorize($start->headers['Location'], $this->google());
        $this->assertSame(302, $b2->get('/social/google/callback', $params)->status);
        $b2->post('/logout');
        $this->assertSame(400, $b2->get('/social/google/callback', $params)->status);
    }

    public function testCallbackWithoutStartedFlowIsRejected(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(400, $b->get('/social/google/callback', ['code' => 'x', 'state' => 'y'])->status);
    }

    public function testFlowStateBelongsToOneProvider(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $start = $b->get('/social/google/login');
        $params = $this->idp->authorize($start->headers['Location'], $this->google());
        $this->assertSame(400, $b->get('/social/github/callback', $params)->status);
    }

    public function testFlowExpires(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $this->flow($b, 'google', $this->google(), '', function (array $p) {
            Clock::advance(601);
            return $p;
        });
        $this->assertSame(400, $r->status);
    }

    public function testProviderDenialShowsGenericMessage(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $start = $b->get('/social/google/login');
        parse_str((string) parse_url($start->headers['Location'], PHP_URL_QUERY), $q);
        $r = $b->get('/social/google/callback', ['error' => 'access_denied', 'error_description' => '<script>x</script>', 'state' => $q['state']]);
        $this->assertSame(400, $r->status);
        $this->assertStringNotContains('<script>', $r->body);
        $this->assertStringContains('abgebrochen', $r->body);
    }

    public function testPkceIsEnforcedByProviderAndVerifierIsSent(): void
    {
        // Der Fake-Anbieter prüft den code_verifier gegen die code_challenge – erfolgreicher Login beweist, dass er korrekt gesendet wurde.
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame('/account', $this->flow($b, 'google', $this->google())->headers['Location']);
        $this->assertTrue(count(array_filter($this->idp->log, fn ($l) => str_contains($l, 'POST https://oauth2.googleapis.com/token'))) === 1);
    }

    /** @return array<string,array> Manipulationen am ID-Token, die alle abgelehnt werden müssen */
    private function badTokens(): array
    {
        return [
            'falsches nonce' => [['nonce' => 'anderes-nonce']],
            'fehlendes nonce' => [['nonce' => null]],
            'falsche audience' => [['aud' => 'fremde-app']],
            'falscher issuer' => [['iss' => 'https://evil.example']],
            'abgelaufen' => [['exp' => Clock::now() - 3600]],
            'iat in der Zukunft' => [['iat' => Clock::now() + 3600]],
            'fehlendes sub' => [['sub' => '']],
            'aud-Liste ohne azp' => [['aud' => ['client-123', 'andere']]],
        ];
    }

    public function testManipulatedIdTokensAreRejected(): void
    {
        $i = 0;
        foreach ($this->badTokens() as $label => [$override]) {
            $b = new TestBrowser($this->ctx->app, '198.51.100.' . (++$i));
            $r = $this->flow($b, 'google', $this->google($override));
            $this->assertSame(400, $r->status, $label);
            $this->assertNull($b->session()->userId(), $label);
        }
        $this->assertNull($this->user('nina@example.org'));
    }

    public function testIdTokenSignedWithForeignKeyIsRejected(): void
    {
        $this->idp->attackerPem = FakeIdp::attackerKey();
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(400, $this->flow($b, 'google', $this->google())->status);
        $this->assertNull($this->user('nina@example.org'));
    }

    public function testAlgNoneAndHmacTokensAreRejected(): void
    {
        $verify = fn (string $jwt) => \Vylaris\Social\IdTokenVerifier::verify($jwt, [], fn () => true, 'client-123', 'n');
        $none = Jwt_b64('{"alg":"none"}') . '.' . Jwt_b64('{"iss":"x","aud":"client-123","sub":"s","nonce":"n","exp":' . (Clock::now() + 100) . '}') . '.';
        $this->assertThrows(\Vylaris\Social\SocialException::class, fn () => $verify($none));
        $hs = Jwt_b64('{"alg":"HS256"}') . '.' . Jwt_b64('{"sub":"s"}') . '.' . Jwt_b64('sig');
        $this->assertThrows(\Vylaris\Social\SocialException::class, fn () => $verify($hs));
    }

    public function testJwksIsRefetchedAfterProviderKeyRotation(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $fetches = $this->idp->jwksFetches;
        $this->idp->newKey(); // Anbieter rotiert, gecachtes JWKS ist veraltet
        $b = new TestBrowser($this->ctx->app, '203.0.113.70');
        $this->assertSame('/account', $this->flow($b, 'google', $this->google())->headers['Location']);
        $this->assertTrue($this->idp->jwksFetches === $fetches + 1, 'genau ein zusätzlicher Abruf');
    }

    public function testJwksIsCachedBetweenLogins(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $this->flow(new TestBrowser($this->ctx->app, '203.0.113.71'), 'google', $this->google());
        $this->assertSame(1, $this->idp->jwksFetches);
    }

    public function testWrongClientSecretFailsAtProvider(): void
    {
        $this->ctx->app->social->saveProvider($this->admin, ['label' => 'Google', 'client_id' => 'client-123', 'client_secret' => 'falsch', 'enabled' => true, 'allow_signup' => true], 'google');
        $this->assertSame(400, $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google())->status);
    }

    // ------------------------------------------------------------ GitHub / Discord

    public function testGithubSignupUsesOnlyPrimaryVerifiedEmail(): void
    {
        $profile = ['id' => 4711, 'login' => 'octo', 'name' => 'Octo Cat', 'emails' => [
            ['email' => 'unverified@example.org', 'primary' => false, 'verified' => false],
            ['email' => 'octo@example.org', 'primary' => true, 'verified' => true],
        ]];
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame('/account', $this->flow($b, 'github', $profile)->headers['Location']);
        $this->assertNotNull($this->user('octo@example.org'));
        $this->assertNull($this->user('unverified@example.org'));
        $this->assertSame('4711', $this->ctx->db->scalar("SELECT subject FROM social_identities WHERE provider_key = 'github'"));
    }

    public function testGithubWithoutVerifiedEmailCannotSignUp(): void
    {
        $profile = ['id' => 5, 'login' => 'octo', 'emails' => [['email' => 'x@example.org', 'primary' => true, 'verified' => false]]];
        $this->assertSame(400, $this->flow(new TestBrowser($this->ctx->app), 'github', $profile)->status);
        $this->assertNull($this->user('x@example.org'));
    }

    public function testDiscordSignupRequiresVerifiedEmail(): void
    {
        $ok = ['id' => '9001', 'username' => 'disc', 'global_name' => 'Disc Ord', 'email' => 'disc@example.org', 'verified' => true];
        $this->assertSame('/account', $this->flow(new TestBrowser($this->ctx->app), 'discord', $ok)->headers['Location']);
        $this->assertSame('Disc Ord', $this->user('disc@example.org')['display_name']);
        $bad = ['id' => '9002', 'username' => 'disc2', 'email' => 'disc2@example.org', 'verified' => false];
        $this->assertSame(400, $this->flow(new TestBrowser($this->ctx->app, '203.0.113.80'), 'discord', $bad)->status);
        $this->assertNull($this->user('disc2@example.org'));
    }

    public function testDiscordRequestsCarryDiscordCompliantUserAgent(): void
    {
        $ok = ['id' => '9101', 'username' => 'ua', 'email' => 'ua@example.org', 'verified' => true];
        $this->flow(new TestBrowser($this->ctx->app), 'discord', $ok);
        $seen = 0;
        foreach ($this->idp->headerLog as [$url, $headers]) {
            if (str_starts_with($url, 'https://discord.com/')) {
                $seen++;
                $ua = array_values(array_filter($headers, static fn (string $h) => stripos($h, 'user-agent:') === 0));
                $this->assertSame(['User-Agent: DiscordBot (https://auth.test, 1.0)'], $ua, $url);
            }
        }
        $this->assertSame(2, $seen, 'Token-Endpunkt und Profil');
    }

    public function testAdminSeesRecentProviderFailuresWithExplanation(): void
    {
        $a = $this->ctx->app;
        $a->audit->log('social.failed', null, ['provider' => 'discord', 'reason' => 'token_exchange_failed:http_403', 'mode' => 'login']);
        $a->audit->log('social.failed', null, ['provider' => 'github', 'reason' => 'state_mismatch', 'mode' => 'login']);
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'admin@example.org', 'password' => self::PW]);
        $page = $b->get('/admin/social/discord')->body;
        $this->assertStringContains('Letzte fehlgeschlagene Anmeldungen', $page);
        $this->assertStringContains('token_exchange_failed:http_403', $page);
        $this->assertStringContains('blockiert die Anfrage', $page);
        $this->assertStringNotContains('state_mismatch', $page, 'nur Fehler dieses Anbieters');
        $this->assertStringNotContains('Letzte fehlgeschlagene Anmeldungen', $b->get('/admin/social/google')->body);
    }

    // ------------------------------------------------------------ Microsoft

    public function testMicrosoftNeverCreatesAccountsFromUnverifiableEmail(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $this->flow($b, 'microsoft', ['sub' => 'ms-1', 'email' => 'victim@example.org', 'name' => 'Angreifer']);
        $this->assertSame(400, $r->status);
        $this->assertNull($this->user('victim@example.org'));
    }

    public function testMicrosoftLinkedIdentityLoginIsTenantScoped(): void
    {
        $a = $this->ctx->app;
        $uid = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $this->linkVia($b, 'microsoft', ['sub' => 'ms-1', 'tid' => '11111111-2222-3333-4444-555555555555'], true);
        $this->assertSame('11111111-2222-3333-4444-555555555555:ms-1', $this->ctx->db->scalar("SELECT subject FROM social_identities WHERE user_id = {$uid}"));

        // Gleiches "sub" in anderem Mandanten ist eine andere Identität -> kein Zugang
        $other = new TestBrowser($a, '203.0.113.90');
        $r = $this->flow($other, 'microsoft', ['sub' => 'ms-1', 'tid' => '99999999-2222-3333-4444-555555555555', 'email' => 'anna@example.org']);
        $this->assertSame(400, $r->status);
        $this->assertNull($other->session()->userId());
        // Richtiger Mandant -> Zugang
        $ok = new TestBrowser($a, '203.0.113.91');
        $this->assertSame('/account', $this->flow($ok, 'microsoft', ['sub' => 'ms-1', 'tid' => '11111111-2222-3333-4444-555555555555'])->headers['Location']);
    }

    public function testMicrosoftIssuerMustMatchTenantClaim(): void
    {
        $a = $this->ctx->app;
        $a->social->saveProvider($this->admin, ['label' => 'MS', 'client_id' => 'client-123', 'client_secret' => '', 'issuer' => '11111111-2222-3333-4444-555555555555', 'enabled' => true, 'allow_signup' => false], 'microsoft');
        $b = new TestBrowser($a);
        $r = $this->flow($b, 'microsoft', ['sub' => 'x', 'tid' => '99999999-2222-3333-4444-555555555555']);
        $this->assertSame(400, $r->status);
        $this->assertStringContains('fehlgeschlagen', $r->body);
    }

    // ------------------------------------------------------------ Generischer OIDC-Anbieter

    public function testGenericOidcWithDiscoveryAndBasicAuth(): void
    {
        $this->idp->genericUsesBasicAuth = true;
        $b = new TestBrowser($this->ctx->app);
        $r = $this->flow($b, 'oidc', ['sub' => 'kc-1', 'email' => 'kc@example.org', 'email_verified' => true, 'name' => 'Key Cloak']);
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertNotNull($this->user('kc@example.org'));
        $this->assertTrue(in_array('GET https://idp.example.org/.well-known/openid-configuration', $this->idp->log, true));
    }

    public function testGenericOidcRejectsTokenFromOtherIssuer(): void
    {
        $r = $this->flow(new TestBrowser($this->ctx->app), 'oidc', ['sub' => 'kc-1', 'email' => 'kc@example.org', 'email_verified' => true, 'iss' => 'https://idp.example.org.evil.test']);
        $this->assertSame(400, $r->status);
    }

    public function testStringEmailVerifiedFalseIsNotTrue(): void
    {
        foreach ([false, 'false', 0, null, 'yes', 1] as $i => $v) {
            $r = $this->flow(new TestBrowser($this->ctx->app, '192.0.2.' . ($i + 1)), 'google', $this->google(['email_verified' => $v, 'sub' => 'g-' . $i, 'email' => "x{$i}@example.org"]));
            $this->assertSame(400, $r->status, var_export($v, true));
        }
    }

    // ------------------------------------------------------------ MFA bleibt Pflicht

    public function testOwnSecondFactorIsStillRequiredAfterProviderLogin(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $uid = (int) $this->user('nina@example.org')['id'];
        $secret = $this->enableTotp($uid);

        $b = new TestBrowser($this->ctx->app, '203.0.113.100');
        $r = $this->flow($b, 'google', $this->google(), '/account/sessions');
        $this->assertSame('/mfa', $r->headers['Location']);
        $this->assertNull($b->session()->userId(), 'noch nicht angemeldet');
        $this->assertSame(302, $b->get('/account')->status);

        $code = $this->ctx->app->totp->codeAt($secret, $this->ctx->app->totp->currentStep());
        $r = $b->post('/mfa/totp', ['code' => $code]);
        $this->assertSame('/account/sessions', $r->headers['Location']);
        $this->assertSame(['fed', 'otp', 'mfa'], $b->session()->get('amr'));
        $this->assertSame(200, $b->get('/account')->status);
    }

    public function testPasswordLoginAmrIsUnaffectedByEarlierSocialAttempt(): void
    {
        $a = $this->ctx->app;
        $uid = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $secret = $this->enableTotp($uid);
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $b->post('/mfa/totp', ['code' => $a->totp->codeAt($secret, $a->totp->currentStep())]);
        $this->assertSame(['pwd', 'otp', 'mfa'], $b->session()->get('amr'));
    }

    public function testAdminAreaStillNeedsMfaAfterProviderLogin(): void
    {
        $ctx = Factory::app(['security' => ['admin_requires_mfa' => true]], $this->idp);
        $a = $ctx->app;
        $a->social->saveProvider($this->admin, ['type' => 'google', 'client_id' => 'client-123', 'client_secret' => FakeIdp::SECRET, 'enabled' => true, 'allow_signup' => true]);
        $uid = $a->users->create('chef@example.org', $a->passwords->hash(self::PW), 'Chef', true);
        $a->users->assignRole($uid, 'superadmin');
        $ctx->db->insert('social_identities', ['user_id' => $uid, 'provider_key' => 'google', 'subject' => 'g-chef', 'created_at' => Clock::db()]);
        $b = new TestBrowser($a);
        $start = $b->get('/social/google/login');
        $b->get('/social/google/callback', $this->idp->authorize($start->headers['Location'], ['sub' => 'g-chef', 'email' => 'chef@example.org', 'email_verified' => true]));
        $this->assertSame($uid, $b->session()->userId());
        $r = $b->get('/admin/social');
        $this->assertSame(302, $r->status, 'ohne zweiten Faktor kein Admin-Zugang');
        $this->assertStringNotContains('Social Login', $r->body);
    }

    // ------------------------------------------------------------ Verknüpfen / Trennen im Konto

    /** Führt den Link-Flow für den angemeldeten Browser aus. */
    private function linkVia(TestBrowser $b, string $provider, array $profile, bool $withPassword): \Vylaris\Core\Response
    {
        $data = $withPassword ? ['password' => self::PW] : [];
        $r = $b->post('/account/social/' . $provider . '/link', $data);
        $this->assertSame(302, $r->status);
        $params = $this->idp->authorize($r->headers['Location'], $profile);
        return $b->get('/social/' . $provider . '/callback', $params);
    }

    public function testLinkFlowRequiresLoginPasswordAndCsrf(): void
    {
        $a = $this->ctx->app;
        $uid = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $anon = new TestBrowser($a);
        $this->assertSame(302, $anon->post('/account/social/google/link')->status);
        $this->assertStringContains('/login', $anon->last->headers['Location']);

        $b = new TestBrowser($a, '203.0.113.110');
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $this->assertSame(403, $b->post('/account/social/google/link', ['password' => self::PW], false)->status, 'CSRF');
        $this->assertSame(403, $b->post('/account/social/google/link', ['password' => 'falsch'])->status);
        $this->assertSame(403, $b->post('/account/social/google/link', [])->status);

        $r = $this->linkVia($b, 'google', $this->google(['sub' => 'g-anna', 'email' => 'anna@gmail.example']), true);
        $this->assertSame('/account/social', $r->headers['Location']);
        $this->assertSame(1, count($a->social->identitiesFor($uid)));
        $this->assertStringContains('Google', $b->get('/account/social')->body);
        // Später Login per Anbieter funktioniert, obwohl E-Mail unterschiedlich ist
        $other = new TestBrowser($a, '203.0.113.111');
        $this->assertSame('/account', $this->flow($other, 'google', $this->google(['sub' => 'g-anna', 'email' => 'anna@gmail.example']))->headers['Location']);
        $this->assertSame($uid, $other->session()->userId());
    }

    public function testLinkConfirmationIsRateLimited(): void
    {
        $a = $this->ctx->app;
        $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        for ($i = 0; $i < 5; $i++) {
            $b->post('/account/social/google/link', ['password' => 'falsch']);
        }
        $this->assertSame(403, $b->post('/account/social/google/link', ['password' => self::PW])->status, 'auch das richtige Passwort wird gesperrt');
    }

    public function testIdentityOfAnotherAccountCannotBeLinked(): void
    {
        $a = $this->ctx->app;
        $this->flow(new TestBrowser($a), 'google', $this->google()); // Nina besitzt g-100
        $uid = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $b = new TestBrowser($a, '203.0.113.120');
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $r = $this->linkVia($b, 'google', $this->google(), true);
        $this->assertSame('/account/social', $r->headers['Location']);
        $this->assertStringContains('anderen Konto', $b->get('/account/social')->body);
        $this->assertSame(0, count($a->social->identitiesFor($uid)));
    }

    public function testLinkFlowCannotBeCompletedByAnotherSession(): void
    {
        $a = $this->ctx->app;
        $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $a->users->create('bob@example.org', $a->passwords->hash(self::PW), 'Bob', true);
        $b = new TestBrowser($a, '203.0.113.130');
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $start = $b->post('/account/social/google/link', ['password' => self::PW]);
        $params = $this->idp->authorize($start->headers['Location'], $this->google(['sub' => 'g-x']));
        // Der Callback wird einem anderen Browser untergeschoben (Login-CSRF/Verknüpfungs-Fixierung)
        $victim = new TestBrowser($a, '203.0.113.131');
        $victim->post('/login', ['email' => 'bob@example.org', 'password' => self::PW]);
        $r = $victim->get('/social/google/callback', $params);
        $this->assertSame(400, $r->status, 'ohne eigenen Flow-Zustand abgelehnt');
        $this->assertSame(0, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM social_identities WHERE subject = 'g-x'"));
    }

    public function testLoggedInUserDoesNotSwitchAccountViaLoginFlow(): void
    {
        $a = $this->ctx->app;
        $this->flow(new TestBrowser($a), 'google', $this->google());
        $uid = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $b = new TestBrowser($a, '203.0.113.140');
        $start = $b->get('/social/google/login'); // Flow im ausgeloggten Zustand gestartet ...
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]); // ... dann Passwort-Login
        $r = $b->get('/social/google/callback', $this->idp->authorize($start->headers['Location'], $this->google()));
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertSame($uid, $b->session()->userId());
    }

    public function testUnlinkIsBlockedForLastLoginMethodAndAllowedOtherwise(): void
    {
        $a = $this->ctx->app;
        $b = new TestBrowser($a);
        $this->flow($b, 'google', $this->google());
        $uid = (int) $this->user('nina@example.org')['id'];
        $r = $b->post('/account/social/google/unlink');
        $this->assertSame(422, $r->status);
        $this->assertSame(1, count($a->social->identitiesFor($uid)));

        // zweiter Anbieter -> Trennen erlaubt
        $this->linkVia($b, 'github', ['id' => 77, 'login' => 'nina', 'emails' => []], false);
        $this->assertSame(2, count($a->social->identitiesFor($uid)));
        $this->assertSame(302, $b->post('/account/social/google/unlink')->status);
        $this->assertSame(1, count($a->social->identitiesFor($uid)));
        $this->assertSame(422, $b->post('/account/social/github/unlink')->status);
    }

    public function testPasswordResetGivesSocialOnlyAccountAPasswordAndUnlinkBecomesPossible(): void
    {
        $a = $this->ctx->app;
        $this->flow(new TestBrowser($a), 'google', $this->google());
        $uid = (int) $this->user('nina@example.org')['id'];
        $a->auth->requestPasswordReset('nina@example.org', '203.0.113.150');
        $a->mailer->flush();
        preg_match('/token=([A-Za-z0-9_-]+)/', $this->ctx->mail->last()['body'], $m);
        $this->assertSame([], $a->auth->resetPassword($m[1], 'Ein-Sehr-Sicheres-Passwort-77'));
        $this->assertSame(1, (int) $a->users->findById($uid)['password_set']);
        $b = new TestBrowser($a, '203.0.113.151');
        $this->assertSame('/account', $b->post('/login', ['email' => 'nina@example.org', 'password' => 'Ein-Sehr-Sicheres-Passwort-77'])->headers['Location']);
        $this->assertSame(302, $b->post('/account/social/google/unlink', ['password' => 'Ein-Sehr-Sicheres-Passwort-77'])->status);
        $this->assertSame(0, count($a->social->identitiesFor($uid)));
    }

    public function testDeletingUserRemovesIdentities(): void
    {
        $a = $this->ctx->app;
        $this->flow(new TestBrowser($a), 'google', $this->google());
        $uid = (int) $this->user('nina@example.org')['id'];
        if ($this->ctx->db->driver() === 'sqlite') {
            $this->ctx->db->pdo()->exec('PRAGMA foreign_keys = ON');
        }
        $this->ctx->db->run('DELETE FROM users WHERE id = ?', [$uid]);
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM social_identities'));
    }

    // ------------------------------------------------------------ Admin-UI

    public function testAdminUiIsRestrictedToAdmins(): void
    {
        $a = $this->ctx->app;
        $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $this->assertSame(403, $b->get('/admin/social')->status);
        $this->assertSame(403, $b->get('/admin/social/google')->status);
        $this->assertSame(403, $b->post('/admin/social', ['type' => 'github', 'client_id' => 'x', 'client_secret' => 'y'])->status);
        $this->assertSame(403, $b->post('/admin/social/google/action', ['action' => 'delete', 'confirm' => '1'])->status);
        $this->assertNotNull($a->social->provider('google'));
        $anon = new TestBrowser($a, '203.0.113.160');
        $this->assertSame(302, $anon->get('/admin/social')->status);
    }

    public function testAdminCanCreateEditDisableAndDeleteProviderViaUi(): void
    {
        $a = $this->ctx->app;
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'admin@example.org', 'password' => self::PW]);
        $page = $b->get('/admin/social');
        $this->assertSame(200, $page->status);
        $this->assertStringContains('Social Login', $page->body);

        $r = $b->post('/admin/social', ['type' => 'gitlab', 'provider_key' => 'gitlab-intern', 'label' => 'GitLab intern', 'client_id' => 'cid', 'client_secret' => 'geheim-123', 'issuer' => 'https://gitlab.example.org', 'enabled' => '1', 'allow_signup' => '1']);
        $this->assertSame('/admin/social/gitlab-intern', $r->headers['Location']);
        $edit = $b->get('/admin/social/gitlab-intern');
        $this->assertStringContains('https://auth.test/social/gitlab-intern/callback', $edit->body);
        $this->assertStringNotContains('geheim-123', $edit->body);

        $b->post('/admin/social/gitlab-intern/action', ['action' => 'disable']);
        $this->assertSame(0, (int) $a->social->provider('gitlab-intern')['enabled']);
        $bad = $b->post('/admin/social', ['type' => 'oidc', 'provider_key' => 'zzz', 'client_id' => 'c', 'client_secret' => 's', 'issuer' => 'http://insecure.example.org']);
        $this->assertSame('/admin/social', $bad->headers['Location']);
        $this->assertStringContains('https://', $b->get('/admin/social')->body);

        $this->assertSame('/admin/social/gitlab-intern', $b->post('/admin/social/gitlab-intern/action', ['action' => 'delete'])->headers['Location'], 'ohne Bestätigung kein Löschen');
        $this->assertNotNull($a->social->provider('gitlab-intern'));
        $b->post('/admin/social/gitlab-intern/action', ['action' => 'delete', 'confirm' => '1']);
        $this->assertNull($a->social->provider('gitlab-intern'));
    }

    // ------------------------------------------------------------ Audit

    public function testAuditHasEventsWithoutSecretsOrTokens(): void
    {
        $this->flow(new TestBrowser($this->ctx->app), 'google', $this->google());
        $this->flow(new TestBrowser($this->ctx->app, '203.0.113.170'), 'google', $this->google(['aud' => 'x']));
        $events = array_column($this->ctx->app->audit->recent(200), 'event');
        foreach (['social.provider_created', 'social.signup', 'social.failed'] as $ev) {
            $this->assertTrue(in_array($ev, $events, true), $ev);
        }
        $dump = json_encode($this->ctx->app->audit->recent(500));
        $this->assertStringNotContains(FakeIdp::SECRET, $dump);
        $this->assertStringNotContains('code-', $dump);
        $this->assertStringNotContains('at-', $dump);
    }

    public function testStartAndCallbackAreRateLimited(): void
    {
        $b = new TestBrowser($this->ctx->app);
        for ($i = 0; $i < 30; $i++) {
            $b->get('/social/google/login');
        }
        $this->assertSame(429, $b->get('/social/google/login')->status);
    }

    public function testJwkConversionProducesVerifiableKey(): void
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($k);
        $pem = \Vylaris\Social\Jwk::rsaToPem(['kty' => 'RSA', 'n' => \Vylaris\Oidc\Jwt::b64u($d['rsa']['n']), 'e' => \Vylaris\Oidc\Jwt::b64u($d['rsa']['e'])]);
        $this->assertSame(trim($d['key']), trim((string) $pem));
        $this->assertNull(\Vylaris\Social\Jwk::rsaToPem(['kty' => 'EC', 'n' => 'AQ', 'e' => 'AQ']));
        $this->assertNull(\Vylaris\Social\Jwk::rsaToPem(['kty' => 'RSA', 'n' => \Vylaris\Oidc\Jwt::b64u(str_repeat("\x01", 64)), 'e' => 'AQAB']), 'zu kurzer Schlüssel');
    }
}

function Jwt_b64(string $s): string
{
    return \Vylaris\Oidc\Jwt::b64u($s);
}
