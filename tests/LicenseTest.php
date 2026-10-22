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
        $this->assertStringNotContains('name="license_key"', $b->get('/register')->body, 'Registrierung fragt keinen Schlüssel mehr ab');
        // Ohne Pool ist keine Registrierung möglich
        $r = $b->post('/register', ['name' => 'Web Person', 'email' => 'web@example.org', 'password' => self::PW, 'accept_privacy' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContains('keine Lizenz hinterlegt', $r->body);
        $this->assertNull($this->user('web@example.org'));
        // Ein mitgeschickter Schlüssel wird ignoriert (nur der Pool zählt)
        $r = $b->post('/register', ['name' => 'Web Person', 'email' => 'web@example.org', 'password' => self::PW, 'accept_privacy' => '1', 'license_key' => 'VA-AAAA-BBBB-CCCC']);
        $this->assertSame(422, $r->status);
        $this->ctx->app->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $r = $b->post('/register', ['name' => 'Web Person', 'email' => 'web@example.org', 'password' => self::PW, 'accept_privacy' => '1']);
        $this->assertSame(200, $r->status);
        $this->assertNotNull($this->user('web@example.org'));
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
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['maxDevices'] = 0; // kein Platz mehr: Neu-Belegen scheitert
        Clock::advance(4000);
        $this->ctx->app->license->refreshStale();
        $this->assertFalse($this->ctx->app->license->allows($id, 'passkeys'));
        $this->assertSame('NOT_ACTIVATED', $this->ctx->app->license->state($id)['code']);
    }

    public function testResetSeatIsReclaimedAutomaticallyOnSameKey(): void
    {
        $id = $this->licensed('r2@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices'] = [];
        Clock::advance(4000);
        $this->ctx->app->license->refreshStale();
        $this->assertTrue($this->ctx->app->license->allows($id, 'passkeys'));
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
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
        $this->assertThrows(\InvalidArgumentException::class, fn () => $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', ''), 'ohne Pool kein Konto');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', 'VA-UNKNOWN-0001'));
        $this->assertNull($this->user('n1@example.org'));
        $id = $a->admin->createUser($admin, 'n1@example.org', 'Neu Eins', 'VA-AAAA-BBBB-CCCC');
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertTrue($a->license->state($id)['valid']);
    }

    public function testLegacyUserGetsLicenseFromPoolViaAccountPage(): void
    {
        $a = $this->ctx->app;
        $a->users->create('legacy@example.org', $a->passwords->hash(self::PW), 'Legacy', true);
        $b = $this->login('legacy@example.org');
        $page = $b->get('/account/license')->body;
        $this->assertStringContains('Bestandsschutz', $page);
        $this->assertStringNotContains('name="license_key"', $page);
        $this->assertStringContains('keine Lizenz im Pool', $page);
        $a->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $r = $b->post('/account/license', ['license_key' => 'VA-NOPE-NOPE-NOPE']); // eigene Eingabe wird ignoriert
        $this->assertSame(302, $r->status);
        $page = $b->get('/account/license')->body;
        $this->assertStringContains('VA-A…CC', $page);
        $this->assertStringNotContains('VA-AAAA-BBBB-CCCC', $page, 'Schlüssel nur maskiert anzeigen');
        $this->assertSame(409, $b->post('/account/license')->status);
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

    // ------------------------------------------------------------------ Schlüssel-Pool (einmal eingeben)

    private function keylessAdmin(string $email = 'pool-admin@example.org'): TestBrowser
    {
        $a = $this->ctx->app;
        $id = $a->users->create($email, $a->passwords->hash(self::PW), 'Pool Admin', true);
        $a->users->assignRole($id, 'superadmin');
        return $this->login($email);
    }

    public function testPooledKeyIsReusedAutomaticallyUntilSeatsAreUsedUp(): void
    {
        $this->srv->add('VA-DDDD-EEEE-FFFF', 3, ['social_login']);
        $b = $this->keylessAdmin();
        $this->assertSame(302, $b->post('/admin/license/keys', ['license_key' => 'VA-AAAA-BBBB-CCCC'])->status);
        $this->assertSame(1, count($this->ctx->app->license->pool()));
        // Zweimal ohne Schlüssel registrieren: belegt die beiden Plätze des hinterlegten Schlüssels.
        $this->assertTrue($this->register('p1@example.org', '')['ok']);
        $this->assertTrue($this->register('p2@example.org', '')['ok']);
        $this->assertSame(2, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertSame('VA-AAAA-BBBB-CCCC', $this->ctx->app->license->state((int) $this->user('p1@example.org')['id'])['key']);
        // Alle Plätze aufgebraucht: kein weiteres Konto.
        $r = $this->register('p3@example.org', '');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('belegt', implode(' ', $r['errors']));
        $this->assertNull($this->user('p3@example.org'));
        // Zweiten Schlüssel hinterlegen: ab jetzt wird automatisch der nächste mit freiem Platz genommen.
        $b->post('/admin/license/keys', ['license_key' => 'VA-DDDD-EEEE-FFFF']);
        $this->assertTrue($this->register('p3@example.org', '')['ok']);
        $this->assertSame(1, count($this->srv->licenses['VA-DDDD-EEEE-FFFF']['devices']));
        // Wird ein Platz frei, nutzt der nächste Nutzer wieder den ersten Schlüssel.
        $this->ctx->app->license->release((int) $this->user('p1@example.org')['id']);
        $this->assertTrue($this->register('p4@example.org', '')['ok']);
        $this->assertSame(2, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testExplicitKeyStillWinsOverPool(): void
    {
        $this->srv->add('VA-DDDD-EEEE-FFFF', 3, ['social_login']);
        $this->ctx->app->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $this->assertTrue($this->register('own@example.org', 'VA-DDDD-EEEE-FFFF')['ok']);
        $this->assertSame(1, count($this->srv->licenses['VA-DDDD-EEEE-FFFF']['devices']));
        $this->assertSame(0, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testAdminPicksLicenseFromPoolWhenCreatingUser(): void
    {
        $this->srv->add('VA-DDDD-EEEE-FFFF', 3, ['social_login']);
        $b = $this->keylessAdmin();
        $page = $b->get('/admin/users')->body;
        $this->assertStringContains('Kein Lizenzschlüssel im Pool', $page);
        $this->assertStringNotContains('name="license_key"', $page, 'kein Schlüsselfeld bei der Nutzererstellung');
        $b->post('/admin/license/keys', ['license_key' => 'VA-AAAA-BBBB-CCCC']);
        $b->post('/admin/license/keys', ['license_key' => 'VA-DDDD-EEEE-FFFF']);
        $pool = $this->ctx->app->license->pool();
        $page = $b->get('/admin/users')->body;
        $this->assertStringContains('name="license_pool_id"', $page);
        $this->assertStringContains('Automatisch', $page);
        $this->assertStringContains('2 von 2 Plätzen frei', $page);
        $this->assertStringNotContains('VA-DDDD-EEEE-FFFF', $page, 'nur maskiert');
        $this->assertStringContains('name="license_pool_id"', $b->get('/admin/users/new')->body);

        // gezielt den zweiten Schlüssel wählen
        $b->post('/admin/users', ['email' => 'sel@example.org', 'name' => 'Sel Ect', 'license_pool_id' => (string) $pool[1]['id']]);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $this->ctx->app->license->state((int) $this->user('sel@example.org')['id'])['key']);
        // automatisch = erster mit freiem Platz
        $b->post('/admin/users', ['email' => 'auto@example.org', 'name' => 'Au To', 'license_pool_id' => '']);
        $this->assertSame('VA-AAAA-BBBB-CCCC', $this->ctx->app->license->state((int) $this->user('auto@example.org')['id'])['key']);
        // unbekannte Pool-ID wird abgelehnt, der eingegebene Schlüssel ignoriert
        $b->post('/admin/users', ['email' => 'bad@example.org', 'name' => 'Ba Ad', 'license_pool_id' => '9999', 'license_key' => 'VA-AAAA-BBBB-CCCC']);
        $this->assertNull($this->user('bad@example.org'));
        // gewählter, aber voller Schlüssel: kein Konto, kein stilles Ausweichen
        $this->srv->licenses['VA-DDDD-EEEE-FFFF']['maxDevices'] = 1;
        $b->post('/admin/users', ['email' => 'full@example.org', 'name' => 'Fu Ll', 'license_pool_id' => (string) $pool[1]['id']]);
        $this->assertNull($this->user('full@example.org'));
    }

    public function testRegistrationNeverStoresOrAsksForKeys(): void
    {
        $this->ctx->app->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $anon = new TestBrowser($this->ctx->app);
        $this->assertStringNotContains('name="license_key"', $anon->get('/register')->body);
        $this->assertTrue($this->register('solo@example.org', '')['ok']);
        $this->assertSame(1, count($this->ctx->app->license->pool()));
    }

    // ------------------------------------------------------------------ Gegenprüfung & automatische Umstufung

    private function twoKeys(): void
    {
        $this->srv->add('VA-DDDD-EEEE-FFFF', 3, ['social_login', 'passkeys', 'whitelabel']);
        $this->ctx->app->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $this->ctx->app->license->addToPool('VA-DDDD-EEEE-FFFF', null, false);
    }

    public function testPoolKeysAreCheckedAgainstServerAndStatusIsStored(): void
    {
        $this->twoKeys();
        $this->assertSame(2, $this->ctx->app->license->refreshPool(true));
        $pool = $this->ctx->app->license->pool();
        $this->assertSame(1, (int) $pool[0]['valid']);
        $this->assertSame('Pro', $pool[0]['plan']);
        $this->assertSame(2, (int) $pool[0]['max_devices']);
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['status'] = 'blocked';
        $this->ctx->app->license->refreshPool(true);
        $pool = $this->ctx->app->license->pool();
        $this->assertSame(0, (int) $pool[0]['valid']);
        $this->assertSame('BLOCKED', $pool[0]['code']);
        $opts = $this->ctx->app->license->poolOptions();
        $this->assertFalse($opts[0]['usable']);
        $this->assertTrue($opts[1]['usable']);
        // Admin-Seite zeigt den Status, Cron-Lauf prüft den Pool ebenfalls
        $b = $this->keylessAdmin();
        $this->assertStringContains('gesperrt', $b->get('/admin/license')->body);
    }

    public function testUsersOnInvalidLicenseMoveToNextAvailableKey(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $u1 = $this->licensed('m1@example.org', 'VA-AAAA-BBBB-CCCC');
        $u2 = $this->licensed('m2@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['expires'] = Clock::now() + 100;
        Clock::advance(4000); // Lizenz ist abgelaufen
        $a->license->refreshStale();
        foreach ([$u1, $u2] as $id) {
            $s = $a->license->state($id);
            $this->assertSame('VA-DDDD-EEEE-FFFF', $s['key']);
            $this->assertTrue($s['valid']);
            $this->assertTrue($a->license->allows($id, 'whitelabel'), 'Funktionen der neuen Lizenz gelten');
        }
        $this->assertSame(2, count($this->srv->licenses['VA-DDDD-EEEE-FFFF']['devices']));
        $released = array_filter($this->srv->calls, static fn (string $c) => str_starts_with($c, 'deactivate:VA-AAAA-BBBB-CCCC'));
        $this->assertSame(2, count($released), 'alter Platz wird beim Lizenzserver freigegeben');
        $this->assertSame(2, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'license.reassigned'"));
        $this->assertSame(0, $a->license->invalidUsers());
    }

    public function testNoFreeKeyKeepsUserInvalidUntilNewKeyIsAdded(): void
    {
        $a = $this->ctx->app;
        $a->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $id = $this->licensed('w@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['status'] = 'blocked';
        Clock::advance(4000);
        $a->license->refreshStale();
        $this->assertFalse($a->license->state($id)['valid']);
        $this->assertSame(1, $a->license->invalidUsers());
        // neuer Schlüssel im Pool -> sofort umgestuft
        $this->srv->add('VA-DDDD-EEEE-FFFF', 3, ['passkeys']);
        $a->license->addToPool('VA-DDDD-EEEE-FFFF', null);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $a->license->state($id)['key']);
        $this->assertTrue($a->license->state($id)['valid']);
        $this->assertSame(0, $a->license->invalidUsers());
    }

    public function testFullKeysAreSkippedWhenReassigning(): void
    {
        $a = $this->ctx->app;
        $this->srv->add('VA-DDDD-EEEE-FFFF', 1, ['passkeys']);
        $this->srv->add('VA-GGGG-HHHH-IIII', 5, ['passkeys']);
        foreach (['VA-AAAA-BBBB-CCCC', 'VA-DDDD-EEEE-FFFF', 'VA-GGGG-HHHH-IIII'] as $k) {
            $a->license->addToPool($k, null, false);
        }
        $this->licensed('f0@example.org', 'VA-DDDD-EEEE-FFFF'); // belegt den einzigen Platz
        $id = $this->licensed('f1@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['status'] = 'blocked';
        Clock::advance(4000);
        $a->license->refreshStale();
        $this->assertSame('VA-GGGG-HHHH-IIII', $a->license->state($id)['key'], 'voller Schlüssel wird übersprungen');
    }

    public function testServerOutageDoesNotReassignOrInvalidate(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $this->licensed('o@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->down = true;
        Clock::advance(4000);
        $a->license->refreshStale();
        $this->assertSame('VA-AAAA-BBBB-CCCC', $a->license->state($id)['key']);
        $this->assertTrue($a->license->state($id)['valid'], 'innerhalb der Karenzzeit bleibt der letzte Stand');
    }

    public function testLoginRechecksStaleLicenseWithoutCron(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $this->licensed('l@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['status'] = 'blocked';
        Clock::advance(4000);
        $this->login('l@example.org'); // kein Cron-Lauf, nur die Anmeldung
        $this->assertSame('VA-DDDD-EEEE-FFFF', $a->license->state($id)['key']);
    }

    public function testLoginDuringServerOutageIsNotBlockedRepeatedly(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $this->licensed('d@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->srv->down = true;
        Clock::advance(4000);
        $this->login('d@example.org');
        $calls = count($this->srv->calls);
        $this->login('d@example.org');
        $this->assertSame($calls, count($this->srv->calls), 'nach einem Ausfall wird kurz nicht erneut angefragt');
    }

    public function testBindFallsBackToNextPoolKeyWhenSeatIsTakenInBetween(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $a->users->create('race@example.org', $a->passwords->hash(self::PW), 'Race', true);
        $this->srv->licenses['VA-AAAA-BBBB-CCCC']['maxDevices'] = 0; // zwischen Vorabprüfung und Belegung voll geworden
        $r = $a->license->bind($id, 'VA-AAAA-BBBB-CCCC', true);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $r->key);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $a->license->state($id)['key']);
        $id2 = $a->users->create('race2@example.org', $a->passwords->hash(self::PW), 'Race2', true);
        $this->assertThrows(LicenseException::class, fn () => $a->license->bind($id2, 'VA-AAAA-BBBB-CCCC', false), 'ohne Fallback (gezielte Wahl) kein stilles Ausweichen');
    }

    public function testPoolManagementIsAdminOnlyValidatedAndRemovable(): void
    {
        $b = $this->keylessAdmin();
        $b->post('/admin/license/keys', ['license_key' => 'VA-UNKNOWN-0001']);
        $this->assertSame([], $this->ctx->app->license->pool(), 'unbekannter Schlüssel wird nicht hinterlegt');
        $b->post('/admin/license/keys', ['license_key' => 'VA-AAAA-BBBB-CCCC']);
        $page = $b->get('/admin/license')->body;
        $this->assertStringContains('Lizenzpool', $page);
        $this->assertStringContains('VA-A…CC', $page);
        $this->assertStringNotContains('VA-AAAA-BBBB-CCCC', $page, 'Schlüssel nur maskiert anzeigen');
        // normale Nutzer und Anonyme dürfen nichts
        $this->licensed('plain@example.org', 'VA-AAAA-BBBB-CCCC');
        $u = $this->login('plain@example.org');
        $this->assertSame(403, $u->post('/admin/license/keys', ['license_key' => 'VA-AAAA-BBBB-CCCC'])->status);
        $id = (int) $this->ctx->app->license->pool()[0]['id'];
        $this->assertSame(403, $u->post('/admin/license/keys/' . $id . '/remove')->status);
        $this->assertSame(302, (new TestBrowser($this->ctx->app))->post('/admin/license/keys/' . $id . '/remove')->status);
        $this->assertSame(1, count($this->ctx->app->license->pool()));
        $b->post('/admin/license/keys/' . $id . '/remove');
        $this->assertSame([], $this->ctx->app->license->pool());
        // Bestehende Konten behalten ihren Platz.
        $this->assertTrue($this->ctx->app->license->state((int) $this->user('plain@example.org')['id'])['valid']);
    }

    public function testLegacyUserCanAttachPooledKeyWithoutTyping(): void
    {
        $a = $this->ctx->app;
        $id = $a->users->create('legacy2@example.org', $a->passwords->hash(self::PW), 'Alt', true);
        $a->users->assignRole($id, 'user');
        $a->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $b = $this->login('legacy2@example.org');
        $this->assertSame(302, $b->post('/account/license', ['license_key' => ''])->status);
        $this->assertFalse($a->license->state($id)['keyless']);
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
    }

    public function testServerOutageDoesNotSkipToNextPooledKey(): void
    {
        $this->ctx->app->license->addToPool('VA-AAAA-BBBB-CCCC', null, false);
        $this->ctx->app->license->addToPool('VA-DDDD-EEEE-FFFF', null, false);
        $this->srv->down = true;
        $r = $this->register('out@example.org', '');
        $this->assertFalse($r['ok']);
        $this->assertStringContains('nicht erreichbar', implode(' ', $r['errors']));
        $this->assertNull($this->user('out@example.org'));
    }

    // ------------------------------------------------------------------ Lizenzwechsel auf der Nutzerseite

    private function poolId(string $key): int
    {
        foreach ($this->ctx->app->license->pool() as $row) {
            if ($row['license_key'] === $key) {
                return (int) $row['id'];
            }
        }
        return 0;
    }

    public function testAdminMovesUserToOtherPoolLicense(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $this->licensed('mv@example.org', 'VA-AAAA-BBBB-CCCC');
        $b = $this->keylessAdmin();
        $page = $b->get('/admin/users/' . $id)->body;
        $this->assertStringContains('name="license_pool_id"', $page);
        $this->assertStringContains('(aktuell)', $page);
        $this->assertStringContains('Lizenz wechseln', $page);
        $this->assertStringNotContains('VA-DDDD-EEEE-FFFF', $page, 'nur maskiert');
        $this->assertStringNotContains('Automatisch', $page, 'beim Wechsel gibt es keine Automatik');

        $this->srv->calls = [];
        $r = $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-DDDD-EEEE-FFFF')]);
        $this->assertSame(302, $r->status);
        $s = $a->license->state($id);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $s['key']);
        $this->assertTrue($s['valid']);
        $this->assertTrue($a->license->allows($id, 'whitelabel'), 'Funktionen der neuen Lizenz gelten');
        $this->assertSame(1, count($this->srv->licenses['VA-DDDD-EEEE-FFFF']['devices']));
        $this->assertSame(0, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']), 'alter Platz freigegeben');
        $this->assertSame(1, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'license.moved'"));
    }

    public function testMoveFailureLeavesEverythingUnchanged(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $this->licensed('keep0@example.org', 'VA-DDDD-EEEE-FFFF');
        $id = $this->licensed('keep@example.org', 'VA-AAAA-BBBB-CCCC');
        $b = $this->keylessAdmin();
        $this->srv->licenses['VA-DDDD-EEEE-FFFF']['maxDevices'] = 1; // voll
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-DDDD-EEEE-FFFF')]);
        $this->assertSame('VA-AAAA-BBBB-CCCC', $a->license->state($id)['key']);
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']), 'alter Platz bleibt belegt');
        $this->srv->licenses['VA-DDDD-EEEE-FFFF']['maxDevices'] = 3;
        $this->srv->licenses['VA-DDDD-EEEE-FFFF']['status'] = 'blocked';
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-DDDD-EEEE-FFFF')]);
        $this->assertSame('VA-AAAA-BBBB-CCCC', $a->license->state($id)['key']);
        $this->assertSame(0, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'license.moved'"));
    }

    public function testMoveRejectsSameKeyUnknownPoolIdAndMissingChoice(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $this->licensed('rej@example.org', 'VA-AAAA-BBBB-CCCC');
        $b = $this->keylessAdmin();
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-AAAA-BBBB-CCCC')]);
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => '9999']);
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => '']);
        $this->assertSame('VA-AAAA-BBBB-CCCC', $a->license->state($id)['key']);
        $this->assertSame(1, count($this->srv->licenses['VA-AAAA-BBBB-CCCC']['devices']));
        $this->assertThrows(LicenseException::class, fn () => $a->license->moveUser($id, 'VA-NOT-IN-POOL'), 'Schlüssel außerhalb des Pools');
        $this->assertThrows(LicenseException::class, fn () => $a->license->moveUser($id, 'VA-AAAA-BBBB-CCCC'));
    }

    public function testMoveGivesKeylessUserALicense(): void
    {
        $a = $this->ctx->app;
        $this->twoKeys();
        $id = $a->users->create('nokey@example.org', $a->passwords->hash(self::PW), 'No Key', true);
        $a->users->assignRole($id, 'user');
        $b = $this->keylessAdmin();
        $this->assertStringContains('Lizenz zuweisen', $b->get('/admin/users/' . $id)->body);
        $b->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-DDDD-EEEE-FFFF')]);
        $this->assertFalse($a->license->state($id)['keyless']);
        $this->assertSame('VA-DDDD-EEEE-FFFF', $a->license->state($id)['key']);
        $this->assertSame(1, count($this->srv->licenses['VA-DDDD-EEEE-FFFF']['devices']));
    }

    public function testMoveIsAdminOnly(): void
    {
        $this->twoKeys();
        $id = $this->licensed('victim@example.org', 'VA-AAAA-BBBB-CCCC');
        $this->licensed('plain2@example.org', 'VA-AAAA-BBBB-CCCC');
        $u = $this->login('plain2@example.org');
        $this->assertSame(403, $u->post('/admin/users/' . $id . '/license', ['license_pool_id' => (string) $this->poolId('VA-DDDD-EEEE-FFFF')])->status);
        $this->assertSame('VA-AAAA-BBBB-CCCC', $this->ctx->app->license->state($id)['key']);
    }

    public function testBoxiconsComeFromCdnWithSriAndCspAllowsIt(): void
    {
        $anon = new TestBrowser($this->ctx->app);
        $r = $anon->get('/login');
        $this->assertStringContains('https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css', $r->body);
        $this->assertStringContains('integrity="sha384-', $r->body);
        $this->assertStringContains("font-src 'self' https://cdn.jsdelivr.net", $r->headers['Content-Security-Policy']);
        $this->assertStringNotContains("script-src 'self' https", $r->headers['Content-Security-Policy']);
    }
}
