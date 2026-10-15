<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Branding\BrandingService;
use Vylaris\Core\Request;
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** Whitelabel: Branding-Werte, Theme-CSS, Uploads, Berechtigungen, Hell/Dunkel. */
final class BrandingTest extends TestCase
{
    private object $ctx;
    private string $dir;
    private int $admin;
    private int $mod;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vbrand-' . bin2hex(random_bytes(6));
        $this->ctx = Factory::app(['paths' => ['branding_dir' => $this->dir], 'app' => ['name' => 'Auth', 'org' => 'Beispiel AG']]);
        $a = $this->ctx->app;
        $this->admin = $a->users->create('admin@example.org', $a->passwords->hash(self::PW), 'Admin', true);
        $a->users->assignRole($this->admin, 'superadmin');
        $this->mod = $a->users->create('mod@example.org', $a->passwords->hash(self::PW), 'Mod', true);
        $a->users->assignRole($this->mod, 'admin');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function login(string $email): TestBrowser
    {
        $b = new TestBrowser($this->ctx->app);
        $b->post('/login', ['email' => $email, 'password' => self::PW]);
        return $b;
    }

    private function png(int $w = 32, int $h = 32): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0" . str_repeat("\x40\x60\xff", $w);
        }
        $chunk = static fn (string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
    }

    private function brand(): BrandingService
    {
        return $this->ctx->app->brand;
    }

    private function form(array $over = []): array
    {
        return $over + ['name' => 'Acme ID', 'org' => 'Acme AG', 'login_title' => 'Willkommen', 'allow_toggle' => '1', 'show_name' => '1'];
    }

    // ------------------------------------------------------------------ Standardwerte

    public function testDefaultsComeFromConfig(): void
    {
        $this->assertSame('Auth', $this->brand()->get('name'));
        $this->assertSame('Beispiel AG', $this->brand()->get('org'));
        $this->assertSame('dark', $this->brand()->get('default_theme'));
        $this->assertSame('Ein Konto für alle Anwendungen von Beispiel AG.', $this->brand()->text('login_subtitle'));
    }

    public function testDarkIsDefaultTheme(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $html = $b->get('/login')->body;
        $this->assertStringContains('data-theme="dark"', $html);
        $this->assertStringContains('data-theme-toggle', $html);
        $this->assertStringContains('/brand/theme.css?v=', $html);
    }

    public function testNoHardcodedGroupNameLeft(): void
    {
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringNotContains('Vylaris', $html);
        $this->assertStringContains('Beispiel AG', $html);
    }

    // ------------------------------------------------------------------ Theme-Cookie

    public function testThemeCookieSwitchesToLight(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $b->cookies = ['vtheme' => 'light'];
        $this->assertStringContains('data-theme="light"', $b->get('/login')->body);
        $b->cookies = ['vtheme' => 'evil"><script>'];
        $this->assertStringContains('data-theme="dark"', $b->get('/login')->body);
    }

    public function testDefaultLightAndToggleDisabled(): void
    {
        $this->brand()->save(['default_theme' => 'light', 'allow_toggle' => '0'], $this->admin);
        $b = new TestBrowser($this->ctx->app);
        $b->cookies = ['vtheme' => 'dark'];
        $html = $b->get('/login')->body;
        $this->assertStringContains('data-theme="light"', $html);
        $this->assertStringNotContains('data-theme-toggle', $html);
    }

    public function testSystemThemePreferenceIsExposedToScript(): void
    {
        $this->brand()->save(['default_theme' => 'system'], $this->admin);
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('data-theme-pref="system"', $html);
        $this->assertStringContains('data-theme="dark"', $html); // Fallback ohne JavaScript
    }

    // ------------------------------------------------------------------ Texte & XSS

    public function testNameAndTextsAreAppliedAndEscaped(): void
    {
        $this->brand()->save($this->form(['tagline' => 'x', 'footer' => '<b>© {org}</b>', 'login_subtitle' => '<img src=x onerror=alert(1)>']), $this->admin);
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('Willkommen', $html);
        $this->assertStringContains('&lt;b&gt;© Acme AG&lt;/b&gt;', $html);
        $this->assertStringNotContains('<img src=x', $html);
        $this->assertStringContains('· Acme ID</title>', $html);
    }

    public function testNameRejectsMarkupAndLength(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['name' => 'A<script>'], $this->admin));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['name' => ''], $this->admin));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['name' => str_repeat('a', 61)], $this->admin));
        $this->assertSame('Auth', $this->brand()->get('name'));
    }

    public function testLegalLinksOnlyHttpsOrLocal(): void
    {
        foreach (['javascript:alert(1)', 'http://example.org', '//evil.example', 'https://user:pw@example.org', 'data:text/html,x'] as $bad) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['legal_1_label' => 'Impressum', 'legal_1_url' => $bad], $this->admin), $bad);
        }
        $this->brand()->save(['legal_1_label' => 'Impressum', 'legal_1_url' => 'https://example.org/imp', 'legal_2_label' => 'Datenschutz', 'legal_2_url' => '/datenschutz', 'support_email' => 'hilfe@example.org'], $this->admin);
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('href="https://example.org/imp"', $html);
        $this->assertStringContains('href="/datenschutz"', $html);
        $this->assertStringContains('mailto:hilfe@example.org', $html);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['support_email' => 'kein-mail'], $this->admin));
    }

    // ------------------------------------------------------------------ Farben / Theme-CSS

    public function testThemeCssContainsVariables(): void
    {
        $css = $this->brand()->themeCss();
        $this->assertStringContains('--brand-600: 79 70 229;', $css); // indigo-600
        $this->assertStringContains('--accent-600:', $css);
        $this->assertStringContains('--n-950:', $css);
        $this->assertStringContains('--r-xl: .75rem;', $css);
    }

    public function testPresetAndCustomHexColors(): void
    {
        $this->brand()->save(['primary' => 'emerald', 'accent' => '#7a1fa2', 'neutral' => 'zinc', 'radius' => 'round', 'font' => 'serif'], $this->admin);
        $css = $this->brand()->themeCss();
        $this->assertStringContains('--brand-600: 5 150 105;', $css);
        $this->assertStringContains('--accent-600: 122 31 162;', $css);
        $this->assertStringContains('--r-xl: 1.25rem;', $css);
        $this->assertStringContains('Georgia', $css);
    }

    public function testColorValidation(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['primary' => '#ffff00'], $this->admin), 'zu hell');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['primary' => 'red; background:url(x)'], $this->admin));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['primary' => '#12345'], $this->admin));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['radius' => 'huge'], $this->admin));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['default_theme' => 'neon'], $this->admin));
        $this->assertSame('indigo', $this->brand()->get('primary'));
    }

    public function testShadesAreDeterministic(): void
    {
        $a = $this->brand()->shades('#336699');
        $this->assertSame($a, $this->brand()->shades('#336699'));
        $this->assertSame('#336699', $a['600']);
        $this->assertSame(11, count($a));
    }

    public function testCustomCssRules(): void
    {
        foreach (['@import url(x.css);', 'a{background:url(https://evil.example/x)}', 'a{background:url(//evil.example/x)}', 'a{width:expression(1)}', 'a{background:url(javascript:1)}', '</style><script>'] as $bad) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => $this->brand()->save(['custom_css' => $bad], $this->admin), $bad);
        }
        $this->brand()->save(['custom_css' => '.card{border-width:2px}'], $this->admin);
        $this->assertStringContains('.card{border-width:2px}', $this->brand()->themeCss());
    }

    public function testPlainBackgroundHidesBlobs(): void
    {
        $this->brand()->save(['bg' => 'plain'], $this->admin);
        $this->assertStringContains('.app-bg::before', $this->brand()->themeCss());
        $this->assertStringContains('display:none', $this->brand()->themeCss());
    }

    // ------------------------------------------------------------------ Öffentliche Marken-Dateien

    public function testBrandRoutesAreSessionlessAndCacheable(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/brand/theme.css');
        $this->assertSame(200, $r->status);
        $this->assertStringContains('text/css', $r->headers['Content-Type']);
        $this->assertTrue(!isset($r->headers['Set-Cookie']));
        $this->assertSame(0, (int) $this->ctx->db->one('SELECT COUNT(*) c FROM sessions')['c']);
        $etag = $r->headers['ETag'];
        $r2 = $b->get('/brand/theme.css', [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertSame(304, $r2->status);
        $v = $this->brand()->get('version');
        $this->assertStringContains('immutable', $b->get('/brand/theme.css', ['v' => $v])->headers['Cache-Control']);
        $this->assertSame(404, $b->get('/brand/unknown')->status);
        $fav = $b->get('/brand/favicon');
        $this->assertSame('image/svg+xml', $fav->headers['Content-Type']);
        $this->assertSame(404, $b->get('/brand/logo')->status);
    }

    // ------------------------------------------------------------------ Uploads

    public function testSniffAcceptsAndRejects(): void
    {
        $this->assertSame('png', BrandingService::sniff($this->png(), false)[0]);
        $this->assertSame('svg', BrandingService::sniff('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>', false)[0]);
        $this->assertSame('ico', BrandingService::sniff("\x00\x00\x01\x00" . str_repeat("\x01", 40), true)[0]);
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>',
            '<html><body>hi</body></html>', 'GIF89a' . str_repeat('x', 40), '<?php echo 1; ?>' . str_repeat('x', 40),
        ] as $bad) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => BrandingService::sniff($bad, false), substr($bad, 0, 30));
        }
        $this->assertThrows(\InvalidArgumentException::class, fn () => BrandingService::sniff("\x00\x00\x01\x00" . str_repeat("\x01", 40), false), 'ICO nur für Favicon');
        $this->assertThrows(\InvalidArgumentException::class, fn () => BrandingService::sniff($this->png(8, 8), false), 'zu klein');
    }

    public function testUploadLogoServedWithSandboxHeaders(): void
    {
        $b = $this->login('admin@example.org');
        $b->files = ['logo' => ['name' => 'evil.php.png', 'size' => 100, 'bytes' => $this->png()]];
        $r = $b->post('/admin/branding', $this->form());
        $this->assertSame(302, $r->status);
        $this->assertTrue(is_file($this->dir . '/logo.png'), 'Datei liegt außerhalb des Webroots unter festem Namen');
        $served = (new TestBrowser($this->ctx->app))->get('/brand/logo');
        $this->assertSame(200, $served->status);
        $this->assertSame('image/png', $served->headers['Content-Type']);
        $this->assertStringContains('sandbox', $served->headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $served->headers['X-Content-Type-Options']);
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('class="brand-logo"', $html);
        // dunkles Logo fällt auf das helle zurück, bis ein eigenes hochgeladen wird
        $this->assertSame(200, (new TestBrowser($this->ctx->app))->get('/brand/logo-dark')->status);
        $this->assertStringNotContains('logo-dark', $html);
    }

    public function testSeparateDarkLogoAndRemove(): void
    {
        $b = $this->login('admin@example.org');
        $b->files = ['logo' => ['name' => 'a.png', 'size' => 1, 'bytes' => $this->png()], 'logo_dark' => ['name' => 'b.png', 'size' => 1, 'bytes' => $this->png(40, 40)]];
        $b->post('/admin/branding', $this->form());
        $html = (new TestBrowser($this->ctx->app))->get('/login')->body;
        $this->assertStringContains('logo-light', $html);
        $this->assertStringContains('logo-dark', $html);
        $b->post('/admin/branding', $this->form(['remove_logo' => '1', 'remove_logo_dark' => '1']));
        $this->assertFalse(is_file($this->dir . '/logo.png'));
        $this->assertSame(404, (new TestBrowser($this->ctx->app))->get('/brand/logo')->status);
    }

    public function testBadUploadIsRefusedButTextsStay(): void
    {
        $b = $this->login('admin@example.org');
        $b->files = ['logo' => ['name' => 'x.svg', 'size' => 50, 'bytes' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>']];
        $b->post('/admin/branding', $this->form());
        $this->assertSame([], glob($this->dir . '/logo.*') ?: []);
        $this->assertSame('Acme ID', $this->brand()->get('name'));
        $this->assertStringContains('Gespeichert, aber', $b->get('/admin/branding')->body);
    }

    public function testOversizeUploadMarkerIsRefused(): void
    {
        $b = $this->login('admin@example.org');
        $b->files = ['favicon' => ['name' => 'big.png', 'size' => 5000000, 'bytes' => '']];
        $b->post('/admin/branding', $this->form());
        $this->assertStringContains('zu groß', $b->get('/admin/branding')->body);
    }

    // ------------------------------------------------------------------ Berechtigungen

    public function testOnlySuperadminMayEdit(): void
    {
        $this->assertSame(302, (new TestBrowser($this->ctx->app))->get('/admin/branding')->status);
        $mod = $this->login('mod@example.org');
        $this->assertSame(403, $mod->get('/admin/branding')->status);
        $this->assertSame(403, $mod->post('/admin/branding', $this->form(['name' => 'Gekapert']))->status);
        $this->assertSame(403, $mod->post('/admin/branding/reset')->status);
        $this->assertSame('Auth', $this->brand()->get('name'));
        $user = $this->ctx->app->users->create('u@example.org', $this->ctx->app->passwords->hash(self::PW), 'U', true);
        $this->assertSame(403, $this->login('u@example.org')->get('/admin/branding')->status);
        $this->assertSame(200, $this->login('admin@example.org')->get('/admin/branding')->status);
    }

    public function testCsrfRequired(): void
    {
        $b = $this->login('admin@example.org');
        $this->assertSame(403, $b->post('/admin/branding', $this->form(['name' => 'X']), false)->status);
        $this->assertSame('Auth', $this->brand()->get('name'));
    }

    public function testAdminPageRendersAndEscapesValues(): void
    {
        $b = $this->login('admin@example.org');
        $this->brand()->save(['tagline' => '"><script>alert(1)</script>'], $this->admin);
        $html = $b->get('/admin/branding')->body;
        $this->assertStringNotContains('<script>alert(1)', $html);
        $this->assertStringContains('enctype="multipart/form-data"', $html);
    }

    public function testResetRestoresDefaultsAndRemovesFiles(): void
    {
        $this->brand()->save($this->form(['primary' => 'rose']), $this->admin);
        $this->brand()->setAsset('logo', $this->png(), $this->admin);
        $b = $this->login('admin@example.org');
        $b->post('/admin/branding/reset');
        $this->assertSame('Auth', $this->brand()->get('name'));
        $this->assertSame('indigo', $this->brand()->get('primary'));
        $this->assertSame([], glob($this->dir . '/logo.*') ?: []);
    }

    public function testChangesAreAudited(): void
    {
        $this->brand()->save($this->form(), $this->admin);
        $row = $this->ctx->db->one("SELECT COUNT(*) c FROM audit_log WHERE event = 'branding.updated'");
        $this->assertSame(1, (int) $row['c']);
    }

    public function testVersionChangesBustCache(): void
    {
        $v1 = $this->brand()->get('version');
        $this->brand()->save($this->form(), $this->admin);
        $this->assertTrue($v1 !== $this->brand()->get('version'));
        $this->assertStringContains('?v=' . $this->brand()->get('version'), (new TestBrowser($this->ctx->app))->get('/login')->body);
    }

    // ------------------------------------------------------------------ E-Mail

    public function testMailPlaceholdersAndFooter(): void
    {
        $this->brand()->save($this->form(['mail_footer' => 'Hilfe: {org} Support']), $this->admin);
        $this->ctx->app->mailer->queue('x@example.org', 'Willkommen bei {app}', "Hallo\n\nEin {org}-Konto.");
        $row = $this->ctx->db->one('SELECT subject, body_text FROM mail_queue ORDER BY id DESC LIMIT 1');
        $this->assertSame('Willkommen bei Acme ID', $row['subject']);
        $this->assertStringContains('Ein Acme AG-Konto.', $row['body_text']);
        $this->assertStringContains("-- \nHilfe: Acme AG Support", $row['body_text']);
    }

    public function testRuntimeOverridesForMailAndPasskeyName(): void
    {
        $this->brand()->save($this->form(['mail_from_name' => 'Acme Team']), $this->admin);
        $again = new \Vylaris\Core\App($this->ctx->config, $this->ctx->db, $this->ctx->mail, dirname(__DIR__));
        $this->assertSame('Acme Team', $again->config->get('mail.from_name'));
        $this->assertSame('Acme ID', $again->config->get('webauthn.rp_name'));
    }
}
