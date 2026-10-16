<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};
use Vylaris\Update\Changelog;

/** Änderungsprotokoll: Parser, einmalige Anzeige je Administrator im Admin-Bereich. */
final class ChangelogTest extends TestCase
{
    private const PW = 'Correct-Horse-Battery-9';

    private function tmpRoot(string $md): string
    {
        $d = sys_get_temp_dir() . '/vy-cl-' . bin2hex(random_bytes(4));
        mkdir($d);
        file_put_contents($d . '/CHANGELOG.md', $md);
        return $d;
    }

    public function testParsesEntriesNewestFirstAndIgnoresGarbage(): void
    {
        $ctx = Factory::app();
        $c = new Changelog($this->tmpRoot("# Titel\n\n## 1.0.0 – 2026-01-01\n- alt\n\n## v1.10.0 - 2026-03-01\n- neu A\n* neu B\n\n## kaputt\n- x\n\n## 1.2.0\n- mitte\n"), $ctx->db);
        $e = $c->entries();
        $this->assertSame(['1.10.0', '1.2.0', '1.0.0'], array_column($e, 'version'));
        $this->assertSame(['neu A', 'neu B'], $e[0]['items']);
        $this->assertSame('2026-03-01', $e[0]['date']);
        $this->assertSame([], (new Changelog('/nonexistent', $ctx->db))->entries());
    }

    public function testUnseenFirstShowsOnlyCurrentThenOnlyNewerVersions(): void
    {
        $ctx = Factory::app();
        $c = new Changelog($this->tmpRoot("## 1.3.0\n- c\n## 1.2.0\n- b\n## 1.1.0\n- a\n## 2.0.0\n- zukunft\n"), $ctx->db);
        $this->assertSame(['1.3.0'], array_column($c->unseen(7, '1.3.0'), 'version'), 'neu: nur aktuelle Version, nie Zukünftiges');
        $c->markSeen(7, '1.1.0');
        $this->assertSame(['1.3.0', '1.2.0'], array_column($c->unseen(7, '1.3.0'), 'version'));
        $c->markSeen(7, '1.3.0');
        $this->assertSame([], $c->unseen(7, '1.3.0'));
        $this->assertSame(['1.3.0'], array_column($c->unseen(8, '1.3.0'), 'version'), 'andere Nutzer unabhängig');
    }

    public function testAdminSeesChangelogOnceAfterLogin(): void
    {
        $ctx = Factory::app();
        $a = $ctx->app;
        foreach (['a1@example.org', 'a2@example.org'] as $mail) {
            $id = $a->users->create($mail, $a->passwords->hash(self::PW), 'Admin', true);
            $a->users->assignRole($id, 'admin');
        }
        $ver = $a->updater->currentVersion();
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'a1@example.org', 'password' => self::PW]);
        $page = $b->get('/admin')->body;
        $this->assertStringContains('Neu in Version ' . $ver, $page);
        $this->assertStringContains('Verstanden', $page);
        $this->assertSame(302, $b->post('/admin/changelog/seen')->status);
        $this->assertStringNotContains('Neu in Version', $b->get('/admin')->body, 'nach „Verstanden“ nicht mehr');
        // Zweiter Administrator hat es noch nicht gesehen.
        $b2 = new TestBrowser($a);
        $b2->post('/login', ['email' => 'a2@example.org', 'password' => self::PW]);
        $this->assertStringContains('Neu in Version', $b2->get('/admin')->body);
        // Ohne CSRF-Token und ohne Anmeldung nicht möglich.
        $this->assertSame(403, $b2->post('/admin/changelog/seen', [], false)->status);
        $this->assertSame(302, (new TestBrowser($a))->post('/admin/changelog/seen')->status);
    }

    public function testProjectChangelogHasEntryForInstalledVersion(): void
    {
        $ctx = Factory::app();
        $ver = $ctx->app->updater->currentVersion();
        $this->assertTrue(in_array($ver, array_column($ctx->app->changelog->entries(), 'version'), true), 'CHANGELOG.md braucht einen Eintrag für ' . $ver);
    }
}
