<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Core\{App, Config, Database, Migrator, Clock};
use Vylaris\Repository\UserRepository;
use Vylaris\Service\{ArrayMailTransport, AuditLogger, AuthService, Mailer, PasswordService, RateLimiter, SessionService, TokenService};

/** Baut Testumgebung: SQLite in-memory + Migrationen + Testkonfiguration. */
final class Factory
{
    public static function config(array $override = []): Config
    {
        $base = [
            'app' => ['base_url' => 'https://auth.test', 'secret' => base64_encode(str_repeat('k', 32)), 'env' => 'development'],
            'db' => getenv('VYLARIS_TEST_MYSQL')
                ? ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) (getenv('VYLARIS_TEST_MYSQL_PORT') ?: 3306), 'name' => getenv('VYLARIS_TEST_MYSQL_DB') ?: 'vauth', 'user' => getenv('VYLARIS_TEST_MYSQL_USER') ?: 'vauth', 'password' => getenv('VYLARIS_TEST_MYSQL_PASS') ?: '']
                : ['driver' => 'sqlite', 'path' => ':memory:'], // VYLARIS_TEST_MYSQL=1: Tests laufen gegen echte MySQL/MariaDB (leert die Test-DB!)
            'session' => ['idle_timeout' => 1800, 'absolute_ttl' => 86400, 'cookie_name' => 'vsid'],
            'security' => [
                'login_max_attempts_ip' => 20, 'login_max_attempts_account' => 5, 'login_window_seconds' => 900,
                'password_min_length' => 12, 'allow_registration' => true, 'require_email_verification' => true, 'admin_requires_mfa' => false,
            ],
            'webauthn' => ['rp_name' => 'Test', 'rp_id' => 'auth.test'],
            'update' => ['repo' => ''], // Tests sprechen nie mit GitHub
            'license' => ['required' => false], // LicenseTest schaltet die Pflicht gezielt ein
            'oidc' => ['access_token_ttl' => 600, 'id_token_ttl' => 600, 'refresh_token_ttl' => 2592000, 'auth_code_ttl' => 60],
        ];
        return new Config(array_replace_recursive($base, $override));
    }

    public static function db(?Config $config = null): Database
    {
        Clock::fix(1_800_000_000);
        $db = Database::fromConfig($config ?? self::config());
        if ($db->driver() === 'mysql') {
            $db->run('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->all('SHOW TABLES') as $row) {
                $db->run('DROP TABLE `' . current($row) . '`');
            }
            $db->run('SET FOREIGN_KEY_CHECKS = 1');
        }
        (new Migrator($db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        return $db;
    }

    /** Vollständiger Service-Graph für Auth-Tests. */
    public static function auth(array $configOverride = []): object
    {
        $config = self::config($configOverride);
        $db = self::db($config);
        $mail = new ArrayMailTransport();
        $users = new UserRepository($db);
        $passwords = new PasswordService(12);
        $tokens = new TokenService($db);
        $limiter = new RateLimiter($db, 'testsalt');
        $mailer = new Mailer($db, $mail);
        $audit = new AuditLogger($db);
        $sessions = new SessionService($db);
        $auth = new AuthService($db, $config, $users, $passwords, $tokens, $limiter, $mailer, $audit, $sessions);
        return (object) compact('config', 'db', 'mail', 'users', 'passwords', 'tokens', 'limiter', 'mailer', 'audit', 'sessions', 'auth');
    }

    /** Legt einen verifizierten Benutzer an. */
    public static function user(object $s, string $email = 'anna@example.org', string $password = 'Correct-Horse-Battery-9', string $name = 'Anna Muster'): int
    {
        return $s->users->create($email, $s->passwords->hash($password), $name, true);
    }

    /** Vollständige App (alle registrierten Module) mit Mail-Array-Transport. */
    public static function app(array $configOverride = [], ?\Vylaris\Social\HttpClient $http = null, ?\Vylaris\Update\UpdateSource $update = null): object
    {
        $config = self::config($configOverride);
        $db = self::db($config);
        $mail = new ArrayMailTransport();
        $app = new App($config, $db, $mail, dirname(__DIR__, 2), $http, $update);
        \Vylaris\Core\Bootstrap::registerModules($app);
        return (object) compact('app', 'config', 'db', 'mail');
    }
}
