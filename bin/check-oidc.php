<?php
declare(strict_types=1);

/**
 * Prüft die öffentliche OIDC-Erreichbarkeit – so, wie ein Client sie sieht.
 * Aufruf: php bin/check-oidc.php [https://auth.example.org]   (ohne Argument: base_url aus der Config)
 * Zeigt HTTP-Status, Content-Type, JSON-Gültigkeit, issuer-Abgleich und JWKS.
 */
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$base = $argv[1] ?? null;
if ($base === null) {
    $cfg = \Vylaris\Core\Config::load(\Vylaris\Core\Bootstrap::configPath($root));
    $base = $cfg->baseUrl();
}
$base = rtrim($base, '/');
$ok = true;
$say = static function (bool $good, string $msg) use (&$ok): void {
    $ok = $ok && $good;
    echo ($good ? '[ OK ] ' : '[FEHL] ') . $msg . "\n";
};

$fetch = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['error' => curl_error($ch)];
    }
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};

$r = $fetch($base . '/.well-known/openid-configuration');
if (isset($r['error'])) {
    $say(false, 'Abruf fehlgeschlagen: ' . $r['error'] . ' (Server kann sich ggf. nicht selbst per HTTPS erreichen – dann von einem anderen Rechner prüfen: curl -i ' . $base . '/.well-known/openid-configuration)');
    exit(1);
}
$say($r['status'] === 200, 'Discovery: HTTP ' . $r['status'] . ($r['status'] >= 300 && $r['status'] < 400 ? ' (Weiterleitung! Clients folgen nicht immer – URL/HTTPS prüfen)' : ''));
$say((bool) preg_match('#^content-type:\s*application/json#mi', $r['headers']), 'Content-Type ist application/json');
$doc = json_decode(ltrim($r['body'], "\xEF\xBB\xBF"), true);
$say(is_array($doc), 'Antwort ist gültiges JSON' . (is_array($doc) ? '' : ' – Anfang der Antwort: ' . substr(trim(strip_tags($r['body'])), 0, 160)));
if (is_array($doc)) {
    $say(($doc['issuer'] ?? '') === $base, 'issuer "' . ($doc['issuer'] ?? '') . '" stimmt mit "' . $base . '" überein (muss exakt gleich sein, ohne Schrägstrich am Ende)');
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $k) {
        $say(isset($doc[$k]) && str_starts_with((string) $doc[$k], $base . '/'), "$k zeigt auf dieselbe Domain");
    }
    $j = $fetch((string) ($doc['jwks_uri'] ?? $base . '/oidc/jwks'));
    $jd = isset($j['body']) ? json_decode($j['body'], true) : null;
    $say(($j['status'] ?? 0) === 200 && !empty($jd['keys']), 'JWKS erreichbar und enthält Schlüssel');
}

// ---------------------------------------------------------------- Lokale Prüfung (nur wenn die Config lesbar ist)
echo "\n-- Server-interne Prüfung --\n";
try {
    $say(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . ' (mind. 8.2)');
    foreach (['openssl', 'sodium', 'mbstring', 'curl', 'json'] as $ext) {
        $say(extension_loaded($ext), "PHP-Erweiterung $ext");
    }
    $app = \Vylaris\Core\Bootstrap::app($root);
    $say(true, 'Config gelesen, Datenbank verbunden (' . $app->db->driver() . ')');
    $done = array_map('intval', array_column($app->db->all('SELECT version FROM schema_migrations'), 'version'));
    $files = glob($root . '/migrations/[0-9][0-9][0-9]_*.php') ?: [];
    $pending = [];
    foreach ($files as $f) {
        if (!in_array((int) substr(basename($f), 0, 3), $done, true)) {
            $pending[] = basename($f);
        }
    }
    $say($pending === [], $pending ? 'Offene Migrationen: ' . implode(', ', $pending) . ' → php bin/migrate.php' : 'Alle Migrationen angewendet');
    $key = $app->keys->ensureActiveKey();
    $pem = $app->keys->privatePem($key);
    $sig = '';
    $signed = openssl_sign('probe', $sig, $pem, OPENSSL_ALGO_SHA256);
    $pub = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'] ?? '';
    $say($signed && openssl_verify('probe', $sig, $pub, OPENSSL_ALGO_SHA256) === 1, 'Signaturschlüssel ' . $key['kid'] . ': Signieren und Prüfen funktioniert');
    $logDir = (string) $app->config->get('paths.log_dir', $root . '/var/log');
    @mkdir($logDir, 0750, true);
    $say(is_dir($logDir) && is_writable($logDir), "Log-Verzeichnis $logDir beschreibbar (Fehler stehen in error-*.log)");
    $latest = glob($logDir . '/error-*.log') ?: [];
    if ($latest) {
        rsort($latest);
        echo "\nLetzte Fehlerlog-Zeilen (" . basename($latest[0]) . "):\n" . implode('', array_slice(file($latest[0]) ?: [], -8)) . "\n";
    }
} catch (\Throwable $e) {
    $say(false, 'Interner Fehler: ' . get_class($e) . ': ' . $e->getMessage());
}
exit($ok ? 0 : 1);
