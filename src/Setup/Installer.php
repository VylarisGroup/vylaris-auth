<?php
declare(strict_types=1);

namespace Vylaris\Setup;

use Vylaris\Core\{Config, Database, Migrator};
use Vylaris\Oidc\KeyService;
use Vylaris\Repository\UserRepository;
use Vylaris\Service\PasswordService;

/** Erstinstallation: Konfiguration schreiben, Schema migrieren, ersten Superadmin und Signaturschlüssel anlegen. */
final class Installer
{
    /** $allowSqlite ausschließlich für Tests; Produktion verwendet MySQL/MariaDB. */
    public function __construct(private string $root, private bool $allowSqlite = false)
    {
    }

    public function configFile(): string
    {
        return $this->root . '/config/config.php';
    }

    public function tokenFile(): string
    {
        return $this->root . '/config/setup.token';
    }

    public function lockFile(): string
    {
        return $this->root . '/var/setup.lock';
    }

    public function isLocked(): bool
    {
        return is_file($this->lockFile()) || is_file($this->configFile());
    }

    /** Das Setup-Token muss vom Betreiber vorab als Datei hinterlegt werden (schützt vor Übernahme durch Dritte). */
    public function tokenConfigured(): bool
    {
        return is_file($this->tokenFile()) && strlen(trim((string) file_get_contents($this->tokenFile()))) >= 24;
    }

    public function tokenValid(string $given): bool
    {
        return $this->tokenConfigured() && hash_equals(trim((string) file_get_contents($this->tokenFile())), trim($given));
    }

