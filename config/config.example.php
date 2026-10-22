<?php
/**
 * VylarisAuth – Konfigurationsvorlage.
 *
 * Kopieren nach config/config.php (wird von setup.php erzeugt) und NIE ins Git
 * einchecken. Diese Datei liegt außerhalb von public/.
 * Alle Werte hier sind Platzhalter – keine echten Zugangsdaten.
 */
return [
    'app' => [
        'name'     => 'Auth',        // Standard-Anwendungsname (im Admin unter „Branding“ überschreibbar)
        'org'      => '',            // Organisation für Texte wie „Ein Konto für alle Anwendungen von {org}“
        'env'      => 'production',            // production | development
        'base_url' => 'https://auth.vylaris.ch', // Issuer / Origin, ohne Schrägstrich am Ende
        'timezone' => 'Europe/Zurich',
        // Zufälliger Schlüssel (>= 32 Byte, base64) für HMAC von CSRF/Tokens; setup.php erzeugt ihn.
        'secret'   => 'CHANGE_ME_BASE64_32_BYTES',
    ],
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'CHANGE_ME',
        'user'     => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'mail' => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'encryption' => 'tls',                  // tls | ssl | none
        'username'   => 'CHANGE_ME',
        'password'   => 'CHANGE_ME',
        'from_email' => 'no-reply@vylaris.ch',
        'from_name'  => 'Auth',
    ],
    'session' => [
        'cookie_name'   => '__Host-vsid',
        'idle_timeout'  => 1800,    // Sekunden ohne Aktivität
        'absolute_ttl'  => 86400,   // maximale Lebensdauer
    ],
    'security' => [
        'login_max_attempts_ip'      => 20,   // pro Fenster
        'login_max_attempts_account' => 5,
        'login_window_seconds'       => 900,
        'password_min_length'        => 12,
        'allow_registration'         => true,
        'require_email_verification' => true,
        'admin_requires_mfa'         => true,  // Admin-Bereich nur mit MFA-Anmeldung
        'social_skips_mfa'           => true,  // Social Login überspringt TOTP/Passkey (Admin-Bereich verlangt weiterhin MFA); false = zweiter Faktor auch nach Social Login
        'magic_link_enabled'         => true,  // Anmeldung per E-Mail-Link (zweiter Faktor bleibt Pflicht, falls eingerichtet)
        'magic_link_ttl'             => 900,   // Gültigkeit des Links in Sekunden (120–3600)
        'magic_link_same_browser'    => false, // true: Link nur im anfragenden Browser einlösbar (strenger, aber unbequemer)
        'audit_retention_days'       => 365,   // Aufbewahrung Audit-Log (min. 30)
        'trusted_proxies'            => [],   // z. B. ['203.0.113.10'], sonst leer
    ],

    // Oberfläche
    'ui' => [
        'icons_cdn' => true,   // Boxicons von cdn.jsdelivr.net (mit SRI) laden; false = lokale Kopie unter public/assets/boxicons (CSP erlaubt dann nur 'self')
    ],

    // Datenschutz (DSGVO) – siehe docs/DATENSCHUTZ.md
    'privacy' => [
        'ip_retention_days'    => 30,    // IP-Adresse/Browser im Audit-Log werden danach entfernt (Ereignis bleibt bis audit_retention_days)
        'inactive_account_days' => 0,    // 0 = aus. Sonst: Konten ohne Anmeldung nach X Tagen (min. 90) vorwarnen und löschen (Admins ausgenommen)
        'inactive_warning_days' => 30,   // Frist zwischen Vorwarnung und Löschung
        'require_acceptance'   => true,  // Registrierung nur mit Bestätigung "Datenschutzerklärung gelesen"
    ],
    // Aktualisierung aus GitHub-Releases – siehe docs/UPDATE.md
    'update' => [
        'repo'        => 'VylarisGroup/vylaris-auth',   // 'besitzer/name'; leer ('') = Updates aus; fehlt der Eintrag, gilt dieses Repository
        'token'       => '',     // nur für private Repositories: Fine-grained Token, Berechtigung "Contents: Read-only"
        'check_hours' => 12,     // Cron prüft höchstens so oft auf neue Releases
    ],

    // Lizenzsystem (TRS License) – siehe docs/LIZENZ.md
    'license' => [
        'required'      => true,                              // neue Konten brauchen einen Lizenzschlüssel
        // Lizenzserver, Produkt-Kennung und öffentlicher Schlüssel sind fest in die Software eingebaut
        // und lassen sich hier bewusst nicht ändern.
        'grace_days'    => 7,                                 // so lange gilt der letzte bestätigte Stand ohne Kontakt zum Lizenzserver
        'refresh_seconds' => 3600,                            // Cron prüft Lizenzen frühestens nach dieser Zeit erneut
        'keyless_features' => 'all',                          // Funktionen für Bestandskonten ohne Schlüssel: 'all' oder Liste, z. B. ['passkeys']
    ],
    'oidc' => [
        'private_key_dir'      => __DIR__ . '/../var/keys',
        'require_nonce'        => true,
        'access_token_ttl'     => 600,        // 10 Minuten
        'id_token_ttl'         => 600,
        'refresh_token_ttl'    => 2592000,    // 30 Tage
        'auth_code_ttl'        => 60,
    ],
    'webauthn' => [
        'rp_name' => 'Auth',
        'rp_id'   => 'auth.vylaris.ch',        // registrierbare Domain oder Host
    ],
    'paths' => [
        'log_dir' => __DIR__ . '/../var/log',
    ],
];
