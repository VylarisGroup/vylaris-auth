# Betrieb: Backup, Updates, Schlüssel, Wiederherstellung

## Was gesichert werden muss

| Was | Wo | Hinweis |
|---|---|---|
| Datenbank | MySQL/MariaDB | `mysqldump --single-transaction --default-character-set=utf8mb4 db > backup.sql` (Plesk: *Datenbanken → Export* bzw. Backup-Manager). Enthält Benutzer, Hashes, verschlüsselte TOTP-Secrets, Clients, Audit-Log. |
| `config/config.php` | Server | enthält `app.secret`, DB-/SMTP-Zugang. **Getrennt von der DB-Sicherung** und verschlüsselt ablegen. |
| `var/branding/` | Server | Hochgeladene Logos/Favicon (Whitelabel). Gehen sie verloren, lädt man sie unter *Admin → Branding* neu hoch; Texte/Farben liegen in der DB. |
| `var/keys/` | Server | OIDC-Signaturschlüssel (privat). Ohne sie müssen neue Schlüssel erzeugt werden; die Client-Anwendungen holen sie automatisch per JWKS, ausgestellte Tokens werden ungültig. |

Ohne `app.secret` sind TOTP-Secrets nicht entschlüsselbar und Recovery-Codes nicht prüfbar: **Backup von DB und config immer zusammen aufbewahren** (aber an getrennten Orten).
Empfehlung: täglich DB-Dump, 30 Tage Aufbewahrung, wöchentlicher Wiederherstellungstest in einer Testumgebung.

Dateirechte auf Backups: nur Betriebspersonal. Sie enthalten Passwort-Hashes und personenbezogene Daten (DSG/DSGVO beachten).

## Update-Verfahren

1. Wartungsfenster ankündigen, Backup (DB + config + var/keys + var/branding) erstellen.
2. Optional lokal: `php tests/run.php` (alle Tests, ohne externe Dienste).
3. Dateien hochladen (**`config/config.php`, `var/` und `config/setup.token` nicht überschreiben**).
4. **Migrationen sofort nach dem Hochladen anwenden** (`002_social_login`: sonst schlagen Passwortänderungen fehl; `003_privacy`: sonst schlagen Registrierung und Datenschutz-Funktionen fehl, weil Spalten wie `users.privacy_accepted_at` fehlen): `php bin/migrate.php` (oder Plesk „PHP-Skript ausführen“). Migrationen sind versioniert, einmalig und laufen in der Reihenfolge ihres Dateinamens; schon angewendete werden übersprungen.
5. Smoke-Test: Login, Discovery, ein Anwendungs-Login, `php bin/cron.php -v`.
6. Rollback: Dateien der Vorversion zurück, DB aus Backup wiederherstellen (Migrationen sind nicht umkehrbar – daher Backup vorab).

Es gibt keine Composer-Abhängigkeiten, die separat aktualisiert werden müssten; Sicherheitsupdates betreffen PHP/Plesk (Hoster) und neue Versionen dieses Pakets.

## Signaturschlüssel rotieren (OIDC)

- Empfohlen: alle 6–12 Monate und bei Verdacht auf Kompromittierung.
- **Admin → Schlüssel → Schlüssel rotieren** (Superadmin) oder `php bin/rotate-keys.php`.
- Ablauf: Es wird ein neuer Schlüssel *aktiv*; der alte wird *retired* und bleibt **24 h** im JWKS, damit noch gültige Tokens prüfbar sind. Der Cron-Job löscht ihn danach samt Datei.
- Clients laden das JWKS selbständig nach (unbekannte `kid` → JWKS neu holen). Prüfen Sie, dass Ihre Client-Bibliotheken das tun (Cache ≤ 24 h).
- Bei **Kompromittierung** des privaten Schlüssels: rotieren, danach den alten Schlüssel sofort entfernen (`DELETE FROM oidc_keys WHERE kid='…'` und Datei in `var/keys/` löschen) – dann werden alle bisherigen Tokens ungültig – und alle Sitzungen/Refresh-Tokens widerrufen (`UPDATE sessions SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL; UPDATE oauth_refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL;`).

