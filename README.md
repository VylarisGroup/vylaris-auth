# VylarisAuth – zentraler SSO-/OIDC-Dienst

Single Sign-On für die VylarisGroup (`auth.vylaris.ch`): Konten, MFA (TOTP, Recovery-Codes, Passkeys), serverseitige Sitzungen,
OpenID Connect (Authorization Code + PKCE), **Social Login** (Google, Microsoft, GitHub, GitLab, Discord, beliebige OIDC-Anbieter), Anwendungs- und Rollenverwaltung, Admin-Bereich, Audit-Log und REST-API.

**Läuft auf normalem Shared Hosting (Plesk): PHP 8.2+, MySQL/MariaDB. Kein Composer, kein Node, kein Docker, kein Root, kein SSH nötig.**
Alle Abhängigkeiten sind im Paket enthalten; CSS/Icons sind fertig gebaut.

## Installation in 6 Schritten

1. Ordner `vylarisauth` anlegen, ZIP im Plesk-Dateimanager dort hochladen und entpacken.
2. Dokumentenstamm der Subdomain auf den Ordner **`public/`** setzen (oder Variante B, siehe [docs/PLESK.md](docs/PLESK.md)).
3. Leere MySQL-Datenbank anlegen.
4. Datei `config/setup.token` mit einem zufälligen Text (≥ 24 Zeichen) anlegen.
5. `https://auth.vylaris.ch/setup.php` öffnen und das Formular ausfüllen (sperrt sich danach selbst); danach `public/setup.php` löschen.
6. Cron-Job `bin/cron.php` alle 5 Minuten einrichten, im Konto TOTP/Passkey aktivieren.

Ausführlich mit Checkliste und Fehlerbehebung: **[docs/PLESK.md](docs/PLESK.md)**.

## Dokumentation

| Datei | Inhalt |
|---|---|
| [docs/PLESK.md](docs/PLESK.md) | Installation, Konfiguration, Checkliste, Fehlerbehebung |
| [docs/OIDC-INTEGRATION.md](docs/OIDC-INTEGRATION.md) | Anbindung einer Anwendung (Endpunkte, Claims, Ablauf) |
| [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md) | DSGVO: Funktionen (Export, Löschung, Fristen), Pflichten des Betreibers, Vorlagen (VVT, TOMs, Panne) |
| [docs/UPDATE.md](docs/UPDATE.md) | Aktualisierung aus GitHub-Releases (Einrichtung, Ablauf, Rückstellen, Sicherheit) |
| [docs/LIZENZ.md](docs/LIZENZ.md) | Lizenzpflicht (TRS License): Schlüssel bei Registrierung, Platzlimit, Scopes, Ausfallverhalten, Grenzen |
| [docs/WHITELABEL.md](docs/WHITELABEL.md) | Whitelabel: Name, Logo, Farben, Texte, Hell/Dunkel (Standard dunkel), Sicherheitsregeln |
| [docs/SOCIAL-LOGIN.md](docs/SOCIAL-LOGIN.md) | Anmeldung über externe Anbieter: Einrichtung je Anbieter, Konto-Regeln, Sicherheit |
| [docs/API.md](docs/API.md) | REST-Endpunkte für externe Apps |
| [docs/BETRIEB.md](docs/BETRIEB.md) | Backup, Update, Schlüsselrotation, Wiederherstellung, Notfälle |
| [docs/SICHERHEIT.md](docs/SICHERHEIT.md) | Sicherheitskonzept, Prüfstatus, **offene Punkte vor dem Livegang** |
| [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md) | Architektur und Datenmodell |
| [examples/rp-php](examples/rp-php) | Referenz-Anwendung (zwei Instanzen = zwei unabhängige Apps) |

## Tests (optional, lokal)

```bash
php tests/run.php            # alle Tests (SQLite in-memory, keine externen Dienste, keine Abhängigkeiten)
php tests/run.php OidcFlow   # nur Testklassen, deren Name den Filter enthält
php bin/hosting-check.php    # Hosting-Voraussetzungen prüfen
```

Nur wenn Templates/CSS geändert werden: `npm install && npm run build` (Tailwind + Boxicons → `public/assets`). Komponentenklassen (`.card`, `.btn`, `.input` …) stehen in `assets-src/input.css`, Scroll-/Menü-Effekte in `public/assets/ui.js` (ohne Inline-Code, CSP-konform).

## Verzeichnisse

```
public/        Webroot (index.php, setup.php, assets/)
src/           Anwendungscode (Core, Controller, Service, Repository, Oidc, Social, Security, Setup)
templates/     PHP-Templates (Escaping per $e())
migrations/    versionierte Schema-Migrationen
config/        Konfiguration (config.php wird vom Setup erzeugt)
var/           Laufzeitdaten: Signaturschlüssel, Logs, Locks, Branding-Dateien (var/branding)
bin/           CLI: migrate, cron, rotate-keys, generate-keys, create-admin, hosting-check
examples/      Referenz-Relying-Party
tests/         Tests (eigener Runner)
```
