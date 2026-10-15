<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Service\{Maintenance, PrivacyException, TokenService};
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** DSGVO: Auskunft/Export, Löschung, Berichtigung, Speicherbegrenzung, Datenschutzerklärung. */
final class PrivacyTest extends TestCase
{
    private object $ctx;
    private int $super;
    private int $admin;
    private int $anna;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $this->ctx = Factory::app(['privacy' => ['inactive_account_days' => 0]]);
        $a = $this->ctx->app;
        $mk = fn (string $mail, string $name) => $a->users->create($mail, $a->passwords->hash(self::PW), $name, true);
        $this->super = $mk('super@example.org', 'Super');
        $a->users->assignRole($this->super, 'superadmin');
        $this->admin = $mk('admin@example.org', 'Admin');
        $a->users->assignRole($this->admin, 'admin');
        $this->anna = $mk('anna@example.org', 'Anna Muster');
        $a->users->assignRole($this->anna, 'user');
    }

    private function login(string $email): TestBrowser
    {
        $b = new TestBrowser($this->ctx->app);
        $b->post('/login', ['email' => $email, 'password' => self::PW]);
        return $b;
    }

    /** Alle Tabelleninhalte als Text – zum Nachweis, dass eine Adresse nirgends mehr vorkommt. */
    private function dump(): string
    {
        $out = '';
        $tables = $this->ctx->db->driver() === 'sqlite'
            ? array_column($this->ctx->db->all("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name')
            : array_map('current', $this->ctx->db->all('SHOW TABLES'));
        foreach ($tables as $t) {
            foreach ($this->ctx->db->all('SELECT * FROM ' . $t) as $row) {
                $out .= json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        return $out;
    }

    /** Anna bekommt Daten in möglichst vielen Tabellen. */
    private function populate(): TestBrowser
    {
        $a = $this->ctx->app;
        $b = $this->login('anna@example.org');
        $c = $a->clients->create('Wiki', ['https://wiki.example.org/cb']);
        $client = $a->clients->findByPk((int) $c['id']);
        $a->clients->grantConsent($this->anna, $client, ['openid', 'email']);
        $role = $a->clients->createRole((int) $c['id'], 'editor');
        $a->clients->assignRole($this->anna, (int) $c['id'], $role);
        $this->ctx->db->insert('social_identities', ['user_id' => $this->anna, 'provider_key' => 'google', 'subject' => 'g-1', 'email' => 'anna@example.org', 'display_name' => 'Anna', 'created_at' => Clock::db()]);
        $setup = $a->mfa->beginTotpSetup($this->anna, 'anna@example.org');
        $this->ctx->db->insert('webauthn_credentials', ['user_id' => $this->anna, 'credential_id' => 'cred-1', 'credential_source' => '{}', 'name' => 'Key', 'created_at' => Clock::db()]);
        $a->mailer->queue('anna@example.org', 'Test', 'Hallo');
        $a->audit->log('test.event', $this->anna, ['email' => 'anna@example.org', 'note' => 'x']);
        $a->audit->log('auth.legacy', null, ['email' => 'anna@example.org']); // Altbestand mit Klartext-Adresse
        return $b;
    }

    // ------------------------------------------------------------------ Export

    public function testExportContainsDataButNoSecrets(): void
    {
        $this->populate();
        $json = $this->ctx->app->privacy->exportJson($this->anna);
        $d = json_decode($json, true);
        $this->assertSame('anna@example.org', $d['profile']['email']);
        $this->assertSame('Wiki', $d['app_authorizations'][0]['application']);
        $this->assertSame('editor', $d['app_roles'][0]['role']);
        $this->assertSame('google', $d['social_logins'][0]['provider']);
        $this->assertSame('Key', $d['second_factor']['passkeys'][0]['name']);
        $this->assertTrue(count($d['sessions']) >= 1);
        $this->assertSame('vylarisauth-export-1', $d['meta']['format']);
        $user = $this->ctx->app->users->findById($this->anna);
        $this->assertStringNotContains($user['password_hash'], $json);
        foreach (['password_hash', 'secret_enc', 'code_hash', 'credential_source', 'token_hash'] as $k) {
            $this->assertStringNotContains($k, $json, $k);
        }
    }

    public function testExportContainsOnlyOwnData(): void
    {
        $this->populate();
        $this->ctx->app->audit->log('x', $this->admin, ['n' => 'admin-only-marker']);
        $json = $this->ctx->app->privacy->exportJson($this->anna);
        $this->assertStringNotContains('admin@example.org', $json);
        $this->assertStringNotContains('admin-only-marker', $json);
    }

    public function testSelfServiceExportNeedsPasswordAndCsrf(): void
    {
        $b = $this->login('anna@example.org');
        $this->assertSame(422, $b->post('/account/privacy/export', ['password' => 'falsch'])->status);
        $this->assertSame(403, $b->post('/account/privacy/export', ['password' => self::PW], false)->status);
        $r = $b->post('/account/privacy/export', ['password' => self::PW]);
        $this->assertSame(200, $r->status);
        $this->assertStringContains('attachment', $r->headers['Content-Disposition']);
        $this->assertStringContains('no-store', $r->headers['Cache-Control']);
        $this->assertSame('anna@example.org', json_decode($r->body, true)['profile']['email']);
    }

    public function testExportRequiresLogin(): void
    {
        $r = (new TestBrowser($this->ctx->app))->get('/account/privacy');
        $this->assertSame(302, $r->status);
        $this->assertStringContains('/login', $r->headers['Location']);
    }

    // ------------------------------------------------------------------ Löschung

    public function testEraseRemovesAllPersonalData(): void
    {
        $this->populate();
        $this->assertStringContains('anna@example.org', $this->dump());
        $this->ctx->app->privacy->erase($this->anna, $this->admin, 'admin');
        $dump = $this->dump();
        $this->assertStringNotContains('anna@example.org', $dump);
        $this->assertStringNotContains('Anna Muster', $dump);
        $this->assertNull($this->ctx->app->users->findById($this->anna));
        foreach (['sessions', 'user_roles', 'user_app_roles', 'oauth_consents', 'social_identities', 'mfa_totp', 'webauthn_credentials', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $t) {
            $this->assertSame(0, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM $t WHERE user_id = ?", [$this->anna]), $t);
        }
        $this->assertSame(0, (int) $this->ctx->db->scalar('SELECT COUNT(*) FROM mail_queue WHERE to_email = ?', ['anna@example.org']));
    }

    public function testEraseKeepsAnonymizedAuditTrail(): void
    {
        $this->populate();
        $this->ctx->app->privacy->erase($this->anna, $this->admin, 'admin');
        $this->assertTrue((int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'test.event'") === 1, 'Ereignis bleibt');
        $row = $this->ctx->db->one("SELECT user_id, ip, user_agent, details FROM audit_log WHERE event = 'test.event'");
        $this->assertNull($row['user_id']);
        $this->assertNull($row['ip']);
        $this->assertNull($row['details']);
        $e = $this->ctx->db->one("SELECT actor_id, details FROM audit_log WHERE event = 'privacy.account_erased'");
        $this->assertSame($this->admin, (int) $e['actor_id']);
        $this->assertStringContains('admin', $e['details']);
        $this->assertStringNotContains('anna', $e['details']);
    }

    public function testLastSuperadminCannotBeErased(): void
    {
        $this->assertTrue($this->ctx->app->privacy->erasureBlocker($this->super) !== null);
        $this->assertThrows(PrivacyException::class, fn () => $this->ctx->app->privacy->erase($this->super, null, 'user_request'));
        $this->assertNotNull($this->ctx->app->users->findById($this->super));
        $second = $this->ctx->app->users->create('s2@example.org', 'x', 'S2', true);
        $this->ctx->app->users->assignRole($second, 'superadmin');
        $this->assertNull($this->ctx->app->privacy->erasureBlocker($this->super));
    }

    public function testSelfServiceDeletionFlow(): void
    {
        $b = $this->populate();
        $mails = fn () => $this->ctx->db->all("SELECT body_text FROM mail_queue WHERE subject LIKE '%löschen%'");
        $this->assertSame(422, $b->post('/account/privacy/delete', ['password' => 'falsch'])->status);
        $this->assertSame(0, count($mails()));
        $this->assertSame(302, $b->post('/account/privacy/delete', ['password' => self::PW])->status);
        $body = $mails()[0]['body_text'];
        preg_match('#confirm\?token=([\w-]+)#', $body, $m);
        $token = $m[1];
        $this->assertNotNull($this->ctx->app->users->findById($this->anna), 'noch nicht gelöscht');

        // fremdes Konto kann den Link nicht nutzen
        $other = $this->login('admin@example.org');
        $this->assertSame(400, $other->get('/account/privacy/delete/confirm', ['token' => $token])->status);
        $this->assertSame(400, $other->post('/account/privacy/delete/confirm', ['token' => $token, 'confirm' => '1'])->status);
        // ohne Login
        $this->assertSame(302, (new TestBrowser($this->ctx->app))->get('/account/privacy/delete/confirm', ['token' => $token])->status);
        // Bestätigungshaken nötig, CSRF nötig
        $this->assertSame(200, $b->get('/account/privacy/delete/confirm', ['token' => $token])->status);
        $this->assertSame(422, $b->post('/account/privacy/delete/confirm', ['token' => $token])->status);
        $this->assertSame(403, $b->post('/account/privacy/delete/confirm', ['token' => $token, 'confirm' => '1'], false)->status);
        $this->assertNotNull($this->ctx->app->users->findById($this->anna));

        $this->assertSame(200, $b->post('/account/privacy/delete/confirm', ['token' => $token, 'confirm' => '1'])->status);
        $this->assertNull($this->ctx->app->users->findById($this->anna));
        $this->assertStringNotContains('anna@example.org', $this->dump());
        // Token ist einmalig; Anmeldung nicht mehr möglich
        $this->assertNull($this->ctx->app->tokens->peek($token, TokenService::DELETE_ACCOUNT));
        $again = new TestBrowser($this->ctx->app);
        $again->post('/login', ['email' => 'anna@example.org', 'password' => self::PW]);
        $this->assertSame(302, $again->get('/account')->status);
    }

    public function testSocialOnlyAccountDeletesWithoutPassword(): void
    {
        $this->ctx->db->update('users', ['password_set' => 0], 'id', $this->anna);
        $b = $this->login('anna@example.org');
        $this->assertSame(302, $b->post('/account/privacy/delete')->status);
        $this->assertSame(1, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM mail_queue WHERE subject LIKE '%löschen%'"));
    }

    public function testDeletionBlockedForLastSuperadminInUi(): void
    {
        $b = $this->login('super@example.org');
        $this->assertStringContains('letzte Superadmin', $b->get('/account/privacy')->body);
        $this->assertSame(422, $b->post('/account/privacy/delete', ['password' => self::PW])->status);
    }

    // ------------------------------------------------------------------ Admin

    public function testAdminCanExportAndEraseUsersWithConfirmation(): void
    {
        $b = $this->login('admin@example.org');
        $r = $b->post('/admin/users/' . $this->anna . '/export');
        $this->assertSame(200, $r->status);
        $this->assertSame('anna@example.org', json_decode($r->body, true)['profile']['email']);
        $this->assertSame(1, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'privacy.export_by_admin'"));

        $b->post('/admin/users/' . $this->anna . '/erase', ['confirm_email' => 'falsch@example.org']);
        $this->assertNotNull($this->ctx->app->users->findById($this->anna));
        $this->assertSame(403, $b->post('/admin/users/' . $this->anna . '/erase', ['confirm_email' => 'anna@example.org'], false)->status);
        $this->assertSame(302, $b->post('/admin/users/' . $this->anna . '/erase', ['confirm_email' => 'Anna@Example.org'])->status);
        $this->assertNull($this->ctx->app->users->findById($this->anna));
    }

    public function testAdminCannotTouchAdminsOrSelfAndUsersCannotUseAdminRoutes(): void
    {
        $adminB = $this->login('admin@example.org');
        $this->assertSame(403, $adminB->post('/admin/users/' . $this->super . '/erase', ['confirm_email' => 'super@example.org'])->status);
        $this->assertSame(403, $adminB->post('/admin/users/' . $this->super . '/export')->status);
        $this->assertSame(403, $adminB->post('/admin/users/' . $this->admin . '/erase', ['confirm_email' => 'admin@example.org'])->status);
        $this->assertNotNull($this->ctx->app->users->findById($this->super));
        $annaB = $this->login('anna@example.org');
        $this->assertSame(403, $annaB->post('/admin/users/' . $this->admin . '/export')->status);
        $this->assertSame(403, $annaB->post('/admin/users/' . $this->admin . '/erase', ['confirm_email' => 'admin@example.org'])->status);
        $this->assertSame(404, $this->login('super@example.org')->post('/admin/users/9999/export')->status);
    }

    // ------------------------------------------------------------------ Berichtigung E-Mail

    public function testEmailChangeFlow(): void
    {
        $b = $this->login('anna@example.org');
        $this->assertSame(422, $b->post('/account/email', ['new_email' => 'neu@example.org', 'password' => 'falsch'])->status);
        $this->assertSame(422, $b->post('/account/email', ['new_email' => 'kaputt', 'password' => self::PW])->status);
        $this->assertSame(302, $b->post('/account/email', ['new_email' => 'Neu@Example.org', 'password' => self::PW])->status);
        $mail = $this->ctx->db->one("SELECT to_email, body_text FROM mail_queue WHERE subject LIKE 'Neue E-Mail%'");
        $this->assertSame('neu@example.org', $mail['to_email']);
        preg_match('#confirm\?token=([\w-]+)#', $mail['body_text'], $m);
        $this->assertSame('anna@example.org', $this->ctx->app->users->findById($this->anna)['email'], 'erst nach Bestätigung');
        $this->assertSame(400, $this->login('admin@example.org')->get('/account/email/confirm', ['token' => $m[1]])->status);
        $this->assertStringContains('neu@example.org', $b->get('/account/email/confirm', ['token' => $m[1]])->body);
        $this->assertSame(302, $b->post('/account/email/confirm', ['token' => $m[1]])->status);
        $u = $this->ctx->app->users->findById($this->anna);
        $this->assertSame('neu@example.org', $u['email']);
        $this->assertNotNull($u['email_verified_at']);
        $this->assertSame(1, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'anna@example.org' AND subject LIKE '%geändert%'"));
        $this->assertSame(400, $b->post('/account/email/confirm', ['token' => $m[1]])->status, 'einmalig');
    }

    public function testEmailChangeToExistingAddressRevealsNothing(): void
    {
        $b = $this->login('anna@example.org');
        $r1 = $b->post('/account/email', ['new_email' => 'admin@example.org', 'password' => self::PW]);
        $flash1 = $b->get('/account/privacy')->body;
        $this->assertSame(302, $r1->status);
        $this->assertSame(0, (int) $this->ctx->db->scalar("SELECT COUNT(*) FROM user_tokens WHERE type = 'change_email'"));
        $this->assertStringContains('Falls die Adresse verwendet werden kann', $flash1);
        $this->assertSame('anna@example.org', $this->ctx->app->users->findById($this->anna)['email']);
    }

    // ------------------------------------------------------------------ Registrierung / Nachweis

    public function testRegistrationRequiresPrivacyAcknowledgement(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $form = ['email' => 'neu@example.org', 'password' => 'Sehr-Langes-Passwort-42', 'name' => 'Neu Nutzer'];
        $this->assertSame(422, $b->post('/register', $form)->status);
        $this->assertNull($this->ctx->app->users->findByEmail('neu@example.org'));
        $this->assertStringContains('accept_privacy', $b->get('/register')->body);
        $this->assertSame(200, $b->post('/register', $form + ['accept_privacy' => '1'])->status);
        $u = $this->ctx->app->users->findByEmail('neu@example.org');
        $this->assertNotNull($u['privacy_accepted_at']);
        $this->assertSame($this->ctx->app->brand->get('privacy_version'), $u['privacy_version']);
    }

    public function testAcknowledgementCanBeDisabled(): void
    {
        $ctx = Factory::app(['security' => ['allow_registration' => true], 'privacy' => ['require_acceptance' => false]]);
        $r = (new TestBrowser($ctx->app))->post('/register', ['email' => 'n@example.org', 'password' => 'Sehr-Langes-Passwort-42', 'name' => 'Neu Nutzer']);
        $this->assertSame(200, $r->status);
    }

    // ------------------------------------------------------------------ Protokolle / Speicherbegrenzung

    public function testFailedLoginLogHasNoPlaintextEmail(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $b->post('/login', ['email' => 'jemand-fremdes@example.org', 'password' => 'falsch-falsch-1']);
        $b->post('/login', ['email' => 'anna@example.org', 'password' => 'falsch-falsch-1']);
        $this->assertStringNotContains('jemand-fremdes@example.org', $this->dump());
        $this->assertTrue((int) $this->ctx->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'auth.login_failed'") >= 1);
        $this->assertStringContains('email_ref', (string) $this->ctx->db->scalar("SELECT details FROM audit_log WHERE event = 'auth.login_failed' ORDER BY id DESC LIMIT 1"));
    }

    public function testIpAddressesInAuditLogAreRemovedAfterRetention(): void
    {
        $db = $this->ctx->db;
        $old = Clock::db(Clock::now() - 40 * 86400);
        $db->insert('audit_log', ['created_at' => $old, 'event' => 'old.event', 'ip' => '203.0.113.5', 'user_agent' => 'UA']);
        $db->insert('audit_log', ['created_at' => Clock::db(), 'event' => 'new.event', 'ip' => '203.0.113.6', 'user_agent' => 'UA']);
        $n = $this->ctx->app->privacy->anonymizeAuditData(30);
        $this->assertSame(1, $n);
        $this->assertNull($db->one("SELECT ip FROM audit_log WHERE event = 'old.event'")['ip']);
        $this->assertSame('203.0.113.6', $db->one("SELECT ip FROM audit_log WHERE event = 'new.event'")['ip']);
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'old.event'"), 'Ereignis bleibt erhalten');
    }

    public function testMaintenanceRunsPrivacyJobs(): void
    {
        $a = $this->ctx->app;
        $this->ctx->db->insert('audit_log', ['created_at' => Clock::db(Clock::now() - 60 * 86400), 'event' => 'old', 'ip' => '203.0.113.5']);
        $m = new Maintenance($a->db, $a->mailer, $a->sessions, $a->tokens, $a->limiter, $a->audit, $a->keys, 365, $a->privacy, 30);
        $res = $m->run();
        $this->assertSame(1, $res['audit_ips_removed']);
        $this->assertSame(0, $res['inactive_erased']);
    }

    // ------------------------------------------------------------------ Inaktive Konten

    private function inactiveCtx(int $days = 365): object
    {
        $this->ctx = Factory::app(['privacy' => ['inactive_account_days' => $days, 'inactive_warning_days' => 30]]);
        $this->setUp2();
        return $this->ctx;
    }

    private function setUp2(): void
    {
        $a = $this->ctx->app;
        $this->super = $a->users->create('super@example.org', $a->passwords->hash(self::PW), 'Super', true);
        $a->users->assignRole($this->super, 'superadmin');
        $this->anna = $a->users->create('anna@example.org', $a->passwords->hash(self::PW), 'Anna', true);
        $a->users->assignRole($this->anna, 'user');
    }

    public function testInactiveAccountsAreWarnedThenErased(): void
    {
        $ctx = $this->inactiveCtx();
        $a = $ctx->app;
        $ctx->db->update('users', ['last_login_at' => Clock::db(Clock::now() - 400 * 86400)], 'id', $this->anna);
        $ctx->db->update('users', ['last_login_at' => Clock::db(Clock::now() - 900 * 86400)], 'id', $this->super);
        $r = $a->privacy->processInactiveAccounts();
        $this->assertSame(['warned' => 1, 'erased' => 0], $r, 'Admin wird nie automatisch behandelt');
        $this->assertSame(1, (int) $ctx->db->scalar("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'anna@example.org' AND subject LIKE '%gelöscht%'"));
        $this->assertSame(['warned' => 0, 'erased' => 0], $a->privacy->processInactiveAccounts(), 'keine zweite Warnung');
        Clock::advance(31 * 86400);
        $this->assertSame(['warned' => 0, 'erased' => 1], $a->privacy->processInactiveAccounts());
        $this->assertNull($a->users->findByEmail('anna@example.org'));
        $this->assertNotNull($a->users->findByEmail('super@example.org'));
    }

    public function testLoginAfterWarningPreventsErasure(): void
    {
        $ctx = $this->inactiveCtx();
        $a = $ctx->app;
        $ctx->db->update('users', ['last_login_at' => Clock::db(Clock::now() - 400 * 86400)], 'id', $this->anna);
        $a->privacy->processInactiveAccounts();
        Clock::advance(10 * 86400);
        $a->users->recordLoginSuccess($this->anna); // Benutzer meldet sich an
        Clock::advance(40 * 86400);
        $this->assertSame(['warned' => 0, 'erased' => 0], $a->privacy->processInactiveAccounts());
        $this->assertNotNull($a->users->findByEmail('anna@example.org'));
    }

    public function testInactivityDisabledOrUnsafeValuesDoNothing(): void
    {
        $this->ctx->db->update('users', ['last_login_at' => Clock::db(Clock::now() - 900 * 86400)], 'id', $this->anna);
        $this->assertSame(['warned' => 0, 'erased' => 0], $this->ctx->app->privacy->processInactiveAccounts(), 'Standard: aus');
        $ctx = $this->inactiveCtx(30); // zu kurz -> ignoriert
        $ctx->db->update('users', ['last_login_at' => Clock::db(Clock::now() - 900 * 86400)], 'id', $this->anna);
        $this->assertSame(['warned' => 0, 'erased' => 0], $ctx->app->privacy->processInactiveAccounts());
    }

    // ------------------------------------------------------------------ Datenschutzerklärung

    public function testPrivacyNoticeIsPublicAndRendersControllerEscaped(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/privacy');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('noch nicht hinterlegt', $r->body);
        $this->ctx->app->brand->save(['privacy_controller' => "Muster AG\n<script>alert(1)</script>", 'privacy_email' => 'datenschutz@example.org', 'privacy_extra' => 'Hinweis & mehr'], $this->super);
        $html = $b->get('/privacy')->body;
        $this->assertStringNotContains('noch nicht hinterlegt', $html);
        $this->assertStringNotContains('<script>alert(1)', $html);
        $this->assertStringContains('Muster AG<br />', $html);
        $this->assertStringContains('mailto:datenschutz@example.org', $html);
        $this->assertStringContains('Hinweis &amp; mehr', $html);
        $this->assertStringContains('Art. 6 Abs. 1 lit. b DSGVO', $html);
        $this->assertStringContains('Cookies', $html);
        $this->assertStringContains('href="/privacy"', $b->get('/login')->body, 'Footer-Link');
    }

    public function testPrivacyVersionChangesOnlyWhenTextChanges(): void
    {
        $br = $this->ctx->app->brand;
        $this->assertSame('1', $br->get('privacy_version'));
        $br->save(['name' => 'Neu'], $this->super);
        $this->assertSame('1', $br->get('privacy_version'));
        $br->save(['privacy_controller' => 'X GmbH'], $this->super);
        $this->assertSame(gmdate('Y-m-d', Clock::now()), $br->get('privacy_version'));
    }

    public function testPrivacyNoticeListsSocialProviders(): void
    {
        $a = $this->ctx->app;
        $a->social->saveProvider($this->super, ['type' => 'google', 'provider_key' => 'google', 'label' => 'Google', 'client_id' => 'x', 'client_secret' => 'y', 'issuer' => '', 'enabled' => true, 'allow_signup' => true]);
        $html = (new TestBrowser($a))->get('/privacy')->body;
        $this->assertStringContains('Drittländern', $html);
        $this->assertStringContains('Google', $html);
    }

    public function testTermsLinkValidatedAndShownOnRegistration(): void
    {
        $br = $this->ctx->app->brand;
        $this->assertThrows(\InvalidArgumentException::class, fn () => $br->save(['terms_url' => 'javascript:alert(1)'], $this->super));
        $br->save(['terms_url' => 'https://example.org/agb'], $this->super);
        $this->assertStringContains('https://example.org/agb', (new TestBrowser($this->ctx->app))->get('/register')->body);
    }
}
