<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

final class WebFlowTest extends TestCase
{
    private object $ctx;
    private TestBrowser $b;

    protected function setUp(): void
    {
        $this->ctx = Factory::app();
        $this->b = new TestBrowser($this->ctx->app);
    }

    private function registerAndVerify(string $email = 'anna@example.org', string $pw = 'Sehr-Langes-Passwort-42'): void
    {
        $r = $this->b->post('/register', ['email' => $email, 'password' => $pw, 'name' => 'Anna Muster', 'accept_privacy' => '1']);
        $this->assertSame(200, $r->status);
        $this->ctx->app->mailer->flush();
        preg_match('/token=([A-Za-z0-9_-]+)/', $this->ctx->mail->last()['body'], $m);
        $this->assertSame(200, $this->b->get('/verify-email', ['token' => $m[1]])->status);
    }

    public function testSecurityHeadersAndLoginForm(): void
    {
        $r = $this->b->get('/login');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('name="_csrf"', $r->body);
        $this->assertStringContains("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertStringContains('max-age=', $r->headers['Strict-Transport-Security']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $r = $this->b->post('/login', ['email' => 'a@b.c', 'password' => 'x'], false);
        $this->assertSame(403, $r->status);
        $wrong = $this->b->post('/login', ['email' => 'a@b.c', 'password' => 'x', '_csrf' => 'falsch'], false);
        $this->assertSame(403, $wrong->status);
    }

    public function testRegisterVerifyLoginDashboardLogout(): void
    {
        $this->registerAndVerify();
        $pre = $this->b->session()->id();
        $r = $this->b->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $this->assertSame(302, $r->status);
        $this->assertSame('/account', $r->headers['Location']);
        $this->assertTrue($pre !== $this->b->session()->id(), 'Session-ID muss rotieren');

        $dash = $this->b->get('/account');
        $this->assertSame(200, $dash->status);
        $this->assertStringContains('Anna Muster', $dash->body);

        $out = $this->b->post('/logout');
        $this->assertSame(302, $out->status);
        $again = $this->b->get('/account');
        $this->assertSame(302, $again->status);
        $this->assertStringContains('/login', $again->headers['Location']);
    }

    public function testLoginErrorsAreGeneric(): void
    {
        $this->registerAndVerify();
        $wrongPw = $this->b->post('/login', ['email' => 'anna@example.org', 'password' => 'falsch']);
        $unknown = $this->b->post('/login', ['email' => 'niemand@example.org', 'password' => 'falsch']);
        $this->assertSame(401, $wrongPw->status);
        $this->assertSame(401, $unknown->status);
        $strip = static fn (string $h) => preg_replace('/name="_csrf" value="[a-f0-9]+"/', '', preg_replace('/value="[^"]*"/', '', $h));
        $this->assertSame($strip($wrongPw->body), $strip($unknown->body));
    }

    public function testOpenRedirectIsBlocked(): void
    {
        $this->registerAndVerify();
        foreach (['https://evil.example/', '//evil.example', '/\\evil.example', 'javascript:alert(1)'] as $bad) {
            $b = new TestBrowser($this->ctx->app);
            $r = $b->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42', 'next' => $bad]);
            $this->assertSame('/account', $r->headers['Location'], "next=$bad");
        }
        $b = new TestBrowser($this->ctx->app);
        $r = $b->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42', 'next' => '/account/sessions']);
        $this->assertSame('/account/sessions', $r->headers['Location']);
    }

    public function testProtectedPagesRedirectAnonymousUsers(): void
    {
        foreach (['/account', '/account/sessions'] as $p) {
            $r = $this->b->get($p);
            $this->assertSame(302, $r->status);
            $this->assertStringContains('/login?next=', $r->headers['Location']);
        }
    }

    public function testRevokingDeviceLogsOutThatBrowserImmediately(): void
    {
        $this->registerAndVerify();
        $b1 = new TestBrowser($this->ctx->app, '198.51.100.1');
        $b2 = new TestBrowser($this->ctx->app, '198.51.100.2', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');
        foreach ([$b1, $b2] as $b) {
            $this->assertSame(302, $b->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42'])->status);
        }
        $this->assertSame(200, $b2->get('/account')->status);

        $list = $b1->get('/account/sessions');
        $this->assertStringContains('Chrome auf Windows', $list->body);
        $target = $b2->session()->idHash();
        $this->assertSame(302, $b1->post('/account/sessions/revoke', ['session' => $target])->status);

        $this->assertSame(302, $b2->get('/account')->status, 'widerrufenes Gerät muss sofort abgemeldet sein');
        $this->assertSame(200, $b1->get('/account')->status);
    }

    public function testCannotRevokeSessionOfAnotherUser(): void
    {
        $this->registerAndVerify();
        $this->registerAndVerify('bob@example.org');
        $a = new TestBrowser($this->ctx->app, '198.51.100.10');
        $bob = new TestBrowser($this->ctx->app, '198.51.100.11');
        $a->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $bob->post('/login', ['email' => 'bob@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $a->post('/account/sessions/revoke', ['session' => $bob->session()->idHash()]);
        $this->assertSame(200, $bob->get('/account')->status);
    }

    public function testChangedPasswordLogsOutOtherDevices(): void
    {
        $this->registerAndVerify();
        $a = new TestBrowser($this->ctx->app, '198.51.100.20');
        $other = new TestBrowser($this->ctx->app, '198.51.100.21');
        $a->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $other->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $r = $a->post('/account/password', ['current_password' => 'Sehr-Langes-Passwort-42', 'new_password' => 'Ganz-Neues-Passwort-2026']);
        $this->assertSame(302, $r->status);
        $this->assertSame(200, $a->get('/account')->status);
        $this->assertSame(302, $other->get('/account')->status);
    }

    public function testXssInDisplayNameIsEscaped(): void
    {
        $this->registerAndVerify();
        $this->b->post('/login', ['email' => 'anna@example.org', 'password' => 'Sehr-Langes-Passwort-42']);
        $this->b->post('/account/profile', ['name' => '<script>alert(1)</script>']);
        $html = $this->b->get('/account')->body;
        $this->assertStringNotContains('<script>alert(1)</script>', $html);
        $this->assertStringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testUnknownRouteIs404(): void
    {
        $this->assertSame(404, $this->b->get('/gibt-es-nicht')->status);
    }
}
