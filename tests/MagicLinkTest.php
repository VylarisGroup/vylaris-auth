<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

final class MagicLinkTest extends TestCase
{
    private object $ctx;
    private int $uid;

    private function boot(array $cfg = []): void
    {
        $this->ctx = Factory::app($cfg);
        $this->uid = $this->ctx->app->users->create('anna@example.org', $this->ctx->app->passwords->hash('Correct-Horse-Battery-9'), 'Anna Muster', true);
    }

    /** Fordert einen Link an und liefert das Token aus der Mail. */
    private function request(TestBrowser $b, string $email = 'anna@example.org', string $next = ''): ?string
    {
        $before = count($this->ctx->mail->sent ?? []);
        $r = $b->post('/login/magic', ['email' => $email, 'next' => $next]);
        $this->assertSame(200, $r->status);
        $this->ctx->app->mailer->flush();
        $mail = $this->ctx->mail->last();
        if ($mail === null || $mail['to'] !== $email || !str_contains($mail['body'], '/login/magic/verify')) {
            return null;
        }
        preg_match('/token=([A-Za-z0-9_-]+)/', $mail['body'], $m);
        return $m[1] ?? null;
    }

    public function testLoginPageOffersMagicLink(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        $this->assertStringContains('/login/magic', $b->get('/login')->body);
        $this->assertStringContains('name="email"', $b->get('/login/magic')->body);
    }

    public function testMagicLinkLogsInAndIsSingleUse(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b);
        $this->assertTrue($token !== null);

        // GET verbraucht nichts (Mail-Scanner), zeigt nur die Bestätigung
        $page = $b->get('/login/magic/verify', ['token' => $token]);
        $this->assertSame(200, $page->status);
        $this->assertStringContains('anna@example.org', $page->body);
        $this->assertSame(302, $b->get('/account')->status);
        $this->assertSame(200, $b->get('/login/magic/verify', ['token' => $token])->status);

        $before = $b->session()->id();
        $r = $b->post('/login/magic/verify', ['token' => $token]);
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertTrue($before !== $b->session()->id(), 'Session-ID muss rotieren');
        $this->assertSame(200, $b->get('/account')->status);
        $this->assertSame(['mlink'], $b->session()->get('amr'));

        $other = new TestBrowser($this->ctx->app, '198.51.100.9');
        $this->assertSame(400, $other->post('/login/magic/verify', ['token' => $token])->status);
        $this->assertSame(400, $other->get('/login/magic/verify', ['token' => $token])->status);
    }

    public function testUnknownAndLockedAccountsGetSameAnswerAndNoMail(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        $known = $b->post('/login/magic', ['email' => 'anna@example.org']);
        $this->ctx->app->mailer->flush();
        $mails = count($this->ctx->mail->sent);
        $unknown = $b->post('/login/magic', ['email' => 'niemand@example.org']);
        $this->assertSame($known->status, $unknown->status);
        $this->assertSame($mails, count($this->ctx->mail->sent), 'Unbekannte Adresse: keine Mail');
        $this->ctx->app->db->update('users', ['locked_until' => \Vylaris\Core\Clock::db(Clock::now() + 3600)], 'id', $this->uid);
        $this->ctx->app->mailer->flush();
        $this->assertSame($mails, count($this->ctx->mail->sent));
    }

    public function testExpiredLinkIsRejected(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b);
        Clock::advance(901);
        $this->assertSame(400, $b->post('/login/magic/verify', ['token' => $token])->status);
        $this->assertSame(302, $b->get('/account')->status);
    }

    public function testNewRequestInvalidatesOlderLink(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        $old = $this->request($b);
        $new = $this->request($b);
        $this->assertSame(400, $b->post('/login/magic/verify', ['token' => $old])->status);
        $this->assertSame(302, $b->post('/login/magic/verify', ['token' => $new])->status);
    }

    public function testSecondFactorStaysRequired(): void
    {
        $this->boot();
        $setup = $this->ctx->app->mfa->beginTotpSetup($this->uid, 'anna@example.org');
        $this->ctx->app->mfa->confirmTotp($this->uid, $this->ctx->app->totp->codeAt($setup['secret'], $this->ctx->app->totp->currentStep()));
        Clock::advance(60);

        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b);
        $r = $b->post('/login/magic/verify', ['token' => $token, 'next' => '/account/sessions']);
        $this->assertSame('/mfa', $r->headers['Location']);
        $this->assertSame(302, $b->get('/account')->status, 'Vor dem zweiten Faktor nicht angemeldet');

        $good = $this->ctx->app->totp->codeAt($setup['secret'], $this->ctx->app->totp->currentStep());
        $done = $b->post('/mfa/totp', ['code' => $good]);
        $this->assertSame('/account/sessions', $done->headers['Location']);
        $this->assertSame(200, $b->get('/account')->status);
        $this->assertSame(['mlink', 'otp', 'mfa'], $b->session()->get('amr'));
    }

    public function testMagicLinkDoesNotSatisfyAdminMfaRequirement(): void
    {
        $this->boot(['security' => ['admin_requires_mfa' => true]]);
        $this->ctx->app->users->assignRole($this->uid, 'superadmin');
        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b);
        $b->post('/login/magic/verify', ['token' => $token]);
        $r = $b->get('/admin');
        $this->assertTrue($r->status === 302 || $r->status === 403, 'Admin ohne zweiten Faktor nicht erlaubt');
    }

    public function testSameBrowserBindingWhenEnabled(): void
    {
        $this->boot(['security' => ['magic_link_same_browser' => true]]);
        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b);
        $other = new TestBrowser($this->ctx->app, '198.51.100.7');
        $this->assertSame(403, $other->post('/login/magic/verify', ['token' => $token])->status);
        $this->assertSame(302, $b->post('/login/magic/verify', ['token' => $token])->status);
    }

    public function testRequestsAreRateLimited(): void
    {
        $this->boot();
        $b = new TestBrowser($this->ctx->app);
        for ($i = 0; $i < 8; $i++) {
            $b->post('/login/magic', ['email' => 'anna@example.org']);
        }
        $this->ctx->app->mailer->flush();
        $count = 0;
        foreach ($this->ctx->mail->sent as $m) {
            $count += str_contains($m['body'], '/login/magic/verify') ? 1 : 0;
        }
        $this->assertSame(5, $count);
    }

    public function testCanBeDisabled(): void
    {
        $this->boot(['security' => ['magic_link_enabled' => false]]);
        $b = new TestBrowser($this->ctx->app);
        $this->assertTrue(!str_contains($b->get('/login')->body, '/login/magic'));
        $this->assertSame(302, $b->get('/login/magic')->status);
        $b->post('/login/magic', ['email' => 'anna@example.org']);
        $this->ctx->app->mailer->flush();
        $this->assertSame(0, count($this->ctx->mail->sent));
    }

    public function testUnverifiedAccountGetsVerifiedByLink(): void
    {
        $this->boot();
        $id = $this->ctx->app->users->create('neu@example.org', $this->ctx->app->passwords->hash('Correct-Horse-Battery-9'), 'Neu', false);
        $b = new TestBrowser($this->ctx->app);
        $token = $this->request($b, 'neu@example.org');
        $this->assertSame(302, $b->post('/login/magic/verify', ['token' => $token])->status);
        $this->assertTrue($this->ctx->app->users->findById($id)['email_verified_at'] !== null);
    }
}
