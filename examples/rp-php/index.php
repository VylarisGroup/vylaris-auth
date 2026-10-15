<?php
declare(strict_types=1);

/**
 * Beispiel-Anwendung (Relying Party) für VylarisAuth. Zwei unabhängige Instanzen starten:
 *
 *   OIDC_ISSUER=https://auth.vylaris.ch OIDC_CLIENT_ID=vy_... OIDC_CLIENT_SECRET=... \
 *   APP_NAME="App A" REDIRECT_URI=http://localhost:9001/callback php -S localhost:9001 examples/rp-php/index.php
 *   ... dasselbe mit anderer Client-ID/Port für "App B" (localhost:9002).
 *
 * (Für lokale Tests bei VylarisAuth eine http://localhost-Redirect-URI in einer Test-Umgebung registrieren;
 * in Produktion sind ausschließlich https-URIs erlaubt.)
 */
require __DIR__ . '/lib.php';

session_name('rp_' . substr(hash('sha256', getenv('OIDC_CLIENT_ID') ?: 'x'), 0, 8));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$issuer = rtrim((string) getenv('OIDC_ISSUER'), '/');
$clientId = (string) getenv('OIDC_CLIENT_ID');
$secret = (string) getenv('OIDC_CLIENT_SECRET');
$redirect = (string) getenv('REDIRECT_URI');
$appName = getenv('APP_NAME') ?: 'Beispiel-App';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

function discovery(string $issuer): array
{
    [$st, $doc] = http_json('GET', $issuer . '/.well-known/openid-configuration');
    if ($st !== 200 || ($doc['issuer'] ?? '') !== $issuer) {   // Issuer des Dokuments muss exakt passen
        throw new RuntimeException('Discovery fehlgeschlagen');
    }
    return $doc;
}

if ($path === '/login') {
    $d = discovery($issuer);
    [$verifier, $challenge] = pkce_pair();
    $_SESSION['oidc'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 'verifier' => $verifier];
    header('Location: ' . $d['authorization_endpoint'] . '?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'openid profile email roles',
        'state' => $_SESSION['oidc']['state'], 'nonce' => $_SESSION['oidc']['nonce'],
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986));
    exit;
}

if ($path === '/callback') {
    $s = $_SESSION['oidc'] ?? null;
    unset($_SESSION['oidc']);                                   // einmalig verwenden
    try {
        if ($s === null || isset($_GET['error'])) {
            throw new RuntimeException('Anmeldung abgebrochen: ' . ($_GET['error'] ?? 'keine laufende Anmeldung'));
        }
        if (!hash_equals($s['state'], (string) ($_GET['state'] ?? ''))) {
            throw new RuntimeException('State ungültig (CSRF)');
        }
        if (isset($_GET['iss']) && $_GET['iss'] !== $issuer) {  // RFC 9207
            throw new RuntimeException('Issuer-Parameter ungültig');
        }
        $d = discovery($issuer);
        [$st, $tok] = http_json('POST', $d['token_endpoint'], [
            'form' => ['grant_type' => 'authorization_code', 'code' => (string) ($_GET['code'] ?? ''), 'redirect_uri' => $redirect, 'code_verifier' => $s['verifier']],
            'userpwd' => rawurlencode($clientId) . ':' . rawurlencode($secret),
            'headers' => ['Accept: application/json'],
        ]);
        if ($st !== 200 || empty($tok['id_token'])) {
            throw new RuntimeException('Token-Austausch fehlgeschlagen');
        }
        [, $jwks] = http_json('GET', $d['jwks_uri']);
        $claims = verify_id_token($tok['id_token'], $jwks, $issuer, $clientId, $s['nonce']);
        session_regenerate_id(true);
        $_SESSION['user'] = ['sub' => $claims['sub'], 'name' => $claims['name'] ?? '', 'email' => $claims['email'] ?? '', 'roles' => $claims['roles'] ?? []];
        $_SESSION['id_token'] = $tok['id_token'];
        header('Location: /');
    } catch (Throwable $e) {
        http_response_code(400);
        echo '<p>Anmeldung fehlgeschlagen: ' . $h($e->getMessage()) . '</p><p><a href="/">Zurück</a></p>';
    }
    exit;
}

if ($path === '/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idt = $_SESSION['id_token'] ?? '';
    $_SESSION = [];
    session_destroy();
    $d = discovery($issuer);
    header('Location: ' . $d['end_session_endpoint'] . '?' . http_build_query(['id_token_hint' => $idt, 'client_id' => $clientId], '', '&', PHP_QUERY_RFC3986));
    exit;
}

$u = $_SESSION['user'] ?? null;
echo '<!doctype html><meta charset="utf-8"><title>' . $h($appName) . '</title><body style="font:16px system-ui;max-width:36rem;margin:3rem auto"><h1>' . $h($appName) . '</h1>';
if ($u === null) {
    echo '<p>Nicht angemeldet.</p><p><a href="/login">Mit VylarisAuth anmelden</a></p>';
} else {
    echo '<p>Angemeldet als <b>' . $h($u['name']) . '</b> (' . $h($u['email']) . ')</p><p>Rollen: ' . $h(implode(', ', $u['roles']) ?: '–') . '</p><p>sub: <code>' . $h($u['sub']) . '</code></p>'
        . '<form method="post" action="/logout"><button>Abmelden (inkl. SSO)</button></form>';
}
