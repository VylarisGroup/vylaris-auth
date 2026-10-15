<?php
declare(strict_types=1);

/**
 * VylarisAuth – Hosting-Check
 * Aufruf: php bin/hosting-check.php   (oder im Browser über setup.php)
 * Exit-Code 0 = alle Pflichtprüfungen bestanden.
 */

function vylaris_hosting_checks(?string $rootDir = null): array
{
    $rootDir ??= dirname(__DIR__);
    $checks = [];
    $add = function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks): void {
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'required' => $required];
    };

    $add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);

    foreach (['pdo_mysql', 'openssl', 'sodium', 'mbstring', 'json', 'ctype', 'hash'] as $ext) {
        $add("Erweiterung $ext", extension_loaded($ext), extension_loaded($ext) ? 'vorhanden' : 'fehlt');
    }
    foreach (['curl', 'zip', 'intl'] as $ext) {
        $add("Erweiterung $ext (empfohlen)", extension_loaded($ext), extension_loaded($ext) ? 'vorhanden' : 'fehlt', false);
    }

    $argon = defined('PASSWORD_ARGON2ID');
    $add('Argon2id', $argon, $argon ? 'verfügbar' : 'nicht verfügbar – Fallback bcrypt', false);

    $add('HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || PHP_SAPI === 'cli',
        PHP_SAPI === 'cli' ? 'CLI: nicht prüfbar' : 'Verbindung ist ' . (!empty($_SERVER['HTTPS']) ? 'verschlüsselt' : 'unverschlüsselt'));

    $varDir = $rootDir . '/var';
    $writable = is_dir($varDir) && is_writable($varDir);
    $add('var/ beschreibbar', $writable, $varDir);

    $cfgPrivate = !is_file($rootDir . '/public/config.php');
    $add('Keine config.php im Webroot', $cfgPrivate, $cfgPrivate ? 'ok' : 'config.php darf nicht in public/ liegen');

    $outbound = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    $add('Ausgehendes HTTPS (nur für Social Login)', $outbound, $outbound ? (function_exists('curl_init') ? 'cURL vorhanden' : 'allow_url_fopen aktiv') : 'weder cURL noch allow_url_fopen – Social Login nicht nutzbar', false);

    $add('random_bytes verfügbar', function_exists('random_bytes'), 'ok');
    $add('open_basedir/Funktionen', true, 'ini open_basedir=' . (ini_get('open_basedir') ?: '(leer)'), false);

    return $checks;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $failed = 0;
    foreach (vylaris_hosting_checks() as $c) {
        $status = $c['ok'] ? 'OK  ' : ($c['required'] ? 'FAIL' : 'WARN');
        if (!$c['ok'] && $c['required']) {
            $failed++;
        }
        printf("[%s] %-38s %s\n", $status, $c['name'], $c['detail']);
    }
    echo $failed === 0 ? "\nAlle Pflichtprüfungen bestanden.\n" : "\n$failed Pflichtprüfung(en) fehlgeschlagen.\n";
    exit($failed === 0 ? 0 : 1);
}
