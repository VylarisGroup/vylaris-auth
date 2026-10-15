<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\{Clock, Session};
use Vylaris\Service\TokenService;
use Vylaris\Tests\Support\{Factory, TestCase};

final class AuthServiceTest extends TestCase
{
    private object $s;

    protected function setUp(): void
    {
        $this->s = Factory::auth();
    }

    public function testRegisterCreatesUserAndSendsVerificationMail(): void
    {
        $r = $this->s->auth->register('Neu@Example.org ', 'Sehr-Langes-Passwort-42', 'Neu Nutzer', '1.1.1.1', true);
        $this->assertTrue($r['ok']);
        $user = $this->s->users->findByEmail('neu@example.org');
        $this->assertNotNull($user);
        $this->assertNull($user['email_verified_at']);
        $this->assertSame(['user'], $this->s->users->roleNames((int) $user['id']));
        $this->s->mailer->flush();
        $this->assertStringContains('/verify-email?token=', $this->s->mail->last()['body']);
    }

    public function testRegisterRejectsWeakPasswordAndBadEmail(): void
    {
        $r = $this->s->auth->register('kein-email', 'kurz', 'X', '');
        $this->assertFalse($r['ok']);
        $this->assertTrue(count($r['errors']) >= 3);
    }

    public function testRegisterDuplicateEmailDoesNotRevealExistence(): void
    {
        Factory::user($this->s, 'vorhanden@example.org');
        $r = $this->s->auth->register('vorhanden@example.org', 'Sehr-Langes-Passwort-42', 'Zweitversuch', '', true);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->s->users->count());
    }

    public function testPasswordHashIsArgon2idWhenAvailable(): void
    {
        $hash = $this->s->passwords->hash('Correct-Horse-Battery-9');
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringContains('$argon2id$', $hash);
        }
        $this->assertTrue($this->s->passwords->verify('Correct-Horse-Battery-9', $hash));
        $this->assertFalse($this->s->passwords->verify('falsch', $hash));
    }

    public function testVerifyEmailTokenIsSingleUse(): void
    {
        $this->s->auth->register('v@example.org', 'Sehr-Langes-Passwort-42', 'Vera Test', '', true);
        $this->s->mailer->flush();
        preg_match('/token=([A-Za-z0-9_-]+)/', $this->s->mail->last()['body'], $m);
        $this->assertTrue($this->s->auth->verifyEmail($m[1]));
        $this->assertFalse($this->s->auth->verifyEmail($m[1]));
        $this->assertNotNull($this->s->users->findByEmail('v@example.org')['email_verified_at']);
    }

    public function testVerifyTokenExpires(): void
    {
        $id = Factory::user($this->s);
        $t = $this->s->tokens->create($id, TokenService::VERIFY_EMAIL, 60);
        Clock::advance(61);
        $this->assertFalse($this->s->auth->verifyEmail($t));
    }

    public function testLoginSuccessAndWrongPassword(): void
    {
        Factory::user($this->s);
        $this->assertSame('invalid', $this->s->auth->login('anna@example.org', 'falsch', '2.2.2.2')->status);
        $this->assertSame('invalid', $this->s->auth->login('unbekannt@example.org', 'irgendwas', '2.2.2.2')->status);
        $ok = $this->s->auth->login('ANNA@example.org', 'Correct-Horse-Battery-9', '2.2.2.2');
        $this->assertTrue($ok->ok());
    }

    public function testLoginRequiresVerifiedEmail(): void
    {
        $this->s->auth->register('u@example.org', 'Sehr-Langes-Passwort-42', 'Unverified', '', true);
        $r = $this->s->auth->login('u@example.org', 'Sehr-Langes-Passwort-42', '3.3.3.3');
        $this->assertSame('unverified', $r->status);
    }

    public function testBruteForceThrottlesAccount(): void
    {
        Factory::user($this->s);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalid', $this->s->auth->login('anna@example.org', 'falsch' . $i, '4.4.4.' . $i)->status);
        }
        // Selbst das richtige Passwort wird jetzt gedrosselt.
        $this->assertSame('throttled', $this->s->auth->login('anna@example.org', 'Correct-Horse-Battery-9', '4.4.4.99')->status);
        Clock::advance(901);
        $this->assertTrue($this->s->auth->login('anna@example.org', 'Correct-Horse-Battery-9', '4.4.4.99')->ok());
    }

    public function testLockedAccountCannotLogin(): void
    {
        $id = Factory::user($this->s);
        $this->s->users->setLocked($id, true, 'Test');
        $this->assertSame('locked', $this->s->auth->login('anna@example.org', 'Correct-Horse-Battery-9', '5.5.5.5')->status);
    }

    public function testSessionIdIsRotatedOnLogin(): void
    {
        $id = Factory::user($this->s);
        $session = new Session([], 'preauth-id');
        $before = $session->id();
        $this->s->auth->establishSession($session, $id);
        $this->assertTrue($before !== $session->id());
        $this->assertSame($id, $session->userId());
        $this->assertNotNull($session->get('csrf'));
    }

    public function testPasswordResetFlowRevokesSessionsAndIsSingleUse(): void
    {
        $id = Factory::user($this->s);
        $this->s->db->insert('sessions', ['id' => 'h1', 'user_id' => $id, 'data' => '', 'created_at' => Clock::db(), 'last_seen_at' => Clock::db(), 'expires_at' => Clock::db(Clock::now() + 600)]);
        $this->s->auth->requestPasswordReset('anna@example.org', '6.6.6.6');
        $this->s->mailer->flush();
        preg_match('/token=([A-Za-z0-9_-]+)/', $this->s->mail->last()['body'], $m);
        $this->assertSame(['Das Passwort muss mindestens 12 Zeichen lang sein.'], $this->s->auth->resetPassword($m[1], 'kurz'));
        $this->assertSame([], $this->s->auth->resetPassword($m[1], 'Neues-Sicheres-Passwort-7'));
        $this->assertSame(['Der Link ist ungültig oder abgelaufen.'], $this->s->auth->resetPassword($m[1], 'Noch-Ein-Neues-Passwort-8'));
        $this->assertTrue($this->s->auth->login('anna@example.org', 'Neues-Sicheres-Passwort-7', '6.6.6.6')->ok());
        $this->assertFalse($this->s->sessions->isActive('h1', $id));
    }

    public function testResetRequestForUnknownEmailSendsNothing(): void
    {
        $this->s->auth->requestPasswordReset('niemand@example.org', '7.7.7.7');
        $this->s->mailer->flush();
        $this->assertSame(0, count($this->s->mail->sent));
    }

    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $id = Factory::user($this->s);
        $this->assertSame(['Das aktuelle Passwort ist falsch.'], $this->s->auth->changePassword($id, 'falsch', 'Neues-Sicheres-Passwort-7'));
        $this->assertSame([], $this->s->auth->changePassword($id, 'Correct-Horse-Battery-9', 'Neues-Sicheres-Passwort-7'));
    }

    public function testAuditLogContainsNoSecrets(): void
    {
        Factory::user($this->s);
        $this->s->auth->login('anna@example.org', 'Geheimes-Falsches-Passwort-1', '8.8.8.8');
        $all = json_encode($this->s->db->all('SELECT * FROM audit_log'));
        $this->assertStringNotContains('Geheimes-Falsches-Passwort-1', $all);
        $scrub = \Vylaris\Service\AuditLogger::scrub(['password' => 'x', 'ok' => 'y', 'nested' => ['access_token' => 'z']]);
        $this->assertSame('[entfernt]', $scrub['password']);
        $this->assertSame('[entfernt]', $scrub['nested']['access_token']);
        $this->assertSame('y', $scrub['ok']);
    }
}
