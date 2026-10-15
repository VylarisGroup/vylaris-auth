# Sicherheitskonzept und Prüfstatus

## Aktualisierung aus GitHub

Superadmin-only, CSRF-geschützt, Wartungsmodus, Archivprüfung (Pfade, Symlinks, Größen), PHP-Syntaxprüfung, Sicherung und automatische Rückstellung (`UpdateTest`). Vertrauensanker ist GitHub (Konto/Repository/Token) – ohne eigene Release-Signatur. Details und Empfehlungen: `UPDATE.md`.

## Lizenzprüfung

Antworten des Lizenzservers werden mit Ed25519 (libsodium) gegen einen fest konfigurierten Schlüssel geprüft; ausgewertet wird nur der signierte Inhalt, Zeitstempel (±5 min) und Schlüsselbezug werden kontrolliert (`LicenseTest`). Die Lizenzpflicht ist **kein Kopierschutz** gegen Betreiber mit Dateizugriff – Details und Grenzen in `LIZENZ.md`.

## Schutzmaßnahmen (umgesetzt und getestet)

| Bereich | Maßnahme | Test |
|---|---|---|
| Passwörter | Argon2id (Fallback bcrypt), Mindestlänge 12, Timing-Angleich bei unbekanntem Konto, generische Fehlermeldungen | `AuthServiceTest` |
| Brute Force | DB-gestützte Rate-Limits pro IP und Konto (Login, MFA, Token-Endpunkt, API-Client-Auth); TOTP: 5 Versuche/15 min | `testBruteForceThrottlesAccount`, `testTotpBruteForceIsThrottled` |
| Sitzungen | Server-seitig (nur SHA-256 der ID in der DB), `__Host-`-Cookie `Secure; HttpOnly; SameSite=Lax`, ID-Rotation beim Login (Fixation), Idle- und Absolut-Timeout, gezielter Widerruf, widerrufene Sitzungen werden nie „wiederbelebt“ | `SessionRevocationTest` |
| CSRF | Token pro Sitzung für alle zustandsändernden Browser-Requests; Token-/UserInfo-/API-Endpunkte nutzen Bearer/Client-Auth statt Cookies | `testPostWithoutCsrfIsRejected`, `testAdminPostRequiresCsrf` |
| XSS | Ausgabe ausschließlich über `$e()` (htmlspecialchars), strenge CSP ohne Inline-Skripte/-Styles (`script-src 'self'`), `X-Content-Type-Options`, `frame-ancestors 'none'` | `testXssInDisplayNameIsEscaped`, `testSecurityHeadersAndLoginForm` |
| SQL-Injection | PDO, echte Prepared Statements (`EMULATE_PREPARES=false`), keine String-Konkatenation von Eingaben (verbleibende Interpolation: ganzzahlig gecastete LIMIT/OFFSET und feste Tabellennamen) | Code-Review (grep) |
| Open Redirect | `Redirect::safeLocal` für `next`; OIDC: exakte Redirect-URIs, bei Fehlern **keine** Weiterleitung; Logout nur auf registrierte URIs | `testOpenRedirectIsBlocked`, `testAuthorizeWithBadRedirectShowsErrorAndNeverRedirects`, `testLogoutIgnoresUnregisteredRedirect` |
| Tokens (Verify/Reset/Einladung) | zufällig, nur als SHA-256 gespeichert, einmalig, befristet; Passwortänderung/-reset beendet alle Sitzungen | `testTokensAreStoredOnlyAsHashes`, `testPasswordResetFlowRevokesSessionsAndIsSingleUse` |
| MFA | TOTP (RFC 6238, Replay-Schutz, Secret verschlüsselt mit libsodium), Recovery-Codes (HMAC, einmalig), Passkeys (Challenge, Origin, RP-ID-Hash, Signatur, UP/UV, Sign-Count-Rollback) | `MfaTest`, `PasskeyTest`, `WebAuthn`-Tests |
| OIDC | Code-Flow nur mit PKCE S256, `state`/`nonce`, Code einmalig + an Client/Redirect gebunden, Replay widerruft Tokens, Refresh-Rotation mit Reuse-Erkennung, `iss`-Parameter (RFC 9207), Scope-Begrenzung je Client, App-spezifische Rollen/Claims, `sub` pseudonym | `OidcCoreTest`, `OidcWebTest`, `OidcFlowTest` |
| Social Login | `state` einmalig + sessiongebunden, PKCE S256, `nonce`, ID-Token-Prüfung (RS256/JWKS, `iss`, `aud`, `exp`, `nonce`), **keine automatische Kontoverknüpfung per E-Mail**, Registrierung nur mit vom Anbieter verifizierter E-Mail, Microsoft-E-Mail nie vertraut (nOAuth), Verknüpfen/Trennen nur mit CSRF + Passwortbestätigung, letzte Anmeldemethode nicht trennbar, eigene MFA bleibt Pflicht, Client-Secrets verschlüsselt (libsodium), SSRF-Schutz für Anbieter-URLs | `SocialLoginTest` (52 Tests, per Mutationstest auf Wirksamkeit geprüft) |
| Whitelabel | nur Superadmin + CSRF, Upload-Erkennung am Inhalt (PNG/JPEG/WebP/SVG/ICO), SVG ohne Skripte/DOCTYPE/foreignObject, Auslieferung mit `sandbox`-CSP + `nosniff`, Dateien außerhalb des Webroots, Link-Schemata (https/lokal), Kontrastprüfung, CSS-Filter, Escaping aller Marken-Texte, `/brand/*` ohne Sitzung | `BrandingTest` (29 Tests) |
| Datenschutz (DSGVO) | Export ohne Secrets, Löschung inkl. Anonymisierung der Protokolle (Vollscan der DB im Test), Bestätigung per E-Mail-Link + Passwort, letzter Superadmin geschützt, Admin-Löschung nur mit Adress-Bestätigung, Logs ohne Klartext-Adressen, IP-Kürzung, Inaktivitätslöschung | `PrivacyTest` (26 Tests) |
| Autorisierung | zentrale `Policy`; Admin-Aktionen prüfen serverseitig bei jedem Request; Admins verwalten keine Admins; kein Self-Lock/Self-Grant; letzter Superadmin geschützt; Admin-Bereich nur mit MFA-Login | `AdminTest` |
| Audit | Ereignisse ohne Passwörter/Tokens/Secrets (Scrubbing sensibler Schlüssel), Aufbewahrungsfrist | `testAuditLogContainsNoSecrets`, `MaintenanceTest` |
| Setup | Token-Pflicht (Datei außerhalb des Webroots), CSRF, Versuchsbegrenzung, Selbstsperre, löscht Token, schreibt Config 0640 | `InstallerTest` |
| Dateien | Webroot nur `public/`; `config/`, `var/` mit `Require all denied`; Private Keys 0600 in `var/keys` | `testKeyGenerationJwksAndPermissions` |