    /**
     * @param array<string,string> $in Formulareingaben
     * @return string[] Fehlermeldungen (leer = Erfolg)
     */
    public function install(array $in): array
    {
        if ($this->isLocked()) {
            return ['Die Installation ist bereits abgeschlossen.'];
        }
        $errors = [];
        $appName = trim(preg_replace('/\s+/u', ' ', (string) ($in['app_name'] ?? '')) ?? '');
        $appName = ($appName === '' || mb_strlen($appName) > 60 || preg_match('/[<>"]/', $appName)) ? 'Auth' : $appName;
        $orgName = trim(preg_replace('/\s+/u', ' ', (string) ($in['org_name'] ?? '')) ?? '');
        $orgName = ($orgName === '' || mb_strlen($orgName) > 80 || preg_match('/[<>"]/', $orgName)) ? $appName : $orgName;
        $baseUrl = rtrim(trim($in['base_url'] ?? ''), '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!preg_match('#^https://[^/\s]+$#', $baseUrl) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $baseUrl)) {
            $errors[] = 'Die Basis-URL muss mit https:// beginnen und darf keinen Pfad enthalten.';
        }
        $email = strtolower(trim($in['admin_email'] ?? ''));
        $name = trim($in['admin_name'] ?? '');
        $pw = (string) ($in['admin_password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige Administrator-E-Mail angeben.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Bitte einen Namen für den Administrator angeben.';
        }
        if (mb_strlen($pw) < 12) {
            $errors[] = 'Das Administrator-Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if ($pw !== (string) ($in['admin_password2'] ?? '')) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        $repo = trim((string) ($in['update_repo'] ?? ''));
        $repo = preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $repo) ?? $repo;
        $repo = preg_replace('#(\.git)?/?$#', '', $repo) ?? $repo;
        $token = trim((string) ($in['update_token'] ?? ''));
        if ($repo !== '' && !\Vylaris\Update\GithubSource::validRepo($repo)) {
            $errors[] = 'Das GitHub-Repository muss die Form besitzer/name haben.';
        }
        if (($in['update_private'] ?? '') === '1' && $repo !== '' && !preg_match('/^[A-Za-z0-9_]{20,255}$/', $token)) {
            $errors[] = 'Für ein privates Repository wird ein GitHub-Token benötigt (Fine-grained Token mit Berechtigung „Contents: Read-only“).';
        }
        if (($in['update_private'] ?? '') !== '1') {
            $token = ''; // öffentliches Repository: kein Token speichern
        }
        if (!is_dir($this->root . '/var') || !is_writable($this->root . '/var') || !is_writable($this->root . '/config')) {
            $errors[] = 'Die Verzeichnisse var/ und config/ müssen für PHP beschreibbar sein.';
        }
        if ($errors) {
            return $errors;
        }

        $driver = $this->allowSqlite && ($in['db_driver'] ?? '') === 'sqlite' ? 'sqlite' : 'mysql';
        $cfg = [
            'app' => ['name' => $appName, 'org' => $orgName, 'env' => 'production', 'base_url' => $baseUrl, 'timezone' => 'Europe/Zurich', 'secret' => base64_encode(random_bytes(32))],
            'db' => $driver === 'sqlite'
                ? ['driver' => 'sqlite', 'path' => $this->root . '/var/auth.sqlite']
                : ['driver' => 'mysql', 'host' => trim($in['db_host'] ?? 'localhost'), 'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
                   'name' => trim($in['db_name'] ?? ''), 'user' => trim($in['db_user'] ?? ''), 'password' => (string) ($in['db_password'] ?? ''), 'charset' => 'utf8mb4'],
            'mail' => [
                'host' => trim($in['mail_host'] ?? ''), 'port' => (int) ($in['mail_port'] ?? 587) ?: 587,
                'encryption' => in_array($in['mail_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $in['mail_encryption'] : 'tls',
                'username' => trim($in['mail_user'] ?? ''), 'password' => (string) ($in['mail_password'] ?? ''),
                'from_email' => trim($in['mail_from'] ?? '') ?: 'no-reply@' . ($host ?: 'localhost'), 'from_name' => $appName,
            ],
            'session' => ['cookie_name' => '__Host-vsid', 'idle_timeout' => 1800, 'absolute_ttl' => 86400],
            'security' => [
                'login_max_attempts_ip' => 20, 'login_max_attempts_account' => 5, 'login_window_seconds' => 900,
                'password_min_length' => 12, 'allow_registration' => ($in['allow_registration'] ?? '') === '1',
                'require_email_verification' => true, 'admin_requires_mfa' => true, 'trusted_proxies' => [],
            ],
            'oidc' => [
                'private_key_dir' => $this->root . '/var/keys', 'require_nonce' => true,
                'access_token_ttl' => 600, 'id_token_ttl' => 600, 'refresh_token_ttl' => 2592000, 'auth_code_ttl' => 60,
            ],
            'license' => ['required' => true, 'grace_days' => 7, 'refresh_seconds' => 3600, 'keyless_features' => 'all'],
            'update' => ['repo' => $repo, 'token' => $token, 'check_hours' => 12],
            'webauthn' => ['rp_name' => $appName, 'rp_id' => (string) $host],
            'paths' => ['log_dir' => $this->root . '/var/log'],
        ];

        $config = new Config($cfg);
        try {
            $db = Database::fromConfig($config);
            (new Migrator($db, $this->root . '/migrations'))->migrate();
        } catch (\Throwable $e) {
            error_log('VylarisAuth Setup DB: ' . $e->getMessage());
            return ['Datenbankverbindung oder Migration fehlgeschlagen. Zugangsdaten und Rechte prüfen (Details im PHP-Fehlerlog).'];
        }
        if ((int) $db->scalar('SELECT COUNT(*) FROM users') > 0) {
            return ['Die Datenbank enthält bereits Benutzer. Verwenden Sie eine leere Datenbank oder bin/create-admin.php.'];
        }

        $users = new UserRepository($db);
        $id = $users->create($email, (new PasswordService(12))->hash($pw), $name, true);
        $users->assignRole($id, 'superadmin');
        $users->assignRole($id, 'user');
        @mkdir($cfg['oidc']['private_key_dir'], 0700, true);
        (new KeyService($db, $config))->ensureActiveKey();

        $php = "<?php\n// Von setup.php erzeugt. NICHT ins Git einchecken, nicht in public/ ablegen.\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = $this->configFile() . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $this->configFile())) {
            return ['config/config.php konnte nicht geschrieben werden.'];
        }
        @chmod($this->configFile(), 0640);
        file_put_contents($this->lockFile(), 'Installiert am ' . gmdate('c') . "\n");
        @unlink($this->tokenFile());
        return [];
    }
}
