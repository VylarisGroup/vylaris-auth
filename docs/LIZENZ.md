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
2. **Lizenzserver und öffentlicher Schlüssel sind fest eingebaut** (`LicenseService::API_URL`, `PRODUCT`, `DEFAULT_PUBLIC_KEY`). Sie lassen sich weder in der Oberfläche noch in `config/config.php` oder im Setup ändern; Einträge `license.api_url`, `license.public_key` und `license.product` werden ignoriert. Ein anderer Server oder Schlüssel erfordert eine neue Programmversion.

```php
'license' => [
    'required'         => true,
    'grace_days'       => 7,
    'refresh_seconds'  => 3600,
    'keyless_features' => 'all',   // oder z. B. ['passkeys']
],
```
**Bestehende Installationen:** Migration einspielen (`php bin/migrate.php`); ein `license`-Block ist nicht nötig, die Standardwerte (Lizenzpflicht an) greifen automatisch.

## Fehlersuche

| Meldung | Ursache |
|---|---|
| „Das Lizenzsystem ist nicht eingerichtet“ | eingebauter Schlüssel unbrauchbar (beschädigte Installation, Dateien neu einspielen) |
| „keine gültige Signatur“ | Antwort nicht vom Lizenzserver signiert oder verfälscht |
| „veraltet oder fehlerhaft (Uhrzeit …)“ | Serveruhr weicht um mehr als 5 Minuten ab |
| „Lizenzserver nicht erreichbar“ | Firewall/DNS; Domain muss öffentlich auflösen, nur HTTPS |
| „anderen Produkt“ | `license.product` ≠ Produktkennung des Schlüssels |