## Bedrohungsmodell (Kurzfassung)

- **Angreifer ohne Konto** (Credential Stuffing, Enumeration, Brute Force): Rate-Limits, generische Antworten, Registrierung antwortet identisch für vorhandene/neue Adressen.
- **Angreifer mit Konto** (Rechteausweitung, IDOR): Alle Objektzugriffe sind an `user_id` der Sitzung gebunden (Sitzungen, Passkeys, Consents); Admin-Aktionen prüfen Rollen serverseitig.
- **Böse/kompromittierte Anwendung**: sieht nur eigene Rollen, nur Scopes, die sie registriert hat; Secret nur gehasht; Deaktivierung widerruft Tokens.
- **Gestohlene Tokens**: kurze Access-Token-Laufzeit (10 min), Bindung an Sitzung, Widerruf bei Sperre/Logout/Passwortänderung; Refresh-Rotation.
- **Datenbank-Leck**: Passwörter Argon2id, Tokens/Sitzungs-IDs/Recovery-Codes nur als Hash/HMAC, TOTP-Secrets verschlüsselt (Schlüssel liegt in `config/`, nicht in der DB).
- **Server-Dateizugriff** (Shared Hosting, andere Mandanten): nicht vollständig abwehrbar; Rechte restriktiv setzen, Backups verschlüsseln.

## Bekannte Grenzen / bewusste Entscheidungen

