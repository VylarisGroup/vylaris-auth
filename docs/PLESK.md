# Installation auf Plesk / Shared Hosting

**Kein Composer, kein Node, kein SSH nötig.** Das Paket wird hochgeladen, entpackt und im Browser eingerichtet.

## Voraussetzungen

- PHP **8.2 oder neuer** (Plesk → Domain → *PHP-Einstellungen*) mit `pdo_mysql`, `openssl`, `sodium`, `mbstring`, `json`, `ctype`.
- Eine leere MySQL-/MariaDB-Datenbank (utf8mb4) mit Benutzer (Plesk → *Datenbanken*).
- HTTPS (Plesk → *SSL/TLS-Zertifikate* → Let's Encrypt) für `auth.vylaris.ch`.
- Mailversand: SMTP-Zugangsdaten **oder** erlaubtes PHP-`mail()` beim Hoster.

## Schritt für Schritt

1. **Subdomain** `auth.vylaris.ch` anlegen, Let's Encrypt aktivieren, „HTTP → HTTPS umleiten“ einschalten.
2. **Hochladen**: Im Plesk-Dateimanager einen Ordner `vylarisauth` anlegen (z. B. neben `httpdocs`, falls erlaubt, sonst darin), `vylarisauth.zip` dort hochladen und *Entpacken*. Das ZIP enthält keinen Unterordner und auch versteckte Dateien (`.htaccess`) – prüfen Sie, dass `.htaccess` vorhanden ist (Dateimanager → „Versteckte Dateien anzeigen“).
3. **Dokumentenstamm festlegen** – zwei Varianten:
   - **Variante A (empfohlen)**: Plesk → Domain → *Hosting-Einstellungen* → *Dokumentenstamm* auf den Ordner **`…/vylarisauth/public`** setzen. Alles andere liegt dann außerhalb des Webs.
   - **Variante B** (falls der Dokumentenstamm nicht änderbar ist): Projekt direkt in den Dokumentenstamm entpacken. Die mitgelieferte `.htaccess` im Projektstamm leitet intern nach `public/` und sperrt `src/`, `config/`, `var/` usw. (zusätzlich haben diese Ordner eigene `.htaccess`-Sperren). Prüfen Sie danach unbedingt die Checkliste unten.
4. **Schreibrechte**: Ordner `config/` und `var/` müssen für PHP schreibbar sein (Plesk-Dateimanager → *Berechtigungen*; i. d. R. schon der Fall, da PHP unter dem Domain-Benutzer läuft).
5. **Setup-Token**: im Dateimanager die Datei `config/setup.token` anlegen, Inhalt: ein zufälliger Text mit mindestens 24 Zeichen (z. B. 32 zufällige Buchstaben/Ziffern). Das schützt das Setup vor Fremden.
6. **`https://auth.vylaris.ch/setup.php` öffnen**. Die Seite prüft das Hosting, dann ausfüllen: Setup-Token, Basis-URL, Datenbank, E-Mail, erster Administrator (Passwort ≥ 12 Zeichen). Das Setup schreibt `config/config.php`, legt die Tabellen an, erzeugt den Superadmin und den Signaturschlüssel und sperrt sich selbst.
7. **`public/setup.php` löschen.**
8. **Cron-Job** (Plesk → *Geplante Aufgaben* → *Aufgabe hinzufügen* → Typ „PHP-Skript ausführen“): Skriptpfad `…/vylarisauth/bin/cron.php`, Intervall alle 5 Minuten. Ohne Cron werden E-Mails nur beiläufig versendet und es wird nichts aufgeräumt. Derselbe Cron prüft auch auf neue Versionen aus GitHub (siehe [UPDATE.md](UPDATE.md)); die Installation eines Updates erfolgt immer per Klick im Admin-Bereich.
9. **Anmelden** unter `/login`, im Konto TOTP oder einen Passkey einrichten (der Admin-Bereich verlangt zweiten Faktor), dann abmelden und neu anmelden.
10. **Anwendungen** unter *Admin → Anwendungen* registrieren; Anleitung: [OIDC-INTEGRATION.md](OIDC-INTEGRATION.md).

## Konfiguration (`config/config.php`)

Vorlage: `config/config.example.php`. Wichtige Werte:

| Schlüssel | Bedeutung |
|---|---|
| `app.base_url` | Issuer, exakt wie von außen erreichbar (https, kein Slash am Ende). Eine Änderung ändert den `iss` aller Tokens. |
| `app.secret` | 32 Byte base64; Basis für TOTP-Verschlüsselung, Recovery-Code-HMAC, `sub`-Pseudonymisierung. **Nach Betriebsbeginn nicht ändern** (siehe BETRIEB.md). |
| `mail.host` | SMTP-Server; **leer = PHP `mail()`** des Hosters. `mail.encryption`: `tls` (587), `ssl` (465), `none` (nur localhost). |
| `security.trusted_proxies` | IPs vorgeschalteter Proxys, damit `X-Forwarded-For` für Rate-Limits genutzt wird. Leer lassen, wenn keiner vorgeschaltet ist. |
| `security.allow_registration` | Öffentliche Registrierung an/aus. |
| `security.admin_requires_mfa` | Admin-Bereich nur nach MFA-Anmeldung (Standard: `true`). |
| `webauthn.rp_id` | Registrierbare Domain für Passkeys. Eine Änderung macht vorhandene Passkeys unbrauchbar. |

## Checkliste nach der Installation (bitte durchgehen)

- [ ] `https://auth.vylaris.ch/.well-known/openid-configuration` liefert JSON mit korrektem `issuer`.
- [ ] **Müssen 403/404 liefern**: `/config/config.php`, `/var/`, `/src/Core/App.php`, `/migrations/001_init.php`, `/setup.php` (nach dem Löschen), `/composer.json`.
- [ ] Antwort-Header enthalten `Strict-Transport-Security`, `Content-Security-Policy`, `X-Content-Type-Options` (Browser-Entwicklertools → Netzwerk).
- [ ] Cookie `__Host-vsid` hat `Secure; HttpOnly; SameSite=Lax`.
- [ ] Registrierung eines Testkontos → Verifizierungsmail kommt an (sonst `mail.*` prüfen, Fehler stehen in der Tabelle `mail_queue.last_error`).
- [ ] Cron läuft: `php bin/cron.php -v` (oder im Plesk „Jetzt ausführen“) → keine Fehlermeldung.
- [ ] Zwei-App-Test mit `examples/rp-php` (siehe OIDC-INTEGRATION.md).
- [ ] **Datenschutz:** *Admin → Branding → Datenschutz* ausfüllen (Verantwortlicher, Kontakt), `/privacy` prüfen, AV-Verträge abschließen, Cron aktiv (Fristen!). Siehe [DATENSCHUTZ.md](DATENSCHUTZ.md).
- [ ] Optional Whitelabel: *Admin → Branding* (Name, Logo, Farben, Standard-Theme); `var/` muss schreibbar sein (Logo-Upload), siehe [WHITELABEL.md](WHITELABEL.md).
- [ ] Optional Social Login: ausgehende HTTPS-Verbindungen erlaubt (`php bin/hosting-check.php`), Anbieter in *Admin → Social Login* einrichten und je Anbieter einen Testlogin durchführen (siehe [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md)).
- [ ] Backup eingerichtet (BETRIEB.md).

## Fehlerbehebung

| Symptom | Ursache / Lösung |
|---|---|
| Setup: „Hosting-Prüfung“ zeigt Fehler | Fehlende PHP-Erweiterung → in Plesk unter *PHP-Einstellungen* aktivieren; PHP-Version erhöhen. |
| „Datenbankverbindung fehlgeschlagen“ | Host (meist `localhost`), Name, Benutzer, Passwort prüfen; Details im PHP-Fehlerlog (Plesk → *Protokolle*). |
| Seite ohne Styling | Dokumentenstamm falsch (Variante A/B) oder `.htaccess` wird ignoriert (nginx-only: siehe unten). |
| Endlose Weiterleitung auf https | Hinter Proxy: `X-Forwarded-Proto` muss `https` sein; sonst Proxy-Einstellung des Hosters prüfen. |
| `/oidc/token` liefert 401 trotz richtiger Daten | `Authorization`-Header kommt nicht in PHP an (FastCGI). Die `.htaccess` setzt ihn; bei nginx-only `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` ergänzen. Alternative: `client_secret` im POST-Body senden. |
| Token-Endpunkt liefert **HTTP 500** (Client: „kein id_token“) | Die JSON-Antwort enthält jetzt `error_description: … Referenz abc123`; diese ID steht in `var/log/error-DATUM.log` (ist `var/log` nicht beschreibbar: im PHP-Fehlerlog des Hosters, Plesk → *Logs*). Außerdem `php bin/check-oidc.php https://IHRE-DOMAIN` ausführen: prüft PHP-Version/Erweiterungen, DB, offene Migrationen, Signaturschlüssel (Signieren/Prüfen), Schreibrechte und zeigt die letzten Fehlerzeilen. |
| Client: „OIDC-Konfiguration nicht lesbar“ / `/.well-known/…` liefert **403** | 403 kommt vom Webserver/Hoster (WAF, ModSecurity, nginx-Regel gegen Pfade mit führendem Punkt), nicht von der App. Prüfen: lädt `/oidc/discovery` (identischer Inhalt)? Dann im Client die **Metadata-/Discovery-URL** `https://IHRE-DOMAIN/oidc/discovery` eintragen, und beim Hoster/in Plesk (*Apache & nginx-Einstellungen*, *Web Application Firewall*) `/.well-known/` freigeben. |
| Client: „OIDC-Konfiguration nicht lesbar“ | `php bin/check-oidc.php` ausführen (zeigt Status, Content-Type, JSON, `issuer`-Abgleich, JWKS) bzw. von außen `curl -i https://IHRE-DOMAIN/.well-known/openid-configuration`. Häufige Ursachen: `base_url` in `config/config.php` weicht von der Client-Issuer-URL ab (http/https, `www`, Schrägstrich am Ende), Weiterleitung statt JSON (HTTPS/Domain), Dokumentenstamm zeigt nicht auf `public/` bzw. `.htaccess` wird nicht ausgewertet (`AllowOverride`), fehlende Migrationen/DB-Fehler (HTML-Fehlerseite statt JSON). |
| Social Login: „Anmeldung fehlgeschlagen“ | Grund im Audit-Log (`social.failed` → `reason`); häufig falsche Redirect-URI/Client-Secret oder gesperrte ausgehende Verbindungen. Siehe SOCIAL-LOGIN.md. |
| Keine Mails | `mail.host` leer und `mail()` gesperrt → SMTP-Zugangsdaten in `config/config.php` eintragen. |

**nginx-only-Hosting** (ohne Apache-Backend): `.htaccess` wird ignoriert. Dann Dokumentenstamm = `public/` (Variante A) und in der nginx-Zusatzkonfiguration `try_files $uri /index.php$is_args$args;` setzen.
