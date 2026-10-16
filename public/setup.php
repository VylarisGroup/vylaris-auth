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
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $body) use ($e): void {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>VylarisAuth Setup</title>'
        . '<style>:root{--bg:#f1f5f9;--card:#fff;--fg:#0f172a;--mut:#64748b;--line:#cbd5e1;--pri:#4f46e5}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#0b1020;--card:#151b2e;--fg:#e2e8f0;--mut:#94a3b8;--line:#2b3550;--pri:#818cf8}}'
        . '*{box-sizing:border-box}body{font:16px/1.5 system-ui,sans-serif;margin:0;background:var(--bg);color:var(--fg);padding:2rem 1rem}'
        . 'main{max-width:38rem;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:1rem;padding:1.5rem 1.5rem 1.75rem;box-shadow:0 10px 30px rgba(0,0,0,.08)}'
        . 'h1{font-size:1.4rem;margin:0 0 .25rem}h2{font-size:1.1rem;margin:0 0 .25rem}.lead,.hint{color:var(--mut);font-size:.9rem;margin:.1rem 0 .8rem}'
        . 'fieldset{border:0;margin:0;padding:0;min-width:0}label{display:block;margin:.8rem 0 .15rem;font-size:.9rem;font-weight:500}'
        . 'input:not([type=checkbox]),select{width:100%;padding:.6rem .7rem;border:1px solid var(--line);border-radius:.5rem;background:transparent;color:inherit;font:inherit}'
        . 'input:focus,select:focus{outline:2px solid var(--pri);outline-offset:1px}select option{color:#0f172a}'
        . '.check{display:flex;gap:.5rem;align-items:center;font-weight:400;margin-top:1rem}.check input{width:auto}'
        . 'button{padding:.65rem 1.1rem;border-radius:.5rem;border:1px solid var(--pri);background:var(--pri);color:#fff;font:inherit;cursor:pointer}button.sec{background:transparent;color:var(--fg);border-color:var(--line)}'
        . '.nav{display:flex;justify-content:space-between;gap:.5rem;margin-top:1.5rem}.nav .sp{flex:1}'
        . '.err{background:#fef2f2;color:#991b1b;padding:.5rem 1rem;border-radius:.5rem;margin:.5rem 0}.ok{background:#ecfdf5;color:#065f46;padding:.5rem 1rem;border-radius:.5rem}'
        . 'code{background:rgba(100,116,139,.18);padding:0 .3rem;border-radius:.25rem}'
        . '.steps{display:flex;gap:.35rem;list-style:none;padding:0;margin:1rem 0 1.25rem}.steps li{flex:1;text-align:center;font-size:.72rem;color:var(--mut);border-top:3px solid var(--line);padding-top:.35rem}'
        . '.steps li.done,.steps li.cur{border-color:var(--pri)}.steps li.cur{color:var(--fg);font-weight:600}'
        . '[hidden]{display:none !important}</style>'
        . '</head><body><main><h1>VylarisAuth – Einrichtung</h1>' . $body . '</main><script src="/assets/setup.js" defer></script></body></html>';
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
$f = static function (string $n, string $label, string $type = 'text', string $def = '', string $attr = '') use ($e, $in): string {
    $auto = $type === 'password' ? ' autocomplete="new-password"' : '';
    return '<label>' . $label . '<input name="' . $n . '" type="' . $type . '" value="' . $e($type === 'password' ? '' : ($in[$n] ?? $def)) . '"' . $auto . ($attr !== '' ? ' ' . $attr : '') . '></label>';
};
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$sel = static fn (string $v): string => ($in['mail_encryption'] ?? 'tls') === $v ? ' selected' : '';
$start = 1;
foreach ($errors as $m) {
    $start = str_contains($m, 'Setup-Token') ? 1 : 5;
}
$body .= '<p class="lead">In wenigen Schritten ist alles eingerichtet.</p>'
    . '<form method="post" autocomplete="off" data-setup data-start="' . $start . '"><input type="hidden" name="csrf" value="' . $e($_SESSION['csrf']) . '">'
    . '<fieldset data-step data-title="Zugang"><h2>Zugang</h2><p class="hint">Der Inhalt der Datei <code>config/setup.token</code> beweist, dass Sie Zugriff auf den Server haben.</p>'
    . $f('token', 'Setup-Token', 'password', '', 'required') . '</fieldset>'
    . '<fieldset data-step data-title="Dienst"><h2>Dienst</h2>'
    . $f('app_name', 'Name der Anwendung (später unter „Branding“ änderbar)', 'text', 'Auth', 'required') . $f('org_name', 'Organisation')
    . $f('base_url', 'Basis-URL (Issuer, exakt wie von außen erreichbar)', 'text', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'auth.example.org'), 'required')
    . '<label class="check"><input type="checkbox" name="allow_registration" value="1"' . (($in['allow_registration'] ?? '') === '1' ? ' checked' : '') . '> Öffentliche Registrierung erlauben</label></fieldset>'
    . '<fieldset data-step data-title="Datenbank"><h2>Datenbank</h2><p class="hint">MySQL oder MariaDB – in Plesk unter „Datenbanken“ angelegt.</p>'
    . $f('db_host', 'Host', 'text', 'localhost', 'required') . $f('db_port', 'Port', 'text', '3306') . $f('db_name', 'Datenbankname', 'text', '', 'required') . $f('db_user', 'Benutzer', 'text', '', 'required') . $f('db_password', 'Passwort', 'password') . '</fieldset>'
    . '<fieldset data-step data-title="E-Mail"><h2>E-Mail</h2><p class="hint">SMTP-Host leer lassen, um den Mailversand des Hosters (PHP mail()) zu nutzen.</p>'
    . $f('mail_host', 'SMTP-Host (empfohlen)') . $f('mail_port', 'Port', 'text', '587')
    . '<label>Verschlüsselung<select name="mail_encryption"><option value="tls"' . $sel('tls') . '>STARTTLS (587)</option><option value="ssl"' . $sel('ssl') . '>SSL/TLS (465)</option><option value="none"' . $sel('none') . '>keine</option></select></label>'
    . $f('mail_user', 'Benutzer') . $f('mail_password', 'Passwort', 'password') . $f('mail_from', 'Absenderadresse', 'text', 'no-reply@example.org') . '</fieldset>'
    . '<fieldset data-step data-title="Administrator"><h2>Erster Administrator</h2><p class="hint">Dieses Konto erhält alle Rechte (Superadmin). Der Admin-Bereich verlangt danach MFA.</p>'
    . $f('admin_name', 'Name', 'text', '', 'required') . $f('admin_email', 'E-Mail', 'email', '', 'required')
    . $f('admin_password', 'Passwort (mind. 12 Zeichen)', 'password', '', 'required minlength="12"') . $f('admin_password2', 'Passwort wiederholen', 'password', '', 'required minlength="12"') . '</fieldset>'
    . '<div class="nav"><button type="button" class="sec" data-prev hidden>Zurück</button><span class="sp"></span><button type="button" data-next hidden>Weiter</button><button type="submit" data-submit>Installieren</button></div></form>';
$page($body);
