<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\License\{LicenseClient, LicenseException, LicenseService};
use Vylaris\Service\Maintenance;
use Vylaris\Social\{HttpClient, HttpResponse};
use Vylaris\Tests\Support\{Factory, FakeIdp, FakeLicenseServer, TestBrowser, TestCase};

/** Lizenzsystem: Registrierung mit Schlüssel, Platzlimit, Scopes, Ausfall, Löschung. */
final class LicenseTest extends TestCase
{
    private object $ctx;
    private FakeLicenseServer $srv;
    private FakeIdp $idp;
    private const PW = 'Correct-Horse-Battery-9';
    private const URL = 'https://licence.test';

    protected function setUp(): void
    {
        $this->srv = new FakeLicenseServer();
        $this->srv->add('VA-AAAA-BBBB-CCCC', 2, ['social_login', 'passkeys']);
        $this->idp = new FakeIdp();
        $srv = $this->srv;
        $idp = $this->idp;
        $http = new class($srv, $idp) implements HttpClient {
            public function __construct(private FakeLicenseServer $srv, private FakeIdp $idp)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
            {
                return str_starts_with($url, 'https://licence.test') ? $this->srv->request($method, $url, $headers, $body) : $this->idp->request($method, $url, $headers, $body);
            }
        };
        $this->ctx = Factory::app(['license' => ['required' => true, 'grace_days' => 7]], $http, null, new LicenseClient($http, self::URL, $this->srv->publicKeyPem, 'vylarisauth'));
    }

    private function register(string $email, string $key, ?TestBrowser $b = null): array
    {
        return $this->ctx->app->auth->register($email, self::PW, 'Test Person', '', true, '2026-01-01', $key);
    }

    private function user(string $email): ?array
    {
        return $this->ctx->app->users->findByEmail($email);
    }

    /** Verifizierter Nutzer mit gebundenem Schlüssel. */
    private function licensed(string $email, string $key, string $role = 'user'): int
    {
        $a = $this->ctx->app;
        $id = $a->users->create($email, $a->passwords->hash(self::PW), 'Person', true);
        $a->users->assignRole($id, $role);
        $a->license->bind($id, $key);
        return $id;
    }

    private function login(string $email): TestBrowser
    {
        $b = new TestBrowser($this->ctx->app);
        $b->post('/login', ['email' => $email, 'password' => self::PW]);
        return $b;
    }

    // ------------------------------------------------------------------ Registrierung

    public function testRegistrationRequiresLicenseKey(): void
    {
        $r = $this->register('a@example.org', '');
        $this->assertFalse($r['ok']);
        $this->assertNull($this->user('a@example.org'));
    }

    public function testInvalidKeysCreateNoAccountAndNoSeat(): void
    {
        $this->srv->add('VA-BLOCKED-0001', 5);
        $this->srv->licenses['VA-BLOCKED-0001']['status'] = 'blocked';
        $this->srv->add('VA-EXPIRED-0001', 5, [], Clock::now() - 10);
        $this->srv->add('XX-OTHER-PRODUKT', 5, [], null, 'anderes-produkt');
        foreach (['VA-UNKNOWN-0001' => 'unbekannt', 'VA-BLOCKED-0001' => 'gesperrt', 'VA-EXPIRED-0001' => 'abgelaufen', 'XX-OTHER-PRODUKT' => 'anderen Produkt', 'kurz' => 'gültigen Lizenzschlüssel'] as $key => $word) {
            $r = $this->register('x' . md5($key) . '@example.org', $key);
            $this->assertFalse($r['ok'], $key);
            $this->assertStringContains($word, implode(' ', $r['errors']), $key);
        }
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM users'));
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM user_licenses'));
        foreach ($this->srv->licenses as $l) {
            $this->assertSame(0, count($l['devices']));
        }
    }

