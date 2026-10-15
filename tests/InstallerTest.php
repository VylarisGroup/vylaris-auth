<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Setup\Installer;
use Vylaris\Tests\Support\TestCase;

/** Setup: Token-Schutz, Validierung, Installation und Selbstsperre. */
final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vylaris-setup-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0700, true);
        mkdir($this->root . '/var', 0700, true);
        symlink(dirname(__DIR__) . '/migrations', $this->root . '/migrations');
    }

    public function __destruct()
    {
        if (isset($this->root) && str_starts_with($this->root, sys_get_temp_dir())) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    private function input(array $o = []): array
    {
        return $o + ['base_url' => 'https://auth.example.org', 'db_driver' => 'sqlite', 'admin_email' => 'Root@Example.org', 'admin_name' => 'Root Admin',
            'admin_password' => 'Sehr-Langes-Passwort-42', 'admin_password2' => 'Sehr-Langes-Passwort-42', 'mail_host' => 'smtp.example.org'];
    }

    public function testTokenRequiredAndComparedStrictly(): void
    {
        $i = new Installer($this->root, true);
        $this->assertFalse($i->tokenConfigured());
        $this->assertFalse($i->tokenValid(''));
        file_put_contents($this->root . '/config/setup.token', 'kurz');
        $this->assertFalse($i->tokenConfigured(), 'zu kurzes Token wird nicht akzeptiert');
        $tok = bin2hex(random_bytes(16));
        file_put_contents($this->root . '/config/setup.token', $tok . "\n");
        $this->assertTrue($i->tokenValid($tok));
        $this->assertFalse($i->tokenValid($tok . 'x'));
        $this->assertFalse($i->tokenValid(''));
    }

    public function testValidationErrors(): void
    {
        $i = new Installer($this->root, true);
        $errs = $i->install($this->input(['base_url' => 'http://evil.example', 'admin_password' => 'kurz', 'admin_password2' => 'anders', 'admin_email' => 'kaputt']));
        $this->assertTrue(count($errs) >= 3);
        $this->assertFalse(is_file($this->root . '/config/config.php'));
        $this->assertFalse($i->isLocked());
    }

    public function testInstallCreatesConfigAdminKeysAndLocks(): void
    {
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $this->assertSame([], $i->install($this->input()));
        $this->assertTrue($i->isLocked());
        $this->assertFalse(is_file($this->root . '/config/setup.token'), 'Token wird gelöscht');
        $cfg = require $this->root . '/config/config.php';
        $this->assertSame('https://auth.example.org', $cfg['app']['base_url']);
        $this->assertSame('auth.example.org', $cfg['webauthn']['rp_id']);
        $this->assertTrue(strlen(base64_decode($cfg['app']['secret'])) === 32);
        $this->assertTrue($cfg['security']['admin_requires_mfa']);

        $db = \Vylaris\Core\Database::fromConfig(new \Vylaris\Core\Config($cfg));
        $u = $db->one('SELECT * FROM users WHERE email = ?', ['root@example.org']);
        $this->assertNotNull($u);
        $this->assertTrue(password_verify('Sehr-Langes-Passwort-42', $u['password_hash']));
        $users = new \Vylaris\Repository\UserRepository($db);
        $this->assertTrue(in_array('superadmin', $users->roleNames((int) $u['id']), true));
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM oidc_keys WHERE status = 'active'"));
        $this->assertTrue(count(glob($this->root . '/var/keys/*') ?: []) >= 1);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->root . '/config/config.php')), -4));
    }

    public function testLicenseSettingsAreWrittenAndValidated(): void
    {
        $srv = new \Vylaris\Tests\Support\FakeLicenseServer();
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $errs = $i->install($this->input(['license_public_key' => 'kein-schluessel!']));
        $this->assertStringContains('Lizenz-Schlüssel', implode(' ', $errs));
        $errs = $i->install($this->input(['license_api_url' => 'http://evil.example/x', 'license_public_key' => $srv->publicKeyPem]));
        $this->assertStringContains('Lizenzservers', implode(' ', $errs));
        $this->assertFalse($i->isLocked());
        $this->assertSame([], $i->install($this->input(['license_public_key' => $srv->publicKeyPem])));
        $cfg = require $this->root . '/config/config.php';
        $this->assertTrue($cfg['license']['required']);
        $this->assertSame('https://licence.vylaris.ch', $cfg['license']['api_url']);
        $this->assertSame(trim($srv->publicKeyPem), $cfg["license"]["public_key"]);
    }

    public function testDefaultLicenseKeyIsUsedWhenFieldIsEmpty(): void
    {
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $this->assertSame([], $i->install($this->input()));
        $cfg = require $this->root . '/config/config.php';
        $this->assertSame(trim(\Vylaris\License\LicenseService::DEFAULT_PUBLIC_KEY), $cfg['license']['public_key']);
    }

    public function testUpdateSettingsAreValidatedAndNormalized(): void
    {
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $this->assertStringContains('besitzer/name', implode(' ', $i->install($this->input(['update_repo' => 'kein repo']))));
        $this->assertStringContains('Token', implode(' ', $i->install($this->input(['update_repo' => 'vylaris/auth', 'update_private' => '1', 'update_token' => 'kurz']))));
        $this->assertFalse($i->isLocked());
        $tok = 'github_pat_' . str_repeat('A1b2', 10);
        $this->assertSame([], $i->install($this->input(['update_repo' => 'https://github.com/Vylaris/auth.git', 'update_private' => '1', 'update_token' => $tok])));
        $cfg = require $this->root . '/config/config.php';
        $this->assertSame('Vylaris/auth', $cfg['update']['repo']);
        $this->assertSame($tok, $cfg['update']['token']);
    }

    public function testPublicRepoDoesNotStoreToken(): void
    {
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $this->assertSame([], $i->install($this->input(['update_repo' => 'vylaris/auth', 'update_token' => 'github_pat_' . str_repeat('x', 30)])));
        $cfg = require $this->root . '/config/config.php';
        $this->assertSame('', $cfg['update']['token']);
    }

    public function testNoRepoMeansUpdatesOff(): void
    {
        $i = new Installer($this->root, true);
        file_put_contents($this->root . '/config/setup.token', bin2hex(random_bytes(16)));
        $this->assertSame([], $i->install($this->input()));
        $cfg = require $this->root . '/config/config.php';
        $this->assertSame('', $cfg['update']['repo']);
    }

    public function testSecondInstallIsRefused(): void
    {
        $i = new Installer($this->root, true);
        $this->assertSame([], $i->install($this->input()));
        $errs = $i->install($this->input(['admin_email' => 'zweiter@example.org']));
        $this->assertSame(1, count($errs));
        $this->assertStringContains('abgeschlossen', $errs[0]);
    }

    public function testSqliteIgnoredInProduction(): void
    {
        $i = new Installer($this->root, false);
        $errs = $i->install($this->input(['db_name' => 'x', 'db_user' => 'x', 'db_host' => '127.0.0.1', 'db_path' => '/etc/passwd']));
        $this->assertSame(1, count($errs), 'MySQL-Verbindung scheitert generisch');
        $this->assertFalse(is_file($this->root . '/config/config.php'));
    }
}