- **Eigenimplementierungen statt Bibliotheken.** Damit das Paket ohne Composer auf Shared Hosting läuft, sind OAuth-/OIDC-Server (`OAuthServer`), JWT (RS256), WebAuthn-Prüfung, TOTP, QR-Code und SMTP-Client im Projekt umgesetzt. Kryptografische Primitive (Signatur, Hash, Zufall, Verschlüsselung) kommen ausschließlich aus OpenSSL/libsodium/PHP; es gibt keine eigene Kryptografie. Die Logik ist mit Positiv- und Angriffstests abgesichert (u. a. Code-Replay, PKCE-Fehler, Refresh-Reuse, `alg=none`, manipulierte Payloads, falsche Audience/Nonce/Origin/RP-ID, Sign-Count-Rollback, Header-Injection), aber **nicht unabhängig auditiert**. Für den Produktivbetrieb mit sensiblen Daten wird ein externes Audit empfohlen.
- WebAuthn: Attestation wird nicht ausgewertet (`none`); Formate `packed`/`tpm` werden nicht verifiziert (für Anmeldung irrelevant, nur für Hersteller-Beglaubigung).
- Social Login: Anbieteranbindungen sind nur gegen simulierte Anbieter getestet (nicht gegen Google, Microsoft, GitHub, GitLab, Discord live). Das TLS-Vertrauen zu den Anbietern entspricht dem des Hosters (CA-Bundle). Details: `docs/SOCIAL-LOGIN.md`.
- Nicht implementiert: `/oidc/revoke`, Introspection, Front-/Back-Channel-Logout, Dynamic Client Registration, `request`/`request_uri` (werden abgelehnt), andere Algorithmen als RS256.
- `sub` ist pro Benutzer stabil, nicht pro Anwendung verschieden (kein pairwise).
- Rate-Limits liegen in der DB (kein Redis): bei verteiltem, hochvolumigem Angriff belasten sie die DB; zusätzlich Plesk/WAF-Limits (Fail2Ban, ModSecurity) einsetzen.
- Mailversand ist asynchron (Queue); Links in Mails enthalten Token – Mail-Konto/Transportweg sind Teil der Vertrauenskette.
- Access Tokens sind kurzlebige JWTs (10 min), werden aber bei jeder Nutzung gegen die DB geprüft (Widerruf wirkt sofort).

## Testumgebungen

Die Suite läuft gegen SQLite (Standard) **und** gegen MariaDB 10.11 mit strengem `sql_mode` (`ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES …`): `VYLARIS_TEST_MYSQL=1 VYLARIS_TEST_MYSQL_PORT=3306 VYLARIS_TEST_MYSQL_PASS=… php tests/run.php` (leert die Test-DB `vauth`!). Zusätzlich wurde der komplette Authorization-Code-Flow über echtes HTTP (PHP-Server + MariaDB) geprüft. Nicht geprüft: Apache/Plesk, MySQL 8, PHP 8.2.

## Offene Punkte vor Produktivbetrieb (ehrlicher Prüfstatus)

1. **Hosting-Umgebung**: Die Tests liefen auf SQLite und MariaDB 10.11 (strict mode), nicht aber auf Apache/Plesk, MySQL 8 oder PHP 8.2 exakt. **Vor dem Livegang ein Staging-Durchlauf**: Setup, Registrierung, Login, MFA, Anwendung anlegen, Zwei-App-SSO, Cron, und `php bin/check-oidc.php https://ihre-domain`.
   Bekannte MySQL-Eigenheit: Die Standard-Collation `utf8mb4_unicode_ci` vergleicht ohne Beachtung der Groß-/Kleinschreibung; das betrifft `credential_id`, `client_id` (Zufallswerte, Kollisionswahrscheinlichkeit vernachlässigbar, Secret/Signatur bleiben maßgeblich).
2. **Social Login live testen**: je aktiviertem Anbieter einen echten Durchlauf (Registrierung, Login, Verknüpfen, Trennen, MFA danach). Außerdem prüfen, dass der Hoster ausgehende HTTPS-Verbindungen erlaubt (`php bin/hosting-check.php`).
3. **Apache/Plesk**: Die `.htaccess`-Dateien (HTTPS-Redirect, Front Controller, Authorization-Header, Sperren) konnten hier nicht gegen einen echten Apache getestet werden. Die Checkliste in `docs/PLESK.md` prüft das nach der Installation.
3. **SMTP** ist gegen einen lokalen Fake-Server getestet (inkl. AUTH, Dot-Stuffing, Header-Injection), nicht gegen Ihren echten Mailserver/STARTTLS. Testmail nach der Installation versenden.
4. Manuelle Browser-Abnahme der Passkey-Flows mit einem echten Authenticator (Tests nutzen einen Software-Authenticator). TOTP-QR-Codes wurden mit OpenCV dekodiert, nicht mit einer Authenticator-App.
5. Externer Penetrationstest/Audit vor sensibler Nutzung empfohlen.
6. DSG/DSGVO: Verarbeitungsverzeichnis, Aufbewahrungsfristen (Audit 365 Tage, Mail-Queue 7 Tage, Sitzungen), Auskunfts-/Löschprozess (Konto-Löschung derzeit nur per DB/Admin) festlegen.
