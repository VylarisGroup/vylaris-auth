# Lizenzsystem (TRS License)

VylarisAuth ist lizenzpflichtig: **Jedes Konto belegt einen Platz („Gerät“) auf einem Lizenzschlüssel.** Das Gerätelimit des Schlüssels (`maxDevices`) begrenzt damit die Zahl der Nutzer. Angebunden ist die Prüf-API von TRS License (`/api/v1/validate|activate|heartbeat|deactivate`, Ed25519-signierte Antworten).

## Ablauf

| Situation | Verhalten |
|---|---|
| Registrierung | Das Formular verlangt einen Lizenzschlüssel. Vorab `validate` (Schlüssel gültig, Produkt passt, freier Platz), dann wird das Konto angelegt und der Platz per `activate` belegt. Schlägt irgendein Schritt fehl, entsteht kein Konto und kein Platz bleibt belegt. |
| Gerät/hwid | `vu-` + 32 Hex-Zeichen, HMAC aus Nutzer-ID und `app.secret`. Es gehen **keine** E-Mail-Adressen oder Namen an den Lizenzserver (Anzeigename dort: „Nutzer 42“). |
| Konto anlegen durch Admin | Der Admin muss einen Schlüssel angeben; das Konto belegt dort einen Platz. |
| Social Login | Legt **keine neuen Konten** mehr an. Bestehende Konten lassen sich weiter verknüpfen. |
| Bestandskonten | Bleiben ohne Schlüssel nutzbar (Bestandsschutz, kein Platz belegt) und können unter „Konto → Lizenz“ einen Schlüssel hinterlegen. Ihre Funktionen steuert `license.keyless_features` (Standard: alle). Das gilt auch für den im Setup angelegten ersten Superadmin. |
| Konto gelöscht (DSGVO, Admin, Inaktivität) | Der Platz wird freigegeben (`deactivate`). Ist der Lizenzserver gerade nicht erreichbar, wird die Freigabe vorgemerkt und vom Cron wiederholt. |
| Lizenz abgelaufen / gesperrt / Platz im Panel zurückgesetzt | **Nur Neuregistrierungen mit diesem Schlüssel scheitern.** Bestehende Nutzer melden sich weiter an, SSO läuft weiter. Die lizenzabhängigen Funktionen (Scopes) entfallen. |
| Lizenzserver nicht erreichbar | Neuregistrierung: Fehlermeldung. Bestehende Konten: der zuletzt bestätigte Stand gilt `license.grace_days` Tage (Standard 7); danach entfallen die Scopes (Anmeldung bleibt). |
| Aktualisierung | `bin/cron.php` prüft Lizenzen per `heartbeat` (frühestens nach `license.refresh_seconds`, Standard 1 h, max. 100 je Lauf) und wiederholt offene Platzfreigaben. Seitenaufrufe lösen keine Netzwerkanfragen aus. Manuell: Admin → Lizenz → „Alle jetzt prüfen“. |

## Einrichtung

1. Im Lizenzpanel ein **Produkt** mit der Kennung `vylarisauth` anlegen (Kennung = `license.product`) und Tarife mit Gerätelimit und `features` (siehe unten).
2. **Öffentlicher Schlüssel:** Der Schlüssel von `licence.vylaris.ch` ist fest in die Software eingebaut (`LicenseService::DEFAULT_PUBLIC_KEY`) und gilt, solange `license.public_key` leer ist – bei bestehenden Installationen ist also nichts einzutragen. Nur bei einem anderen Lizenzserver tragen Sie dessen Schlüssel (Panel → Einstellungen) in `config/config.php` ein. Er wird immer *fest* hinterlegt und nie vom Server geladen:

```php
'license' => [
    'required'         => true,
    'api_url'          => 'https://licence.vylaris.ch',
    'product'          => 'vylarisauth',
    'public_key'       => '-----BEGIN PUBLIC KEY-----\n…\n-----END PUBLIC KEY-----',
    'grace_days'       => 7,
    'refresh_seconds'  => 3600,
    'keyless_features' => 'all',   // oder z. B. ['passkeys']
],
```
`setup.php` belegt beides vor. **Bestehende Installationen:** Migration einspielen (`php bin/migrate.php`); ein `license`-Block ist nicht nötig, die Standardwerte (Server `https://licence.vylaris.ch`, Produkt `vylarisauth`, Lizenzpflicht an) greifen automatisch.
3. Cron wie bisher einrichten (`bin/cron.php`).
4. Die Domain des Lizenzservers muss auf öffentliche Adressen auflösen (der HTTP-Client lässt nur HTTPS und keine privaten Ziele zu).

## Scopes (Funktionen)

