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
}