    public function testValidKeyCreatesAccountAndBindsPseudonymousDevice(): void
    {
        $r = $this->register('Anna@Example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertTrue($r['ok'], implode(',', $r['errors']));
        $u = $this->user('anna@example.org');
        $this->assertNotNull($u);
        $hwid = $this->ctx->app->license->hwid((int) $u['id']);
        $this->assertSame(['Nutzer ' . $u['id']], array_values($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertTrue(isset($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices'][$hwid]));
        $this->assertTrue(str_starts_with($hwid, 'vu-') && !str_contains($hwid, 'anna'));
        foreach ($this->srv->calls as $c) {
            $this->assertStringNotContains('anna', strtolower($c), 'keine E-Mail an den Lizenzserver');
        }
        $row = $this->ctx->db->one('SELECT * FROM user_licenses WHERE user_id = ?', [$u['id']]);
        $this->assertSame('OK', $row['code']);
    }

    public function testSeatLimitCapsNumberOfUsers(): void
    {
        $this->assertTrue($this->register('u1@example.org', 'VA-AAAA-BBBB-CCCC')['ok']);
        $this->assertTrue($this->register('u2@example.org', 'VA-AAAA-BBBB-CCCC')['ok']);
        $r = $this->register('u3@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('belegt', implode(' ', $r['errors']));
        $this->assertNull($this->user('u3@example.org'));
        $this->assertSame(2, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testDuplicateEmailDoesNotConsumeSeat(): void
    {
        $this->assertTrue($this->register('u1@example.org', 'VA-AAAA-BBBB-CCCC')['ok']);
        $this->assertTrue($this->register('u1@example.org', 'VA-AAAA-BBBB-CCCC')['ok']);
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testLicenseServerDownBlocksRegistrationOnly(): void
    {
        $id = $this->licensed('old@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->down = true;
        $r = $this->register('new@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('nicht erreichbar', implode(' ', $r['errors']));
        $this->assertNull($this->user('new@example.org'));
        // Bestehende Nutzer melden sich weiter an
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(302, $b->post('/login', ['email' => 'old@example.org', 'password' => self::PW])->status);
        $this->assertTrue($id > 0);
    }

    public function testFailedAccountCreationRollsBackAndReleasesSeat(): void
    {
        // Aktivierung erfolgreich, danach schlägt das Anlegen fehl -> Platz muss wieder frei werden.
        $a = $this->ctx->app;
        $a->db->run('DROP TABLE user_licenses');
        $this->assertThrows(\Throwable::class, fn () => $this->register('boom@example.org', 'VA-AAAA-BBBB-CCCC'));
        $this->assertNull($this->user('boom@example.org'));
        $this->assertSame(0, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testRegistrationFormAndWebFlow(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->assertStringContains('name="license_key"', $b->get('/register')->body);
        $r = $b->post('/register', ['name' => 'Web Person', 'email' => 'web@example.org', 'password' => self::PW, 'accept_privacy' => '1', 'license_key' => 'VA-AAAA-BBBB-CCCC']);
        $this->assertSame(200, $r->status);
        $this->assertNotNull($this->user('web@example.org'));
        $r = $b->post('/register', ['name' => 'Web Zwei', 'email' => 'web2@example.org', 'password' => self::PW, 'accept_privacy' => '1', 'license_key' => 'VA-NOPE-NOPE-NOPE']);
        $this->assertSame(422, $r->status);
        $this->assertStringContains('unbekannt', $r->body);
        $this->assertStringContains('VA-NOPE-NOPE-NOPE', $r->body, 'Eingabe bleibt erhalten');
    }

    // ------------------------------------------------------------------ Signaturprüfung

    public function testSignatureAndFreshnessAreEnforced(): void
    {
        $this->srv->badSignature = true;
        $r = $this->register('s@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('Signatur', implode(' ', $r['errors']));
        $this->srv->badSignature = false;
        $this->srv->skew = -3600;
        $r = $this->register('s@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('veraltet', implode(' ', $r['errors']));
        $this->assertNull($this->user('s@example.org'));
    }

    public function testResponseForOtherKeyIsRejected(): void
    {
        $this->srv->add('VA-OTHER-KEY-0001', 5, ['social_login']);
        $srv = $this->srv;
        $replay = new class($srv) implements HttpClient {
            public function __construct(private FakeLicenseServer $srv)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
            {
                return $this->srv->request($method, $url, $headers, json_encode(['key' => 'VA-AAAA-BBBB-CCCC', 'product' => 'vylarisauth']));
            }
        };
        $c = new LicenseClient($replay, self::URL, $this->srv->publicKeyPem, 'vylarisauth');
        $e = $this->assertThrows(LicenseException::class, fn () => $c->validate('VA-OTHER-KEY-0001'));
        $this->assertSame('BAD_SIGNATURE', $e->licenseCode);
    }

    public function testServerAndPublicKeyAreFixedAndNotConfigurable(): void
    {
        // Selbst mit Werten in der Config gelten Server und Schlüssel der Software.
        $ctx = Factory::app(['license' => ['required' => true, 'api_url' => 'https://evil.example', 'public_key' => $this->srv->publicKeyPem, 'product' => 'x']], $this->srv);
        $this->assertSame('https://licence.vylaris.ch', $ctx->app->license->client()->baseUrl());
        $this->assertSame('vylarisauth', $ctx->app->license->client()->product());
        $this->assertSame((new LicenseClient($this->srv, self::URL, LicenseService::DEFAULT_PUBLIC_KEY, 'x'))->fingerprint(), $ctx->app->license->client()->fingerprint());
        $this->assertTrue($ctx->app->license->client()->configured());
        // Der Testserver signiert mit einem anderen Schlüssel -> Antworten werden abgelehnt.
        $r = $ctx->app->auth->register('n@example.org', self::PW, 'Nora Nord', '', true, '1', 'VA-AAAA-BBBB-CCCC');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('Signatur', implode(' ', $r['errors']));
        $this->assertNull($ctx->app->users->findByEmail('n@example.org'));
    }

    public function testRawBase64PublicKeyIsAccepted(): void
    {
        $c = new LicenseClient($this->srv, self::URL, base64_encode($this->srv->publicRaw), 'vylarisauth');
        $this->assertTrue($c->validate('VA-AAAA-BBBB-CCCC')->valid);
    }

    // ------------------------------------------------------------------ Scopes

    public function testFeaturesComeFromLicense(): void
    {
        $this->srv->add('VA-MINI-0000-0001', 3, ['passkeys']);
        $id = $this->licensed('m@example.org', 'VA-MINI-0000-0001');
        $l = $this->ctx->app->license;
        $this->assertTrue($l->allows($id, 'passkeys'));
        $this->assertFalse($l->allows($id, 'social_login'));
        $this->assertFalse($l->allows($id, 'whitelabel'));
        $this->assertFalse($l->allows($id, 'erfunden'));
    }

    public function testKeylessLegacyUsersKeepAllFeaturesUnlessRestricted(): void
    {
        $a = $this->ctx->app;
        $id = $a->users->create('legacy@example.org', $a->passwords->hash(self::PW), 'Legacy', true);
        $this->assertTrue($a->license->state($id)['keyless']);
        $this->assertTrue($a->license->allows($id, 'whitelabel'));
        $ctx = Factory::app(['license' => ['required' => true, 'keyless_features' => ['passkeys']]], $this->srv);
        $id2 = $ctx->app->users->create('legacy@example.org', 'x', 'Legacy', true);
        $this->assertTrue($ctx->app->license->allows($id2, 'passkeys'));
        $this->assertFalse($ctx->app->license->allows($id2, 'whitelabel'));
    }

    public function testBlockedLicenseRemovesFeaturesButNotLogin(): void
    {
        $id = $this->licensed('b@example.org', 'VA-AAAA-BBBB-CCCC');
        $l = $this->ctx->app->license;
        $this->assertTrue($l->allows($id, 'passkeys'));
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['status'] = 'blocked';
        Clock::advance(4000);
        $this->assertSame(1, $l->refreshStale());
        $this->assertFalse($l->allows($id, 'passkeys'));
        $this->assertSame('BLOCKED', $l->state($id)['code']);
        $this->assertSame(302, (new TestBrowser($this->ctx->app))->post('/login', ['email' => 'b@example.org', 'password' => self::PW])->status);
    }

    public function testExpiryAndGracePeriod(): void
    {
        $this->srv->add('VA-SHORT-0000-0001', 2, ['passkeys'], Clock::now() + 86400 * 30);
        $id = $this->licensed('g@example.org', 'VA-SHORT-0000-0001');
        $l = $this->ctx->app->license;
        $this->srv->down = true;
        Clock::advance(86400 * 6);
        $this->assertTrue($l->allows($id, 'passkeys'), 'innerhalb der Karenzzeit gilt der letzte bestätigte Stand');
        $this->assertSame(0, $l->refreshStale(), 'Serverausfall: nichts aufgefrischt');
        Clock::advance(86400 * 2);
        $this->assertFalse($l->allows($id, 'passkeys'), 'Karenzzeit überschritten');
        $this->assertSame('UNVERIFIED', $l->state($id)['code']);
        $this->srv->down = false;
        $this->assertSame(1, $l->refreshStale());
        $this->assertTrue($l->allows($id, 'passkeys'), 'nach Wiederverbindung wieder gültig');
        Clock::advance(86400 * 25);
        $this->assertFalse($l->allows($id, 'passkeys'), 'Ablaufdatum der Lizenz erreicht');
        $this->assertSame('EXPIRED', $l->state($id)['code']);
    }

    public function testRevokedDeviceOnServerIsDetected(): void
    {
        $id = $this->licensed('r@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices'] = [];
        Clock::advance(4000);
        $this->ctx->app->license->refreshStale();
        $this->assertFalse($this->ctx->app->license->allows($id, 'passkeys'));
        $this->assertSame('NOT_ACTIVATED', $this->ctx->app->license->state($id)['code']);
    }

    // ------------------------------------------------------------------ Durchsetzung in den Controllern

    public function testAdminFunctionsAreGatedByAdminsOwnScopes(): void
    {
        $this->srv->add('VA-ADMIN-FULL-0001', 5, ['oidc_apps', 'whitelabel', 'audit_log', 'social_login']);
        $this->srv->add('VA-ADMIN-LOW-00001', 5, ['passkeys']);
        $this->licensed('full@example.org', 'VA-ADMIN-FULL-0001', 'superadmin');
        $this->licensed('low@example.org', 'VA-ADMIN-LOW-00001', 'superadmin');

        $low = $this->login('low@example.org');
        $this->assertSame(403, $low->post('/admin/apps', ['name' => 'App', 'redirect_uris' => 'https://app.example.org/cb', 'type' => 'confidential'])->status);
        $this->assertSame(403, $low->get('/admin/audit')->status);
        $this->assertSame(403, $low->post('/admin/branding', ['name' => 'Neu'])->status);
        $this->assertSame(403, $low->post('/admin/social', ['type' => 'github', 'provider_key' => 'github', 'label' => 'GH', 'client_id' => 'x', 'client_secret' => 'y'])->status);
        $this->assertSame(200, $low->get('/admin/users')->status, 'Benutzerverwaltung bleibt immer verfügbar');
        $this->assertSame(200, $low->get('/admin/license')->status);

        $full = $this->login('full@example.org');
        $this->assertSame(201, $full->post('/admin/apps', ['name' => 'App', 'redirect_uris' => 'https://app.example.org/cb', 'type' => 'confidential'])->status);
        $this->assertSame(200, $full->get('/admin/audit')->status);
    }

    public function testAppRevocationStaysPossibleWithoutScope(): void
    {
        $this->srv->add('VA-ADMIN-LOW-00001', 5, ['passkeys']);
        $this->licensed('low@example.org', 'VA-ADMIN-LOW-00001', 'superadmin');
        $res = $this->ctx->app->clients->create('Alt', ['https://app.example.org/cb'], [], true, 'openid', false, 'all', null);
        $pk = (int) $this->ctx->db->scalar('SELECT id FROM oauth_clients ORDER BY id DESC LIMIT 1');
        $b = $this->login('low@example.org');
        $this->assertSame(403, $b->post('/admin/apps/' . $pk . '/action', ['action' => 'settings', 'name' => 'X', 'scopes' => 'openid'])->status);
        $this->assertSame(302, $b->post('/admin/apps/' . $pk . '/action', ['action' => 'deactivate'])->status);
        $this->assertTrue($res !== null);
    }

    public function testPasskeyRegistrationNeedsScope(): void
    {
        $this->srv->add('VA-NOPK-0000-0001', 5, ['social_login']);
        $this->licensed('nopk@example.org', 'VA-NOPK-0000-0001');
        $b = $this->login('nopk@example.org');
        $this->assertSame(403, $b->postJson('/account/mfa/passkeys/options')->status);
        $this->licensed('pk@example.org', 'VA-AAAA-BBBB-CCCC');
        $b2 = $this->login('pk@example.org');
        $this->assertSame(200, $b2->postJson('/account/mfa/passkeys/options')->status);
    }

    // ------------------------------------------------------------------ Social Login, Admin, Bestandskonten

    public function testSocialLoginCannotCreateAccountsWhenLicenseRequired(): void
    {
        $a = $this->ctx->app;
        $admin = $a->users->create('sa@example.org', $a->passwords->hash(self::PW), 'SA', true);
        $a->users->assignRole($admin, 'superadmin');
        $a->social->saveProvider($admin, ['type' => 'google', 'provider_key' => 'google', 'label' => 'Google', 'client_id' => 'client-123', 'client_secret' => FakeIdp::SECRET, 'enabled' => true, 'allow_signup' => true]);
        $b = new TestBrowser($a);
        $start = $b->get('/social/google/login');
        $params = $this->idp->authorize($start->headers['Location'], ['sub' => 'g-1', 'email' => 'nina@example.org', 'email_verified' => true, 'name' => 'Nina']);
        $r = $b->get('/social/google/callback', $params);
        $this->assertNull($this->user('nina@example.org'));
        $this->assertStringContains('Lizenzschlüssel', $r->body);
    }

    public function testAdminCreatedUsersNeedKeyAndTakeSeat(): void
    {
        $a = $this->ctx->app;
        $admin = $a->users->create('ad@example.org', $a->passwords->hash(self::PW), 'Ad', true);
        $a->users->assignRole($admin, 'admin');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', ''));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', 'VA-UNKNOWN-0001'));
        $this->assertNull($this->user('n1@example.org'));
        $id = $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', 'VA-AAAA-BBBB-CCCC');
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertTrue($a->license->state($id)['valid']);
    }

    public function testLegacyUserCanAttachKeyViaAccountPage(): void
    {
        $a = $this->ctx->app;
        $a->users->create('legacy@example.org', $a->passwords->hash(self::PW), 'Legacy', true);
        $b = $this->login('legacy@example.org');
        $this->assertStringContains('Bestandsschutz', $b->get('/account/license')->body);
        $r = $b->post('/account/license', ['license_key' => 'VA-NOPE-NOPE-NOPE']);
        $this->assertSame(422, $r->status);
        $r = $b->post('/account/license', ['license_key' => 'VA-AAAA-BBBB-CCCC']);
        $this->assertSame(302, $r->status);
        $page = $b->get('/account/license')->body;
        $this->assertStringContains('VA-A…CC', $page);
        $this->assertStringNotContains('VA-AAAA-BBBB-CCCC', $page, 'Schlüssel nur maskiert anzeigen');
        $this->assertSame(409, $b->post('/account/license', ['license_key' => 'VA-AAAA-BBBB-CCCC'])->status);
    }

    // ------------------------------------------------------------------ Löschung, Cron, Protokolle

    public function testErasingAccountFreesSeat(): void
    {
        $id = $this->licensed('e@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->ctx->app->privacy->erase($id, null, 'user_request');
        $this->assertSame(0, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM user_licenses'));
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM license_releases'));
    }

    public function testFailedReleaseIsRetriedByCron(): void
    {
        $id = $this->licensed('e@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->down = true;
        $this->ctx->app->privacy->erase($id, null, 'user_request');
        $this->assertSame(1, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM license_releases'));
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->srv->down = false;
        $a = $this->ctx->app;
        $m = new Maintenance($a->db, $a->mailer, $a->sessions, $a->tokens, $a->limiter, $a->audit, $a->keys, 365, $a->privacy, 30, $a->license);
        $res = $m->run();
        $this->assertSame(1, $res['license_releases_done']);
        $this->assertSame(0, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM license_releases'));
    }

    public function testLicenseKeyNeverAppearsInFullInAuditLog(): void
    {
        $this->assertTrue($this->register('audit@example.org', 'VA-AAAA-BBBB-CCCC')['ok']);
        $this->register('audit2@example.org', 'VA-UNKNOWN-0001');
        $log = json_encode($this->ctx->db->all('SELECT * FROM audit_log'));
        $this->assertStringNotContains('VA-AAAA-BBBB-CCCC', $log);
        $this->assertStringContains('license.bound', $log);
    }

    public function testRequirementCanBeSwitchedOff(): void
    {
        $ctx = Factory::app(['license' => ['required' => false]], $this->srv);
        $r = $ctx->app->auth->register('free@example.org', self::PW, 'Frei Person', '', true, '1', '');
        $this->assertTrue($r['ok']);
        $id = (int) $ctx->app->users->findByEmail('free@example.org')['id'];
        $this->assertTrue($ctx->app->license->allows($id, 'whitelabel'));
        $this->assertSame([], $this->srv->calls);
    }

    public function testLoginPageStillWorksAndAdminOverviewShowsSeats(): void
    {
        $this->licensed('sa@example.org', 'VA-AAAA-BBBB-CCCC', 'superadmin');
        $b = $this->login('sa@example.org');
        $page = $b->get('/admin/license')->body;
        $this->assertStringContains('VA-A…CC', $page);
        $this->assertStringContains('1 / 2', $page);
        $this->assertStringContains('social_login', $page);
        $r = $b->post('/admin/license/refresh');
        $this->assertSame(302, $r->status);
    }
}
