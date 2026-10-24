<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** Seitenleiste mit ausklappbaren Gruppen und Assistenten (die alten Formulare bleiben). */
final class UiTest extends TestCase
{
    private const PW = 'Correct-Horse-Battery-9';

    private function ctx(): object
    {
        $ctx = Factory::app(['license' => ['required' => false]]);
        $a = $ctx->app;
        foreach ([['admin@example.org', 'admin'], ['user@example.org', 'user']] as [$mail, $role]) {
            $id = $a->users->create($mail, $a->passwords->hash(self::PW), 'P ' . $role, true);
            $a->users->assignRole($id, $role);
        }
        return $ctx;
    }

    private function login(object $ctx, string $mail): TestBrowser
    {
        $b = new TestBrowser($ctx->app);
        $b->post('/login', ['email' => $mail, 'password' => self::PW]);
        return $b;
    }

    public function testAdminPagesHaveCollapsibleSidebarWithActiveGroupOpen(): void
    {
        $b = $this->login($this->ctx(), 'admin@example.org');
        $page = $b->get('/admin/apps')->body;
        $this->assertStringContains('class="sidebar"', $page);
        foreach (['Verwaltung', 'Sicherheit', 'System', 'Assistenten'] as $g) {
            $this->assertStringContains('<summary class="side-summary"><span><i class="bx ', $page);
            $this->assertStringContains($g, $page);
        }
        $this->assertTrue((bool) preg_match('#<details class="side-group" data-side-key="admin:Verwaltung" open data-active="1">#', $page), 'Gruppe der aktuellen Seite ist offen');
        $this->assertTrue((bool) preg_match('#<details class="side-group" data-side-key="admin:System">#', $page), 'andere Gruppen sind zu');
        $this->assertStringContains('href="/admin/apps" aria-current="page"', $page);
        $this->assertStringNotContains('aria-label="Administration">' . "\n" . '  <a href="/admin"', $page, 'alte Tab-Leiste entfernt');
    }

    public function testAccountPagesHaveSidebarAndPublicPagesDoNot(): void
    {
        $ctx = $this->ctx();
        $b = $this->login($ctx, 'user@example.org');
        $page = $b->get('/account')->body;
        $this->assertStringContains('class="sidebar"', $page);
        $this->assertStringContains('Mein Konto', $page);
        $this->assertStringNotContains('Administration', $page, 'normale Nutzer sehen keine Admin-Leiste');
        $this->assertStringNotContains('class="sidebar"', (new TestBrowser($ctx->app))->get('/login')->body);
        $this->assertStringNotContains('class="sidebar"', $this->login($ctx, 'user@example.org')->get('/admin')->body, 'Admin-Seite für Nutzer: Fehlerseite ohne Admin-Leiste');
    }

    public function testWizardPagesRenderAndPostToTheExistingEndpoints(): void
    {
        $ctx = $this->ctx();
        $b = $this->login($ctx, 'admin@example.org');
        foreach (['/admin/apps/new' => '/admin/apps', '/admin/users/new' => '/admin/users', '/admin/social/new' => '/admin/social'] as $path => $action) {
            $r = $b->get($path);
            $this->assertSame(200, $r->status, $path);
            $this->assertStringContains('data-wizard', $r->body);
            $this->assertStringContains('action="' . $action . '"', $r->body);
            $this->assertTrue(substr_count($r->body, 'class="wiz-step"') >= 2, $path . ' hat mehrere Schritte');
            $this->assertStringContains('data-wizard-summary', $r->body);
        }
        // Die klassischen Formulare bleiben bestehen und verweisen auf den Assistenten.
        foreach (['/admin/apps' => '/admin/apps/new', '/admin/users' => '/admin/users/new', '/admin/social' => '/admin/social/new'] as $path => $wiz) {
            $body = $b->get($path)->body;
            $this->assertStringContains('href="' . $wiz . '"', $body);
            $this->assertStringContains('<form method="post" action="' . $path . '"', $body);
        }
        // Absenden der Assistenten-Felder legt tatsächlich an (gleiche Endpunkte wie das alte Formular).
        $res = $b->post('/admin/apps', ['name' => 'Wizard-App', 'type' => 'confidential', 'redirect_uris' => "https://app.example.org/cb", 'logout_uris' => '', 'scopes' => 'openid profile email roles', 'access_policy' => 'all']);
        $this->assertSame(201, $res->status);
        $this->assertStringContains('Wizard-App', $b->get('/admin/apps')->body);
        $res = $b->post('/admin/users', ['email' => 'neu@example.org', 'name' => 'Neu Nutzer']);
        $this->assertSame(302, $res->status);
        $this->assertNotNull($ctx->app->users->findByEmail('neu@example.org'));
    }