Die Namen tragen Sie im Feld `features` des Tarifs ein. Maßgeblich ist der Schlüssel des **ausführenden Nutzers** (bei Admin-Funktionen der Schlüssel des Administrators).

| Scope | Schaltet frei |
|---|---|
| `social_login` | Anbieter mit dem eigenen Konto verknüpfen; Social-Anbieter anlegen/ändern (Admin) |
| `passkeys` | Neue Passkeys (WebAuthn) einrichten |
| `oidc_apps` | OIDC-Anwendungen anlegen und ändern (Einstellungen, Redirect-URIs, Rollen) |
| `whitelabel` | Branding ändern |
| `audit_log` | Audit-Log einsehen |

Bewusst **nie** lizenzabhängig: Anmeldung per Passwort/vorhandener Methode, SSO, Authenticator-App/MFA, Recovery-Codes, alle Datenschutzfunktionen (Export, Löschung, Berichtigung), Benutzerverwaltung (Sperren, Rollen), und das **Deaktivieren/Widerrufen** von Anwendungen sowie die Secret-Rotation. Eine abgelaufene Lizenz soll weder Sicherheit schwächen noch Betroffenenrechte einschränken. Bereits eingerichtete Passkeys und Social-Verknüpfungen bleiben nutzbar; gesperrt ist das *Neueinrichten*.

Neue Scopes: Eintrag in `LicenseService::FEATURES`, Prüfung im Controller mit `denyFeature($user, 'name')` bzw. `$app->license->allows($userId, 'name')`.

## Status einsehen

* Nutzer: **Konto → Lizenz** (Schlüssel maskiert, Plan, Status, Scopes).
* Admin: **Admin → Lizenz** (Serveradresse, Fingerabdruck des Schlüssels, belegte Plätze je Schlüssel, Konten ohne Schlüssel, offene Freigaben).
* Audit-Log: `license.bound`, `license.invalidated`, `license.feature_denied`, `auth.register_license_refused`. Schlüssel erscheinen nur maskiert (`VA-A…CC`).

## Datenschutz

An den Lizenzserver gehen: Lizenzschlüssel, Produktkennung, die pseudonyme Geräte-ID und „Nutzer <ID>“ sowie die IP-Adresse **Ihres Servers** (nicht die der Nutzer). Der Lizenzserver ist Auftragsverarbeiter bzw. Empfänger; nehmen Sie ihn ins Verzeichnis der Verarbeitungstätigkeiten auf (siehe `DATENSCHUTZ.md`). Der Lizenzschlüssel selbst wird als Zugangsdatum behandelt: Er steht in `user_licenses`, taucht aber nie im Klartext in Protokollen oder Oberflächen auf.

## Grenzen (ehrlich)

* **Das ist kein Kopierschutz.** VylarisAuth ist ein quelloffen ausgelieferter PHP-Code. Wer Dateizugriff auf seine Installation hat, kann die Prüfung ausbauen (`license.required = false` oder Code ändern). Durchgesetzt wird verlässlich nur, was serverseitig beim Lizenzserver liegt: Gültigkeit, Ablauf, Sperre und das Gerätelimit pro Schlüssel. Wer den Betrieb *Dritten* überlässt, sollte die Lizenz vertraglich absichern.
* Ein einzelner Schlüssel mit hohem Limit kann an viele Personen weitergegeben werden; das Limit begrenzt die Anzahl Konten, nicht die Weitergabe des Schlüssels.
* Bestandsschutz bedeutet: Vorhandene Konten ohne Schlüssel zählen nicht gegen das Limit, solange sie keinen hinterlegen.
* Der Offline-Endpunkt (`/api/v1/offline`) wird nicht benötigt: Der zwischengespeicherte, zuletzt bestätigte Stand mit Karenzzeit erfüllt denselben Zweck.
* Webhooks, Verwaltungs-API und Stripe-Anbindung des Lizenzservers betreffen den Lizenz-Verkauf, nicht VylarisAuth; Lizenzen erzeugen Sie dort.

## Fehlersuche

| Meldung | Ursache |
|---|---|
| „Das Lizenzsystem ist nicht eingerichtet“ | `license.public_key` ist gesetzt, aber kein gültiger Ed25519-Schlüssel |
| „keine gültige Signatur“ | falscher öffentlicher Schlüssel (anderer Lizenzserver in `license.api_url`?) oder Antwort verfälscht |
| „veraltet oder fehlerhaft (Uhrzeit …)“ | Serveruhr weicht um mehr als 5 Minuten ab |
| „Lizenzserver nicht erreichbar“ | Firewall/DNS; Domain muss öffentlich auflösen, nur HTTPS |
| „anderen Produkt“ | `license.product` ≠ Produktkennung des Schlüssels |
