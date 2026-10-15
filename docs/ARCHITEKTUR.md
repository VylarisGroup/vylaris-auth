# VylarisAuth – Architektur und Hosting-Prüfung

## 1. Ziel

Zentraler SSO-Dienst (OpenID Connect Provider) für VylarisGroup unter `auth.vylaris.ch`, lauffähig auf Plesk Shared Hosting (PHP 8.2+, MySQL/MariaDB, Apache), ohne Docker, Root, Redis, Node.js im Betrieb oder Dauerprozesse.

## 2. Hosting-Anforderungen

| Anforderung | Minimum | Prüfung |
|---|---|---|
| PHP | 8.2 (empfohlen 8.3) | `bin/hosting-check.php` |
| Erweiterungen | pdo_mysql, openssl, sodium, mbstring, json, ctype, intl (empfohlen) | `bin/hosting-check.php` |
| Argon2id | `PASSWORD_ARGON2ID` verfügbar, sonst Fallback bcrypt (cost 12) | `bin/hosting-check.php` |
| Datenbank | MySQL 8 / MariaDB 10.6+, utf8mb4, InnoDB | `bin/hosting-check.php` |
| Composer / Node | **nicht nötig** – das Paket enthält alles und läuft nach dem Hochladen | – |
| HTTPS | Pflicht (Let's Encrypt in Plesk), HSTS | Doku |
| Cron | Plesk „Geplante Aufgaben“, alle 5 Minuten `php bin/cron.php` | Doku |
| Dokumentenstamm | `public/` als Webroot der Subdomain | Doku |
| Schreibrechte | nur `var/` (Logs, Sessions-Fallback, Rate-Limit-Cache), außerhalb des Webroots | Doku |

Es gibt keine Laufzeit-Abhängigkeiten: kein `vendor/`, kein Build-Schritt. `public/assets/` (CSS, Icons) ist fertig gebaut enthalten.

## 3. Verzeichnisstruktur

```
vylarisauth/
├── public/                 # einziger Webroot (Dokumentenstamm)
│   ├── index.php           # Front Controller
│   ├── setup.php           # Installer (sperrt sich nach Erfolg)
│   ├── .htaccess           # Rewrite, Security-Header
│   └── assets/             # kompiliertes CSS, Boxicons, JS
├── src/
│   ├── Core/               # Router, Request, Response, Container, View, Csrf, Config
│   ├── Controller/         # Auth, Account, Mfa, Admin, Oidc, Api
│   ├── Service/            # Passwort, Mfa, Session, Mail, Audit, RateLimit, Token
│   ├── Repository/         # PDO-Datenzugriff
│   └── Security/           # Policies, Rollenprüfung
├── templates/              # PHP-Templates mit automatischem Escaping
├── config/                 # config.example.php (echte config.php nicht im Webroot)
├── migrations/             # versionierte SQL-Migrationen
├── bin/                    # cron.php, migrate.php, hosting-check.php, rotate-keys.php
├── var/                    # log/, cache/, keys/ (nicht öffentlich, .htaccess deny)
├── tests/
└── docs/
```

Private Konfiguration: `config/config.php` und `var/keys/` liegen außerhalb von `public/`. Auf Shared Hosting ohne Zugriff oberhalb des Webroots wird `public/` als Dokumentenstamm eingestellt, sodass alle anderen Verzeichnisse nicht ausgeliefert werden.

## 4. Abhängigkeiten und Eigenimplementierungen

Wegen der Vorgabe „läuft nach dem Hochladen auf normalem Shared Hosting“ gibt es **keine Composer-Pakete**. Protokoll- und Format-Logik ist daher im Projekt umgesetzt, kryptografische Primitive stammen ausschließlich aus PHP/OpenSSL/libsodium:

| Zweck | Umsetzung |
|---|---|
| OAuth 2.0 / OIDC | `src/Oidc/OAuthServer.php` (Authorization Code + PKCE S256, Refresh-Rotation, RFC 6749/7636/9207/9700, OIDC Core), Validierung in `AuthorizeValidator` |
| JWT/JWKS | `src/Oidc/Jwt.php` (nur RS256, OpenSSL), `KeyService` (Schlüssel, Rotation, JWKS) |
| WebAuthn | `src/Service/WebAuthn/*` (CBOR, COSE, ES256/RS256, Flags, Sign-Count) |
| TOTP | `TotpService` (RFC 6238, getestet gegen die Testvektoren) |
| QR-Code | `src/Service/QrCode.php` (ISO 18004, SVG) |
| SMTP | `SmtpMailTransport` (STARTTLS/SSL, AUTH, Zertifikatsprüfung), Fallback `PhpMailTransport` |
| Passwörter | `password_hash` Argon2id (Fallback bcrypt) |
| Tests | eigener Runner `tests/run.php` (PHPUnit nicht nötig) |

Das widerspricht dem Grundsatz „etablierte Bibliothek statt Eigenimplementierung“. Ausgleich: umfangreiche Negativtests (siehe `docs/SICHERHEIT.md`) und die dort genannte Empfehlung eines externen Audits. Wer Bibliotheken bevorzugt, kann `OAuthServer` hinter derselben Schnittstelle ersetzen.

## 5. Schichten und Request-Fluss

`public/index.php` → Bootstrap (Config, Fehlerbehandlung, Sicherheits-Header) → Router → Middleware (Session, CSRF, Rate-Limit, Auth/Rolle) → Controller → Service → Repository (PDO, Prepared Statements) → Template.

## 6. Sessions

- Eigener `SessionHandlerInterface` auf Tabelle `sessions` (serverseitig, kein Dateisystem nötig).
- Cookie `__Host-vsid`: `Secure`, `HttpOnly`, `SameSite=Lax`, Pfad `/`.
- ID-Rotation bei Login, MFA-Abschluss und Rechteänderung (`session_regenerate_id(true)`).
- Spalten: user_id, ip, user_agent, created_at, last_seen_at, revoked_at → Geräteübersicht und gezielter Widerruf; jede Anfrage prüft `revoked_at`.
- Absolutes und Idle-Timeout konfigurierbar.

## 7. Datenmodell (Kern)

`users`, `user_emails_tokens` (Verifizierung/Reset, gehasht, Ablauf), `sessions`, `mfa_totp`, `mfa_recovery_codes` (gehasht), `webauthn_credentials`, `roles`, `user_roles`, `oauth_clients`, `oauth_client_redirects`, `oauth_scopes`, `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `app_roles`, `user_app_roles`, `audit_log`, `rate_limits`, `settings`, `schema_migrations`.

Tokens und Secrets werden nur als Hash gespeichert (SHA-256 für hochentropische Tokens, Argon2id für Client-Secrets und Recovery-Codes).

## 8. OIDC

Endpoints: `/.well-known/openid-configuration`, `/oidc/jwks`, `/oidc/authorize`, `/oidc/token`, `/oidc/userinfo`, `/oidc/logout` (RP-Initiated Logout mit registrierten `post_logout_redirect_uri`), `/oidc/revoke`.
Regeln: nur `response_type=code`, PKCE `S256` Pflicht, exakter Redirect-URI-Vergleich, `state` Pflicht, `nonce` im ID-Token, RS256-Signatur, Access-Token 10 min, Refresh-Token mit Rotation, Signaturschlüssel mit `kid` und Rotation (alter Schlüssel bleibt im JWKS bis Ablauf aller Tokens).

## 9. Berechtigungen

Globale Rollen (`superadmin`, `admin`, `user`) und je Anwendung eigene Rollen. Rollen kommen als Claim `roles` (nur die der anfragenden App) in ID-Token und UserInfo. Jede Controller-Aktion prüft über eine zentrale `Policy`-Klasse serverseitig.

## 10. Frontend

TailwindCSS wird einmalig lokal gebaut und als statische `public/assets/app.css` ausgeliefert (kein Node im Produktivbetrieb). Boxicons als lokal gehostete CSS/Font-Dateien. Strenge CSP ohne Inline-Skripte.

## 11. Hintergrundaufgaben

`bin/cron.php` (Plesk-Cron, alle 5 Minuten): Mail-Queue aus Tabelle `mail_queue` versenden, abgelaufene Tokens/Sessions/Rate-Limits löschen, Audit-Log-Aufbewahrung. Fallback: kleiner, rate-limitierter Probabilistic-Run bei normalen Requests.

## 12. Sicherheitsmaßnahmen (Auswahl, Abgleich mit OWASP ASVS)

Argon2id, CSRF-Token pro Session, Output-Escaping per Standard, Prepared Statements, Login-Rate-Limit je IP und Konto, generische Fehlermeldungen (keine Kontenenumeration), Passwort-Reset-Tokens einmalig und kurzlebig, Redirect-Ziele nur aus Allowlist, Security-Header (CSP, HSTS, X-Content-Type-Options, Referrer-Policy, frame-ancestors), Audit-Log ohne Geheimnisse.

## 13. Social Login

VylarisAuth ist zusätzlich OAuth2-/OIDC-**Client** gegenüber externen Anbietern (`src/Social/`): `SocialService` (Anbieterverwaltung, Flow, Kontoregeln), `IdTokenVerifier` + `Jwk` (RS256 gegen fremdes JWKS), `HttpClient`/`CurlHttpClient` (austauschbar, SSRF-geschützt), `SocialController` (Login, Callback, Konto, Admin). Tabellen: `social_providers`, `social_identities`, Spalte `users.password_set`. Details und Sicherheitsregeln: [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).

## 14. Test-Strategie

PHPUnit-Unit- und Integrationstests (SQLite oder MySQL-Testdatenbank) für Authentifizierung, MFA, Passkeys (Testvektoren), OIDC-Flow inkl. PKCE-Fehlerfälle, Rollen und Sitzungswiderruf. Zwei Beispiel-Clients (`examples/client-a`, `examples/client-b`) für den End-to-End-Test.

## Whitelabel

`src/Branding/BrandingService` (Werte in `settings` `brand.*`, Dateien in `var/branding`), `BrandController` (Admin, nur Superadmin) und die öffentliche Auslieferung `/brand/theme.css|logo|logo-dark|favicon` (vor der Session-Initialisierung in `App::handle`). Tailwind-Farben (`brand`, `accent`, `slate`), Rundungen und Schrift sind CSS-Variablen; Dark Mode über `html[data-theme=dark]` (Standard), Umschalter + Cookie `vtheme` in `public/assets/ui.js`. Details: [WHITELABEL.md](WHITELABEL.md).