## Andere Geheimnisse rotieren

| Geheimnis | Auswirkung der Änderung | Vorgehen |
|---|---|---|
| `app.secret` | TOTP-Secrets nicht mehr entschlüsselbar, Recovery-Codes ungültig, `sub`-Werte ändern sich (**Anwendungen verlieren die Zuordnung der Benutzer!**), WebAuthn-User-Handles ändern sich | **Nur in Notfällen.** Danach MFA aller Benutzer zurücksetzen (`TRUNCATE mfa_totp, mfa_recovery_codes`; Passkeys neu registrieren) und Anwendungen informieren. |
| Client-Secret einer Anwendung | Die Anwendung kann keine Tokens mehr austauschen, bis das neue Secret eingetragen ist | *Admin → Anwendung → Secret erneuern*, sofort in der Anwendung hinterlegen. |
| DB-/SMTP-Passwort | – | in `config/config.php` anpassen. |

## Wiederherstellung und Notfälle

**Löschanfrage / Auskunft (DSGVO)** → *Admin → Benutzer → Datenschutz* (Export/Löschung) bzw. Selbstbedienung unter *Konto → Datenschutz*; Ablauf und Fristen: [DATENSCHUTZ.md](DATENSCHUTZ.md). Nach Wiederherstellung eines Backups gelöschte Konten erneut löschen.

**Social-Login-Anbieter fällt aus oder Konto ohne Passwort** → Benutzer nutzt „Passwort vergessen“ (setzt ein Passwort, beendet alle Sitzungen). Anbieter vorübergehend deaktivieren: *Admin → Social Login*. Siehe [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).

**Benutzer verliert zweiten Faktor** → Admin: *Benutzer → MFA zurücksetzen* (beendet alle Sitzungen); Benutzer richtet MFA neu ein. Recovery-Codes sind die Selbsthilfe-Variante – nach Benutzung neue Codes erzeugen.

**Superadmin ausgesperrt / einziger Admin hat MFA verloren**
```bash
php bin/create-admin.php admin@vylaris.ch --reset-mfa           # MFA + Sitzungen zurücksetzen
php bin/create-admin.php admin@vylaris.ch "Admin Name"           # Passwort neu setzen / Superadmin neu anlegen (fragt Passwort ab)
```
Ohne Shell-Zugang: kurzzeitig `security.admin_requires_mfa` auf `false` setzen, mit Passwort anmelden, MFA einrichten, Wert zurücksetzen.

**Datenbank verloren** → DB-Backup einspielen, `config/config.php` und `var/keys/` wiederherstellen, `php bin/migrate.php`, `php bin/cron.php -v`.

**Verdacht auf Missbrauch eines Kontos** → *Benutzer → Sperren* (widerruft Sitzungen und Tokens sofort), Audit-Log nach `user_id` filtern, Passwort-Reset erzwingen.

**Anwendung kompromittiert** → *Anwendung → Deaktivieren* (widerruft Tokens) bzw. *Endgültig widerrufen*, Secret ersetzen.

**Mailversand fällt aus** → Mails bleiben in `mail_queue` (bis zu 5 Versuche); Fehler stehen in `last_error`. Nach Behebung `php bin/cron.php -v`.

## Überwachung

- `php bin/hosting-check.php` nach Server-/PHP-Updates.
- Audit-Log (Admin → Audit-Log): auf `auth.login_failed`, `authz.denied`, `oidc.token_rejected`, `api.client_auth_failed`, Refresh-Token-Wiederverwendung achten.
- PHP-Fehlerlog des Hostings (Plesk → *Protokolle*). Anwendungsfehler enthalten keine Passwörter/Tokens.
- Audit-Aufbewahrung: `security.audit_retention_days` (Standard 365, min. 30). Danach löscht der Cron-Job.