    public function testThemeToggleHasVisibleLabelAndFrame(): void
    {
        $ctx = $this->ctx();
        $this->assertStringContains('class="theme-label"', (new TestBrowser($ctx->app))->get('/login')->body);
        $this->assertStringContains('class="theme-label"', $this->login($ctx, 'admin@example.org')->get('/admin')->body);
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
        $this->assertTrue((bool) preg_match('/\.theme-toggle\{[^}]*border-width:1px/', $css), 'Umschalter hat einen sichtbaren Rahmen');
    }

    public function testWizardsAreAdminOnly(): void
    {
        $ctx = $this->ctx();
        $u = $this->login($ctx, 'user@example.org');
        $anon = new TestBrowser($ctx->app);
        foreach (['/admin/apps/new', '/admin/users/new', '/admin/social/new'] as $p) {
            $this->assertSame(403, $u->get($p)->status, $p);
            $this->assertSame(302, $anon->get($p)->status, $p);
        }
    }

    public function testSidebarShowsUnchangeableWatermark(): void
    {
        $ctx = $this->ctx();
        $wm = \Vylaris\Core\Watermark::html();
        $this->assertStringContains('powered by VylarisAuth', $wm);
        $this->assertStringContains('href="https://vylaris.ch"', $wm);
        foreach ([['admin@example.org', '/admin/apps'], ['user@example.org', '/account']] as [$mail, $path]) {
            $page = $this->login($ctx, $mail)->get($path)->body;
            $this->assertStringContains($wm, $page, $path);
            $this->assertSame(1, substr_count($page, 'data-vy-wm'), 'genau einmal, in der Seitenleiste');
        }
        $this->assertStringNotContains('data-vy-wm', (new TestBrowser($ctx->app))->get('/login')->body, 'nur in der Seitenleiste');
        // Wurde das Template verändert, ergänzt das System den Hinweis trotzdem.
        $page = '<html><body><details class="sidebar"><nav></nav></details></body></html>';
        $fixed = \Vylaris\Core\Watermark::ensure($page);
        $this->assertStringContains(\Vylaris\Core\Watermark::html(true), $fixed);
        $this->assertSame($fixed, \Vylaris\Core\Watermark::ensure($fixed), 'idempotent');
        $this->assertSame('<p>x</p>', \Vylaris\Core\Watermark::ensure('<p>x</p>'), 'Seiten ohne Seitenleiste bleiben unberührt');
        // Eine manipulierte Variante (Link entfernt) wird ebenfalls ergänzt.
        $tampered = '<details class="sidebar"><p class="vy-wm" data-vy-wm="1">powered by VylarisAuth</p></details></body>';
        $this->assertStringContains('https://vylaris.ch', \Vylaris\Core\Watermark::ensure($tampered));
    }

    public function testWatermarkStylesComeLastAndCannotBeTargetedByCustomCss(): void
    {
        $ctx = $this->ctx();
        $css = (new TestBrowser($ctx->app))->get('/brand/theme.css')->body;
        $this->assertStringContains('Herkunftshinweis', $css);
        $this->assertStringContains('display:block!important', $css);
        foreach (['.vy-wm{display:none}', 'a[href*="vylaris"]{display:none}', '.sidebar > p:last-child{display:none}', '.sidebar{display:none}', '[data-vy-wm]{opacity:0}'] as $bad) {
            $this->assertTrue(\Vylaris\Core\Watermark::touchesCss($bad), $bad);
        }
        $this->assertFalse(\Vylaris\Core\Watermark::touchesCss('.card{border-width:2px}'));
    }
}
