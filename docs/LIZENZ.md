# Lizenzsystem (TRS License)

VylarisAuth ist lizenzpflichtig: **Jedes Konto belegt einen Platz („Gerät“) auf einem Lizenzschlüssel.** Das Gerätelimit des Schlüssels (`maxDevices`) begrenzt damit die Zahl der Nutzer. Angebunden ist die Prüf-API von TRS License (`/api/v1/validate|activate|heartbeat|deactivate`, Ed25519-signierte Antworten).

## Ablauf

| Situation | Verhalten |
|---|---|
| Registrierung | Das Formular fragt **keinen** Schlüssel ab. Die Lizenz kommt aus dem Lizenzpool (erster Schlüssel mit freiem Platz). Vorab `validate` (gültig, Produkt passt, freier Platz), dann wird das Konto angelegt und der Platz per `activate` belegt. Schlägt irgendein Schritt fehl, entsteht kein Konto und kein Platz bleibt belegt. Ist der Pool leer, ist keine Registrierung möglich. |
| Gerät/hwid | `vu-` + 32 Hex-Zeichen, HMAC aus Nutzer-ID und `app.secret`. Es gehen **keine** E-Mail-Adressen oder Namen an den Lizenzserver (Anzeigename dort: „Nutzer 42“). |
| Konto anlegen durch Admin | Kein Schlüssel-Eingabefeld: Der Admin wählt die Lizenz aus dem Pool (Standard „Automatisch“); das Konto belegt dort einen Platz. |
| Social Login | Legt **keine neuen Konten** mehr an. Bestehende Konten lassen sich weiter verknüpfen. |
| Bestandskonten | Bleiben ohne Schlüssel nutzbar (Bestandsschutz, kein Platz belegt) und können unter „Konto → Lizenz“ per Klick eine Lizenz aus dem Pool zuweisen. Ihre Funktionen steuert `license.keyless_features` (Standard: alle). Das gilt auch für den im Setup angelegten ersten Superadmin. |
| Konto gelöscht (DSGVO, Admin, Inaktivität) | Der Platz wird freigegeben (`deactivate`). Ist der Lizenzserver gerade nicht erreichbar, wird die Freigabe vorgemerkt und vom Cron wiederholt. |
| Lizenz abgelaufen / gesperrt / Platz im Panel zurückgesetzt | Bei der nächsten Prüfung wird der Nutzer **automatisch auf den nächsten verfügbaren Pool-Schlüssel umgestuft** (siehe unten). Gibt es keinen, melden sich bestehende Nutzer weiter an, SSO läuft weiter, aber die lizenzabhängigen Funktionen (Scopes) entfallen, bis ein Schlüssel verfügbar ist. |
| Lizenzserver nicht erreichbar | Neuregistrierung: Fehlermeldung. Bestehende Konten: der zuletzt bestätigte Stand gilt `license.grace_days` Tage (Standard 7); danach entfallen die Scopes (Anmeldung bleibt). |
| Aktualisierung | `bin/cron.php` prüft die Pool-Schlüssel (`validate`) und die Nutzer-Lizenzen (`heartbeat`, frühestens nach `license.refresh_seconds`, Standard 1 h, max. 100 je Lauf), stuft Nutzer mit ungültiger Lizenz um und wiederholt offene Platzfreigaben. Zusätzlich wird beim **Login** der veraltete Lizenzstatus des Nutzers geprüft – die Prüfung hängt also nicht allein vom Cron ab. Nach einem Serverausfall wird 5 Minuten lang nicht erneut angefragt, damit Logins nicht warten. Seitenaufrufe lösen keine Netzwerkanfragen aus. Manuell: Admin → Lizenz → „Alle jetzt prüfen“. |

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

## Lizenzpool

Unter **Admin → Lizenz → Lizenzpool** tragen Sie Ihre Schlüssel einmal ein (sie werden beim Lizenzserver geprüft). Das ist die **einzige Stelle**, an der Schlüssel eingegeben werden – bei der Registrierung, der Nutzererstellung und im Konto gibt es kein Schlüsselfeld mehr.

* **Nutzererstellung durch Admins:** Auswahlfeld „Lizenz“ mit den Pool-Schlüsseln (maskiert, mit Plan und freien Plätzen). „Automatisch“ nimmt den ersten Schlüssel mit freiem Platz; ein gezielt gewählter, aber voller Schlüssel führt zu einer Fehlermeldung statt zu stillem Ausweichen.
* **Registrierung und Konto → Lizenz:** immer „Automatisch“. Wird zwischen Vorabprüfung und Belegung ein Platz anderweitig vergeben, wird der nächste Pool-Schlüssel versucht.
* Sind alle Plätze belegt, scheitert die Anlage neuer Konten mit „Alle Plätze … belegt“ – hinterlegen Sie dann einen weiteren Schlüssel. Wird ein Platz frei (Konto gelöscht), wird er wieder vergeben.
* Ist der Lizenzserver nicht erreichbar, scheitert die Anlage (es wird nicht auf den nächsten Schlüssel ausgewichen).
* Die Pool-Tabelle zeigt Status, Plan, Plätze (laut Lizenzserver), Ablauf und Zeitpunkt der letzten Prüfung.
* Beim Update werden Schlüssel, die bereits von Konten genutzt werden, automatisch in den Pool übernommen (Migration 007).
* Entfernen eines Schlüssels lässt bestehende Konten unberührt; sie werden bei ungültiger Lizenz aber nicht mehr auf diesen Schlüssel umgestuft.

## Gegenprüfung und automatische Umstufung

Bei jeder Prüfung (Cron, Login, „Alle jetzt prüfen“) gilt:

1. Jeder Pool-Schlüssel wird beim Lizenzserver geprüft (gültig, Produkt, Ablauf, Plätze) und der Stand gespeichert.
2. Jeder Nutzer wird per `heartbeat` geprüft. Ist seine Lizenz **abgelaufen, gesperrt, unbekannt, für ein anderes Produkt oder sein Platz zurückgesetzt**, wird er auf den nächsten Pool-Schlüssel mit freiem Platz umgestuft: neuer Platz per `activate`, alter Platz wird freigegeben, Funktionen (Scopes) richten sich ab sofort nach der neuen Lizenz. Wurde nur der Platz im Lizenzpanel zurückgesetzt, wird zuerst derselbe Schlüssel erneut belegt.
3. Volle oder ungültige Pool-Schlüssel werden übersprungen. Gibt es keinen verfügbaren, bleibt der Nutzer „ungültig“ (Anzeige unter Admin → Lizenz „Ohne gültige Lizenz“) und wird automatisch umgestuft, sobald ein Schlüssel verfügbar wird – etwa wenn Sie einen neuen hinterlegen.
4. Bei Serverausfall wird nichts umgestuft oder entwertet; es gilt die Karenzzeit (`license.grace_days`).

Jede Umstufung steht im Audit-Log (`license.reassigned`, mit maskierten Schlüsseln).

## Fehlersuche

| Meldung | Ursache |
|---|---|
| „Das Lizenzsystem ist nicht eingerichtet“ | eingebauter Schlüssel unbrauchbar (beschädigte Installation, Dateien neu einspielen) |
| „keine gültige Signatur“ | Antwort nicht vom Lizenzserver signiert oder verfälscht |
| „veraltet oder fehlerhaft (Uhrzeit …)“ | Serveruhr weicht um mehr als 5 Minuten ab |
| „Lizenzserver nicht erreichbar“ | Firewall/DNS; Domain muss öffentlich auflösen, nur HTTPS |
| „anderen Produkt“ | `license.product` ≠ Produktkennung des Schlüssels |
