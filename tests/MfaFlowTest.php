<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Tests\Support\{Factory, SoftAuthenticator, TestBrowser, TestCase};

final class MfaFlowTest extends TestCase
{
    private object $ctx;
    private int $uid;
    private const PW = 'Correct-Horse-Battery-9';
    private const ORIGIN = 'https://auth.test';

    protected function setUp(): void
    {
        $this->ctx = Factory::app();
        $this->uid = $this->ctx->app->users->create('anna@example.org', $this->ctx->app->passwords->hash(self::PW), 'Anna Muster', true);
    }

    private function login(TestBrowser $b, string $next = ''): void
    {
        $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW, 'next' => $next]);
    }

    private function enableTotp(): string
    {
        $setup = $this->ctx->app->mfa->beginTotpSetup($this->uid, 'anna@example.org');
        $this->ctx->app->mfa->confirmTotp($this->uid, $this->ctx->app->totp->codeAt($setup['secret'], $this->ctx->app->totp->currentStep()));
        Clock::advance(60);
        return $setup['secret'];
    }

    public function testTotpLoginRequiresSecondFactor(): void
    {
        $secret = $this->enableTotp();
        $b = new TestBrowser($this->ctx->app);
        $r = $b->post('/login', ['email' => 'anna@example.org', 'password' => self::PW, 'next' => '/account/sessions']);
        $this->assertSame('/mfa', $r->headers['Location']);
        // Noch nicht angemeldet:
        $this->assertSame(302, $b->get('/account')->status);
        $this->assertStringContains('/login', $b->last->headers['Location']);
        $this->assertStringContains('name="code"', $b->get('/mfa')->body);

        $this->assertSame(401, $b->post('/mfa/totp', ['code' => '123456'])->status);
        $good = $this->ctx->app->totp->codeAt($secret, $this->ctx->app->totp->currentStep());
        $before = $b->session()->id();
        $r = $b->post('/mfa/totp', ['code' => $good]);
        $this->assertSame('/account/sessions', $r->headers['Location']);
        $this->assertTrue($before !== $b->session()->id());
        $this->assertSame(200, $b->get('/account')->status);
        $this->assertSame(['pwd', 'otp', 'mfa'], $b->session()->get('amr'));
    }

    public function testMfaPageWithoutPendingLoginRedirects(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame('/login', $b->get('/mfa')->headers['Location']);
        $this->assertSame('/login', $b->post('/mfa/totp', ['code' => '000000'])->headers['Location']);
    }

    public function testPendingMfaExpires(): void
    {
        $this->enableTotp();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        Clock::advance(301);
        $this->assertSame('/login', $b->get('/mfa')->headers['Location']);
    }

    public function testRecoveryCodeLoginWorksOnce(): void
    {
        $this->enableTotp();
        $codes = $this->ctx->app->mfa->regenerateRecoveryCodes($this->uid);
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $this->assertSame('/account', $b->post('/mfa/recovery', ['code' => $codes[0]])->headers['Location']);
        $this->assertSame(200, $b->get('/account')->status);

        $b2 = new TestBrowser($this->ctx->app, '198.51.100.55');
        $this->login($b2);
        $this->assertSame(401, $b2->post('/mfa/recovery', ['code' => $codes[0]])->status);
    }

    public function testTotpAttemptsAreRateLimited(): void
    {
        $secret = $this->enableTotp();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        for ($i = 0; $i < 5; $i++) {
            $b->post('/mfa/totp', ['code' => '00000' . $i]);
        }
        $good = $this->ctx->app->totp->codeAt($secret, $this->ctx->app->totp->currentStep());
        $this->assertSame(401, $b->post('/mfa/totp', ['code' => $good])->status);
    }

    public function testPasskeyRegistrationThenMfaLogin(): void
    {
        $auth = new SoftAuthenticator();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $this->assertSame(200, $b->get('/account')->status);

        $b->postJson('/account/mfa/passkeys/options');
        $opts = $b->json();
        $this->assertSame('none', $opts['attestation']);
        $b->postJson('/account/mfa/passkeys/register', ['credential' => $auth->register($opts, self::ORIGIN), 'name' => 'Test-Key']);
        $this->assertSame(200, $b->last->status);
        $this->assertSame(10, count($b->json()['recoveryCodes']), 'erster Faktor erzeugt Recovery-Codes');
        $this->assertStringContains('Test-Key', $b->get('/account/mfa/passkeys')->body);

        $b2 = new TestBrowser($this->ctx->app, '198.51.100.60');
        $this->login($b2);
        $this->assertSame('/mfa', $b2->last->headers['Location']);
        $b2->postJson('/mfa/webauthn/options');
        $o = $b2->json();
        $b2->postJson('/mfa/webauthn/verify', ['credential' => $auth->assertion($o, self::ORIGIN)]);
        $this->assertSame(200, $b2->last->status);
        $this->assertSame('/account', $b2->json()['redirect']);
        $this->assertSame(200, $b2->get('/account')->status);
        $this->assertSame(['pwd', 'hwk', 'mfa'], $b2->session()->get('amr'));
    }

    public function testPasskeyMfaRejectsForgedAssertion(): void
    {
        $auth = new SoftAuthenticator();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $b->postJson('/account/mfa/passkeys/options');
        $b->postJson('/account/mfa/passkeys/register', ['credential' => $auth->register($b->json() ?: [], self::ORIGIN)]);
        $b->postJson('/account/mfa/passkeys/options');
        $b->postJson('/account/mfa/passkeys/register', ['credential' => $auth->register($b->json(), self::ORIGIN, ['rawId' => 'x'])]);

        $b2 = new TestBrowser($this->ctx->app, '198.51.100.61');
        $this->login($b2);
        $b2->postJson('/mfa/webauthn/options');
        $o = $b2->json();
        $b2->postJson('/mfa/webauthn/verify', ['credential' => $auth->assertion($o, self::ORIGIN, null, ['tamper' => true])]);
        $this->assertSame(401, $b2->last->status);
        $this->assertSame(302, $b2->get('/account')->status);
    }

    public function testPasswordlessPasskeyLogin(): void
    {
        $auth = new SoftAuthenticator();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $b->postJson('/account/mfa/passkeys/options');
        $b->postJson('/account/mfa/passkeys/register', ['credential' => $auth->register($b->json(), self::ORIGIN), 'name' => 'K']);

        $fresh = new TestBrowser($this->ctx->app, '198.51.100.70');
        $fresh->postJson('/login/passkey/options', ['next' => 'https://evil.example']);
        $o = $fresh->json();
        $this->assertSame('required', $o['userVerification']);
        $handle = $this->ctx->app->passkeys->userHandle($this->uid);
        $fresh->postJson('/login/passkey/verify', ['credential' => $auth->assertion($o, self::ORIGIN, $handle)]);
        $this->assertSame(200, $fresh->last->status);
        $this->assertSame('/account', $fresh->json()['redirect'], 'externes next wird verworfen');
        $this->assertSame(200, $fresh->get('/account')->status);
    }

    public function testJsonEndpointsRequireCsrfHeader(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $this->assertSame(403, $b->postJson('/account/mfa/passkeys/options', [], false)->status);
        $this->assertSame(403, $b->postJson('/login/passkey/options', [], false)->status);
    }

    public function testDisablingTotpNeedsPassword(): void
    {
        $secret = $this->enableTotp();
        $b = new TestBrowser($this->ctx->app);
        $this->login($b);
        $b->post('/mfa/totp', ['code' => $this->ctx->app->totp->codeAt($secret, $this->ctx->app->totp->currentStep())]);
        $this->assertSame(200, $b->get('/account')->status);
        $this->assertSame(403, $b->post('/account/mfa/totp/disable', ['password' => 'falsch'])->status);
        $this->assertTrue($this->ctx->app->mfa->hasTotp($this->uid));
        $this->assertSame(302, $b->post('/account/mfa/totp/disable', ['password' => self::PW])->status);
        $this->assertFalse($this->ctx->app->mfa->hasTotp($this->uid));
    }

    public function testSecuritySensitivePagesRequireLogin(): void
    {
        $b = new TestBrowser($this->ctx->app);
        foreach (['/account/mfa/totp', '/account/mfa/passkeys', '/account/mfa/recovery'] as $p) {
            $this->assertSame(302, $b->get($p)->status, $p);
        }
        $this->assertSame(401, $b->postJson('/account/mfa/passkeys/options')->status);
    }
}
