<?php
declare(strict_types=1);

/**
 * VylarisAuth – Ersteinrichtung. Sperrt sich nach erfolgreicher Installation selbst
 * (var/setup.lock bzw. vorhandene config/config.php). Zusätzlich ist ein vorab
 * hinterlegtes Setup-Token (config/setup.token) nötig. Nach der Installation setup.php löschen.
 */
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use Vylaris\Setup\Installer;

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $body) use ($e): void {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VylarisAuth Setup</title>'
        . '<style>body{font:16px system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#0f172a}label{display:block;margin:.6rem 0 .1rem;font-size:.9rem}'
        . 'input,select{width:100%;padding:.5rem;border:1px solid #94a3b8;border-radius:.4rem;box-sizing:border-box}button{margin-top:1rem;padding:.6rem 1rem;background:#4f46e5;color:#fff;border:0;border-radius:.4rem}'
        . '.err{background:#fef2f2;color:#991b1b;padding:.5rem 1rem;border-radius:.4rem}.ok{background:#ecfdf5;color:#065f46;padding:.5rem 1rem;border-radius:.4rem}code{background:#f1f5f9;padding:0 .25rem}h2{margin-top:1.6rem;font-size:1.1rem}</style>'
        . '</head><body><h1>VylarisAuth – Einrichtung</h1>' . $body . '</body></html>';
};

$inst = new Installer($root);

if ($inst->isLocked()) {
    http_response_code(403);
    $page('<p class="ok">Die Installation ist abgeschlossen und gesperrt. Bitte löschen Sie <code>public/setup.php</code> vom Server.</p>');
    exit;
}
if (!$inst->tokenConfigured()) {
    http_response_code(403);
    $page('<p class="err">Setup-Token fehlt.</p><p>Legen Sie auf dem Server die Datei <code>config/setup.token</code> an (außerhalb des Webroots) '
        . 'mit einem zufälligen Wert von mindestens 24 Zeichen, z. B. <code>' . $e(bin2hex(random_bytes(16))) . '</code>, und laden Sie diese Seite neu.</p>');
    exit;
}

require_once $root . '/bin/hosting-check.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_name('vylaris_setup');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$errors = [];
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = array_map(static fn ($v) => is_string($v) ? $v : '', $_POST);
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if (!hash_equals($_SESSION['csrf'], $in['csrf'] ?? '')) {
        $errors[] = 'Ungültiges Formular-Token, bitte erneut versuchen.';
    } elseif ($_SESSION['tries'] > 10) {
        $errors[] = 'Zu viele Versuche. Browser-Sitzung schließen und neu starten.';
    } elseif (!$inst->tokenValid($in['token'] ?? '')) {
        usleep(500000);
        $errors[] = 'Setup-Token ist falsch.';
    } else {
        $errors = $inst->install($in);
        if (!$errors) {
            session_destroy();
            $page('<p class="ok">Installation abgeschlossen.</p><ol><li>Löschen Sie <code>public/setup.php</code>.</li><li>Richten Sie den Cron-Job <code>php bin/cron.php</code> ein (siehe docs/PLESK.md).</li>'
                . '<li>Melden Sie sich unter <a href="/login">/login</a> an und richten Sie sofort TOTP oder einen Passkey ein – der Admin-Bereich verlangt MFA.</li></ol>');
            exit;
        }
    }
}

$fail = array_filter(vylaris_hosting_checks($root), static fn ($c) => !$c['ok'] && $c['required']);
$body = '';
foreach ($errors as $m) {
    $body .= '<p class="err" role="alert">' . $e($m) . '</p>';
}
if ($fail) {
    $body .= '<h2>Hosting-Prüfung</h2><ul>';
    foreach ($fail as $c) {
        $body .= '<li class="err">' . $e($c['name']) . ': ' . $e($c['detail']) . '</li>';
    }
    $body .= '</ul>';
}
$f = static fn (string $n, string $label, string $type = 'text', string $def = '') => '<label>' . $label . '<input name="' . $n . '" type="' . $type . '" value="' . $e($type === 'password' ? '' : ($in[$n] ?? $def)) . '"' . ($type === 'password' ? ' autocomplete="new-password"' : '') . '></label>';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$body .= '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . $e($_SESSION['csrf']) . '">'
    . $f('token', 'Setup-Token (Inhalt von config/setup.token)', 'password')
    . '<h2>Dienst</h2>' . $f('app_name', 'Name der Anwendung (später im Admin-Bereich unter „Branding“ änderbar)', 'text', 'Auth') . $f('org_name', 'Organisation', 'text', '') . $f('base_url', 'Basis-URL (Issuer)', 'text', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'auth.example.org'))
    . '<label><input type="checkbox" name="allow_registration" value="1" style="width:auto"> Öffentliche Registrierung erlauben</label>'
    . '<h2>Datenbank (MySQL/MariaDB)</h2>' . $f('db_host', 'Host', 'text', 'localhost') . $f('db_port', 'Port', 'text', '3306') . $f('db_name', 'Datenbankname') . $f('db_user', 'Benutzer') . $f('db_password', 'Passwort', 'password')
    . '<h2>E-Mail</h2><p style="font-size:.85rem">SMTP-Host leer lassen, um den lokalen Mailversand des Hosters (PHP mail()) zu nutzen.</p>' . $f('mail_host', 'SMTP-Host (empfohlen)') . $f('mail_port', 'Port', 'text', '587')
    . '<label>Verschlüsselung<select name="mail_encryption"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">keine</option></select></label>'
    . $f('mail_user', 'Benutzer') . $f('mail_password', 'Passwort', 'password') . $f('mail_from', 'Absenderadresse', 'text', 'no-reply@example.org')
    . '<h2>Lizenz</h2><p style="font-size:.85rem">Der öffentliche Schlüssel des Lizenzservers ist vorbelegt; nur ändern, wenn Sie einen anderen Lizenzserver verwenden (später: license.public_key in config/config.php).</p>'
    . $f('license_api_url', 'Adresse des Lizenzservers', 'text', 'https://licence.vylaris.ch')
    . '<label>Öffentlicher Schlüssel (PEM)<textarea name="license_public_key" rows="5" style="width:100%;box-sizing:border-box;font-family:monospace">' . $e($in['license_public_key'] ?? trim(\Vylaris\License\LicenseService::DEFAULT_PUBLIC_KEY)) . '</textarea></label>'
    . '<h2>Automatische Updates (GitHub)</h2><p style="font-size:.85rem">Optional. Neue Versionen werden aus den Releases dieses Repositorys geholt; installiert wird per Klick im Admin-Bereich.</p>'
    . $f('update_repo', 'GitHub-Repository (besitzer/name, leer = keine Updates)', 'text', \Vylaris\Update\GithubSource::DEFAULT_REPO)
    . '<label><input type="checkbox" name="update_private" value="1" style="width:auto"' . (($in['update_private'] ?? '') === '1' ? ' checked' : '') . '> Repository ist privat (Token nötig)</label>'
    . $f('update_token', 'GitHub-Token (nur bei privatem Repository; Fine-grained, Contents: Read-only)', 'password')
    . '<h2>Erster Administrator (Superadmin)</h2>' . $f('admin_name', 'Name') . $f('admin_email', 'E-Mail', 'email') . $f('admin_password', 'Passwort (mind. 12 Zeichen)', 'password') . $f('admin_password2', 'Passwort wiederholen', 'password')
    . '<button type="submit">Installieren</button></form>';
$page($body);
