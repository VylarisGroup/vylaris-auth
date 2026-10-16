<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Service\Maintenance;
use Vylaris\Tests\Support\{Factory, FakeUpdateSource, TestBrowser, TestCase};
use Vylaris\Update\{GithubSource, UpdateException, Updater};

/** Aktualisierung aus GitHub-Releases: Prüfung, Austausch, Rückstellung, Archiv-Sicherheit. */
final class UpdateTest extends TestCase
{
    private string $root;
    private FakeUpdateSource $src;
    private Updater $up;
    /** @var string[] */
    private array $migrated = [];
    private ?\Throwable $migrationError = null;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        Clock::fix(1_800_000_000);
        $this->root = sys_get_temp_dir() . '/vylaris-upd-' . bin2hex(random_bytes(4));
        $files = [
            'VERSION' => '1.0.0', 'src/autoload.php' => "<?php // alt\n", 'src/Old.php' => "<?php // wird entfernt\n", 'src/Keep.php' => "<?php // alt\n",
            'public/index.php' => "<?php // alt\n", 'public/assets/old.css' => 'alt', 'migrations/001_x.php' => "<?php return [];\n",
            'README.md' => 'alt', 'custom.txt' => 'eigene Datei', 'config/config.php' => '<?php return ["secret"=>"GEHEIM"];', 'config/setup.token' => 'tok',
            'var/keys/a.pem' => 'PRIVATE', 'var/branding/logo.png' => 'PNG', 'tests/OldTest.php' => "<?php // alt\n",
        ];
        foreach ($files as $p => $c) {
            @mkdir(dirname($this->root . '/' . $p), 0777, true);
            file_put_contents($this->root . '/' . $p, $c);
        }
        $this->src = new FakeUpdateSource();
        $this->src->files = $this->release();
        $this->migrated = [];
        $this->migrationError = null;
        $this->up = $this->updater();
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && str_starts_with($this->root, sys_get_temp_dir())) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
        $this->cleanRealUpdateDir();
    }

    private function updater(): Updater
    {
        return new Updater($this->root, $this->src, 12, function (): array {
            if ($this->migrationError) {
                throw $this->migrationError;
            }
            $this->migrated[] = 'ran';
            return ['002_neu'];
        });
    }

    /** @return array<string,string> */
    private function release(string $version = '1.1.0'): array
    {
        return [
            'VERSION' => $version, 'src/autoload.php' => "<?php // neu\n", 'src/Keep.php' => "<?php // neu\n", 'src/New.php' => "<?php // neu\n",
            'public/index.php' => "<?php // neu\n", 'public/assets/new.css' => 'neu', 'migrations/001_x.php' => "<?php return [];\n", 'migrations/002_neu.php' => "<?php return [];\n",
            'README.md' => 'neu', 'NEU.txt' => 'neue Datei',
            // dürfen NIE übernommen werden:
            'config/config.php' => '<?php return ["secret"=>"EVIL"];', 'config/setup.token' => 'evil', 'var/keys/evil.pem' => 'EVIL', '.github/workflows/x.yml' => 'x',
        ];
    }

    private function read(string $rel): ?string
    {
        $c = @file_get_contents($this->root . '/' . $rel);
        return $c === false ? null : $c;
    }

    private function untouched(): void
    {
        $this->assertSame('1.0.0', $this->read('VERSION'));
        $this->assertSame("<?php // wird entfernt\n", $this->read('src/Old.php'));
        $this->assertSame("<?php // alt\n", $this->read('src/Keep.php'));
        $this->assertNull($this->read('src/New.php'));
        $this->assertFalse($this->up->locked());
        $this->assertNull($this->up->pending());
    }

    // ------------------------------------------------------------------ Prüfen

    public function testCheckStoresLatestAndDetectsNewerVersion(): void
    {
        $this->assertSame('1.0.0', $this->up->currentVersion());
        $this->assertNull($this->up->updateAvailable());
        $rel = $this->up->check();
        $this->assertSame('1.1.0', $rel['version']);
        $this->assertSame('1.1.0', $this->up->updateAvailable()['version']);
        $this->src->tag = 'v1.0.0';
        $this->up->check();
        $this->assertNull($this->up->updateAvailable(), 'gleiche Version = kein Update');
        $this->src->tag = 'v0.9.0';
        $this->up->check();
        $this->assertNull($this->up->updateAvailable(), 'ältere Version = kein Update');
    }

    public function testCheckFailureIsRecordedAndInvalidTagRejected(): void
    {
        $this->src->fail = true;
        $this->assertThrows(UpdateException::class, fn () => $this->up->check());
        $this->assertFalse($this->up->latest()['ok']);
        $this->assertNull($this->up->updateAvailable());
        $this->src->fail = false;
        $this->src->tag = 'release-2026-10';
        $e = $this->assertThrows(UpdateException::class, fn () => $this->up->check());
        $this->assertStringContains('Versionsnummer', $e->getMessage());
    }

    public function testCronCheckIsThrottledAndNeverThrows(): void
    {
        $this->assertTrue($this->up->checkIfDue());
        $this->assertFalse($this->up->checkIfDue(), 'innerhalb des Intervalls nicht erneut');
        Clock::advance(13 * 3600);
        $this->src->fail = true;
        $this->assertTrue($this->up->checkIfDue(), 'Fehler werden gespeichert, nicht geworfen');
        $this->assertFalse($this->up->latest()['ok']);
        $none = new Updater($this->root, null, 12, fn () => []);
        $this->assertFalse($none->checkIfDue());
        $this->assertFalse($none->configured());
    }

    // ------------------------------------------------------------------ Installieren

    public function testInstallSwapsFilesAndProtectsConfigAndVar(): void
    {
        $r = $this->up->install('1.1.0');
        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0'], $r);
        $this->assertSame('1.1.0', $this->read('VERSION'));
        $this->assertSame("<?php // neu\n", $this->read('src/Keep.php'));
        $this->assertSame("<?php // neu\n", $this->read('src/New.php'));
        $this->assertNull($this->read('src/Old.php'), 'veraltete Datei im gespiegelten Ordner entfernt');
        $this->assertNull($this->read('public/assets/old.css'));
        $this->assertSame('neu', $this->read('public/assets/new.css'));
        $this->assertSame('neu', $this->read('README.md'));
        $this->assertSame('neue Datei', $this->read('NEU.txt'));
        $this->assertSame('eigene Datei', $this->read('custom.txt'), 'fremde Dateien bleiben');
        $this->assertSame("<?php // alt\n", $this->read('tests/OldTest.php'), 'Ordner, die im Release fehlen, bleiben unberührt');
        // geschützt:
        $this->assertSame('<?php return ["secret"=>"GEHEIM"];', $this->read('config/config.php'));
        $this->assertSame('tok', $this->read('config/setup.token'));
        $this->assertSame('PRIVATE', $this->read('var/keys/a.pem'));
        $this->assertSame('PNG', $this->read('var/branding/logo.png'));
        $this->assertNull($this->read('var/keys/evil.pem'));
        $this->assertNull($this->read('.github/workflows/x.yml'));
        // Wartungsmodus bis zum Abschluss
        $this->assertTrue($this->up->locked());
        $this->assertSame('applied', $this->up->pending()['status']);
        $this->assertSame(0, count($this->migrated), 'Migrationen laufen erst in finish() (neuer Code)');
        $this->assertSame(1, count($this->up->backups()));
    }

    public function testFinishRunsMigrationsAndLeavesMaintenance(): void
    {
        $this->up->install('1.1.0');
        $r = $this->up->finish();
        $this->assertSame(['002_neu'], $r['migrations']);
        $this->assertSame(['ran'], $this->migrated);
        $this->assertFalse($this->up->locked());
        $this->assertNull($this->up->pending());
        $last = $this->up->last();
        $this->assertSame('done', $last['status']);
        $this->assertSame('1.1.0', $last['to']);
        $this->assertNull($this->up->updateAvailable());
        $this->assertThrows(UpdateException::class, fn () => $this->up->finish(), 'ohne offenes Update nichts abzuschließen');
    }

    public function testMigrationFailureRestoresOldFiles(): void
    {
        $this->up->install('1.1.0');
        $this->migrationError = new \RuntimeException('SQLSTATE[42S01]: Tabelle existiert bereits');
        $e = $this->assertThrows(UpdateException::class, fn () => $this->up->finish());
        $this->assertStringContains('zurückgestellt', $e->getMessage());
        $this->untouched();
        $this->assertNull($this->read('NEU.txt'), 'neu hinzugekommene Datei wieder entfernt');
        $this->assertNull($this->read('public/assets/new.css'));
        $this->assertSame('alt', $this->read('public/assets/old.css'));
        $this->assertSame('alt', $this->read('README.md'));
        $this->assertSame('eigene Datei', $this->read('custom.txt'));
        $this->assertSame('PRIVATE', $this->read('var/keys/a.pem'));
        $this->assertSame('rolled_back', $this->up->last()['status']);
    }

    public function testManualRollbackRestoresPreviousVersion(): void
    {
        $this->up->install('1.1.0');
        $this->up->finish();
        $name = $this->up->backups()[0]['file'];
        $v = $this->up->rollback($name);
        $this->assertSame('1.0.0', $v);
        $this->untouched();
        $this->assertNull($this->read('NEU.txt'));
        $this->assertThrows(UpdateException::class, fn () => $this->up->rollback('../../etc/passwd'));
        $this->assertThrows(UpdateException::class, fn () => $this->up->rollback('update-x.zip'));
    }

    public function testInstallRefusesWhenNotNewerOrOtherVersionThanSeen(): void
    {
        $this->src->tag = 'v1.0.0';
        $this->src->files = $this->release('1.0.0');
        $e = $this->assertThrows(UpdateException::class, fn () => $this->up->install());
        $this->assertStringContains('aktuell', $e->getMessage());
        $this->src->tag = 'v1.2.0';
        $this->src->files = $this->release('1.2.0');
        $e = $this->assertThrows(UpdateException::class, fn () => $this->up->install('1.1.0'));
        $this->assertStringContains('1.2.0', $e->getMessage());
        $this->assertSame(0, $this->src->downloads, 'nichts heruntergeladen');
        $this->untouched();
    }

    public function testSecondInstallBlockedWhileUpdateIsPending(): void
    {
        $this->up->install('1.1.0');
        $this->src->tag = 'v1.2.0';
        $e = $this->assertThrows(UpdateException::class, fn () => $this->up->install());
        $this->assertStringContains('unvollständig', $e->getMessage());
    }

    public function testOldBackupsArePruned(): void
    {
        @mkdir($this->root . '/var/backups', 0777, true);
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->root . "/var/backups/update-0.$i.0-to-0." . ($i + 1) . '.0-x.zip', 'x');
            touch($this->root . "/var/backups/update-0.$i.0-to-0." . ($i + 1) . '.0-x.zip', time() - 1000 * (10 - $i));
        }
        $this->up->install('1.1.0');
        $this->assertSame(3, count($this->up->backups()));
    }

    // ------------------------------------------------------------------ Archiv-Sicherheit

    /** @dataProvider-frei: jede Variante muss abgelehnt werden und darf nichts verändern */
    public function testMaliciousOrBrokenArchivesAreRejectedWithoutChanges(): void
    {
        $cases = [
            'Pfad mit ..' => fn () => $this->src->rawEntries = ['../evil.php' => '<?php'],
            'tief verschachtelt ..' => fn () => $this->src->rawEntries = [$this->src->top . '/src/../../evil.php' => '<?php'],
            'absoluter Pfad' => fn () => $this->src->rawEntries = ['/tmp/evil.php' => '<?php'],
            'Backslash' => fn () => $this->src->rawEntries = [$this->src->top . '\\..\\evil.php' => '<?php'],
            'Symlink' => fn () => $this->src->symlinks = ['src/link' => '/etc/passwd'],
            'Syntaxfehler' => fn () => $this->src->files['src/Broken.php'] = "<?php\nfunction (((",
            'VERSION passt nicht zum Tag' => fn () => $this->src->files['VERSION'] = '9.9.9',
            'autoload fehlt' => function () { unset($this->src->files['src/autoload.php']); },
            'migrations fehlt' => function () { unset($this->src->files['migrations/001_x.php'], $this->src->files['migrations/002_neu.php']); },
        ];
        foreach ($cases as $label => $mutate) {
            $this->src->files = $this->release();
            $this->src->rawEntries = [];
            $this->src->symlinks = [];
            $mutate();
            $threw = false;
            try {
                $this->updater()->install('1.1.0');
            } catch (UpdateException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'abgelehnt: ' . $label);
            $this->untouched();
            $this->assertNull($this->read('../evil.php'), $label);
            $this->assertFalse(is_file(dirname($this->root) . '/evil.php'), $label);
            $this->assertFalse(is_dir($this->root . '/var/update/work'), 'Arbeitsordner aufgeräumt: ' . $label);
        }
    }

    public function testDirectoryLayoutWithoutTopFolderAlsoWorks(): void
    {
        // Ohne Top-Ordner: Einträge direkt im Wurzelverzeichnis
        $src = new class($this->release()) implements \Vylaris\Update\UpdateSource {
            public function __construct(private array $files)
            {
            }

            public function latestRelease(): array
            {
                return ['tag' => 'v1.1.0', 'version' => '1.1.0', 'name' => '', 'notes' => '', 'zip_url' => 'https://api.github.com/x', 'published_at' => '', 'html_url' => ''];
            }

            public function download(string $zipUrl, string $dest, int $maxBytes): void
            {
                $zip = new \ZipArchive();
                $zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                foreach ($this->files as $p => $c) {
                    $zip->addFromString($p, $c);
                }
                $zip->close();
            }
        };
        $up = new Updater($this->root, $src, 12, fn () => []);
        $up->install();
        $this->assertSame('1.1.0', $this->read('VERSION'));
    }

    public function testDownloadFailureLeavesSystemUntouched(): void
    {
        $this->src->fail = true;
        $this->assertThrows(UpdateException::class, fn () => $this->up->install());
        $this->untouched();
    }

    // ------------------------------------------------------------------ Hilfen

    public function testDefaultRepositoryAppliesOnlyWhenConfigKeyIsMissing(): void
    {
        $cfg = \Vylaris\Tests\Support\Factory::config();
        $this->assertTrue(GithubSource::validRepo(GithubSource::DEFAULT_REPO));
        $db = \Vylaris\Tests\Support\Factory::db($cfg);
        $make = fn (array $c) => new \Vylaris\Core\App(\Vylaris\Tests\Support\Factory::config($c), $db, new \Vylaris\Service\ArrayMailTransport(), dirname(__DIR__));
        $this->assertFalse($make(['update' => ['repo' => '']])->updater->configured(), 'leer = aus');
        $this->assertFalse($make(['update' => ['repo' => 'kaputt']])->updater->configured(), 'ungültig = aus');
        $this->assertTrue($make(['update' => ['repo' => 'a/b']])->updater->configured());
    }

    public function testRepoNameValidation(): void
    {
        foreach (['vylaris/auth', 'Vylaris-Group/auth.v2', 'a/b'] as $ok) {
            $this->assertTrue(GithubSource::validRepo($ok), $ok);
        }
        foreach (['', 'nurname', 'a/b/c', '../x', 'a/..', 'a b/c', 'https://github.com/a/b', '-a/b', 'a/b;rm'] as $bad) {
            $this->assertFalse(GithubSource::validRepo($bad), $bad);
        }
        $this->assertThrows(UpdateException::class, fn () => new GithubSource('böse', 'tok'));
    }

    // ------------------------------------------------------------------ Web / Wartungsmodus

    private function cleanRealUpdateDir(): void
    {
        $dir = dirname(__DIR__) . '/var/update';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                is_file($f) ? @unlink($f) : null;
            }
            @rmdir($dir);
        }
    }

    private function webCtx(): object
    {
        $this->cleanRealUpdateDir();
        $src = new FakeUpdateSource();
        $src->tag = 'v9.9.9';
        $src->files = $this->release('9.9.9');
        $ctx = Factory::app([], null, $src);
        $a = $ctx->app;
        $mk = fn (string $mail, string $role) => (function () use ($a, $mail, $role) {
            $id = $a->users->create($mail, $a->passwords->hash(self::PW), 'P ' . $role, true);
            $a->users->assignRole($id, $role);
            return $id;
        })();
        $mk('super@example.org', 'superadmin');
        $mk('admin@example.org', 'admin');
        $mk('user@example.org', 'user');
        $ctx->src = $src;
        return $ctx;
    }

    private function login(object $ctx, string $email): TestBrowser
    {
        $b = new TestBrowser($ctx->app);
        $b->post('/login', ['email' => $email, 'password' => self::PW]);
        return $b;
    }

    public function testOnlySuperadminsMayUpdate(): void
    {
        $ctx = $this->webCtx();
        foreach (['admin@example.org', 'user@example.org'] as $mail) {
            $b = $this->login($ctx, $mail);
            $this->assertSame(403, $b->get('/admin/update')->status, $mail);
            $this->assertSame(403, $b->post('/admin/update/install', ['version' => '9.9.9'])->status, $mail);
            $this->assertSame(403, $b->post('/admin/update/rollback')->status, $mail);
            $this->assertSame(403, $b->get('/admin/update/finish')->status, $mail);
        }
        $anon = new TestBrowser($ctx->app);
        $this->assertSame(302, $anon->get('/admin/update')->status);
        $this->assertSame(302, $anon->post('/admin/update/install', ['version' => '9.9.9'])->status);
    }

    public function testSuperadminSeesVersionChecksAndGetsDashboardHint(): void
    {
        $ctx = $this->webCtx();
        $b = $this->login($ctx, 'super@example.org');
        $page = $b->get('/admin/update')->body;
        $this->assertStringContains('1.5.0', $page);
        $this->assertStringNotContains('Auf Version 9.9.9', $page, 'vor der Prüfung kein Update-Knopf');
        $r = $b->post('/admin/update/check');
        $this->assertSame(302, $r->status);
        $page = $b->get('/admin/update')->body;
        $this->assertStringContains('Version 9.9.9 verfügbar', $page);
        $this->assertStringContains('Neue Funktionen', $page);
        $this->assertStringContains('Datenbanksicherung', $page);
        $this->assertStringContains('Version 9.9.9 ist verfügbar', $b->get('/admin')->body);
        // Admin (kein Superadmin) sieht den Hinweis nicht
        $a = $this->login($ctx, 'admin@example.org');
        $this->assertStringNotContains('ist verfügbar', $a->get('/admin')->body);
    }

    public function testNotesAreEscaped(): void
    {
        $ctx = $this->webCtx();
        $ctx->src->notes = '<script>alert(1)</script> **fett**';
        $b = $this->login($ctx, 'super@example.org');
        $b->post('/admin/update/check');
        $page = $b->get('/admin/update')->body;
        $this->assertStringNotContains('<script>alert(1)</script>', $page);
        $this->assertStringContains('&lt;script&gt;', $page);
    }

    public function testInstallPostNeedsCsrfAndFakeNeverTouchesRealRoot(): void
    {
        $ctx = $this->webCtx();
        $b = $this->login($ctx, 'super@example.org');
        $this->assertSame(403, $b->post('/admin/update/install', ['version' => '9.9.9'], false)->status, 'ohne CSRF-Token abgelehnt');
        $this->assertSame(0, $ctx->src->downloads);
        $this->assertSame('1.5.0', trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')));
    }

    public function testMaintenanceModeBlocksEverythingExceptUpdateScreens(): void
    {
        $ctx = $this->webCtx();
        $b = $this->login($ctx, 'super@example.org');
        $this->assertSame(200, $b->get('/account')->status);
        @mkdir(dirname(__DIR__) . '/var/update', 0750, true);
        file_put_contents(dirname(__DIR__) . '/var/update/maintenance.lock', '{}');
        $anon = new TestBrowser($ctx->app);
        $r = $anon->get('/login');
        $this->assertSame(503, $r->status);
        $this->assertSame('120', $r->headers['Retry-After']);
        $this->assertSame(503, $anon->get('/oidc/jwks')->status);
        $this->assertSame(503, $anon->get('/.well-known/openid-configuration')->status);
        $this->assertSame(503, $b->get('/account')->status);
        $this->assertTrue($b->get('/admin/update')->status !== 503, 'Update-Seiten bleiben erreichbar');
        unlink(dirname(__DIR__) . '/var/update/maintenance.lock');
        $this->assertSame(200, $anon->get('/login')->status);
    }

    public function testCronSkipsCheckWithoutRepoAndRunsWithSource(): void
    {
        $ctx = $this->webCtx();
        $a = $ctx->app;
        $m = new Maintenance($a->db, $a->mailer, $a->sessions, $a->tokens, $a->limiter, $a->audit, $a->keys, 365, $a->privacy, 30, $a->license, $a->updater);
        $this->assertSame(1, $m->run()['update_checked']);
        $this->assertSame(0, $m->run()['update_checked'], 'gedrosselt');
        $this->assertSame('9.9.9', $a->updater->updateAvailable()['version']);
    }
}
