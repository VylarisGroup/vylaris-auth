# Änderungsprotokoll

Format: `## Version – Datum`, darunter Stichpunkte mit `- `. Neueste Version zuerst.
Die Einträge bis zur installierten Version werden jedem Administrator einmal im Admin-Bereich angezeigt.

## 2.0.0 – 2026-10-29
- Entfernt: Das gesamte Lizenzsystem. VylarisAuth läuft wieder ohne Schlüssel, Lizenzpool, Plätze oder Funktionsbeschränkungen: Registrierung, Anlegen von Konten, Social Login, Passkeys, Anwendungen, Audit-Log und Branding stehen allen offen. Die Seiten „Admin → Lizenz“ und „Konto → Lizenz“, die Lizenz-Karte in der Nutzerbearbeitung und der Lizenz-Schritt im Assistenten entfallen, ebenso der Cron-Teil und die Konfiguration. Beim Update löscht eine Migration die zugehörigen Datenbanktabellen und Protokolleinträge; ein `license`-Block in der `config/config.php` wird ignoriert und kann gelöscht werden.
- Geändert: Neue Konten über Social Login sind wieder möglich, wenn der Anbieter „Registrierung erlauben“ aktiviert hat und eine bestätigte E-Mail-Adresse liefert.

## 1.12.0 – 2026-10-24
- Neu: Am Ende jeder Seitenleiste (Administration und Mein Konto) steht dezent „powered by VylarisAuth“ mit Link auf https://vylaris.ch. Der Hinweis ist nicht konfigurierbar: Er hat kein Branding-Feld, fehlt er in einem angepassten Template, wird er vom System ergänzt, seine Darstellung steht mit `!important` am Ende des Theme-CSS, und eigenes CSS, das ihn oder die Seitenleiste ausblendet, wird beim Speichern abgelehnt.

## 1.11.0 – 2026-10-22
- Geändert: Boxicons werden vom CDN (jsDelivr, mit Subresource Integrity) geladen. Die CSP erlaubt dafür `https://cdn.jsdelivr.net` für Styles und Schriften, Skripte bleiben auf `'self'` beschränkt. Mit `'ui' => ['icons_cdn' => false]` in `config/config.php` wird wieder die lokale Kopie verwendet.

## 1.9.1 – 2026-10-17
- Geändert: Social Login überspringt jetzt den eigenen zweiten Faktor (TOTP/Passkey); der Anbieter-Login gilt als vollwertig. Der Admin-Bereich verlangt weiterhin einen zweiten Faktor. Wer den alten Ablauf will, setzt in `config/config.php` unter `security` `'social_skips_mfa' => false`.

## 1.9.0 – 2026-10-17
- Neu: Anmeldung per Magic Link. Auf der Login-Seite „Anmeldelink per E-Mail“: Link per Mail, Bestätigungsseite, Einmalverwendung, 15 Minuten gültig. Ein eingerichteter zweiter Faktor (TOTP/Passkey) wird weiterhin abgefragt; für den Admin-Bereich reicht der Link allein nicht. Einstellungen und Details: docs/MAGIC-LINK.md.

## 1.8.5 – 2026-10-16
- Neu: Anleitung für KeyHelp (docs/KEYHELP.md). `public/.htaccess` setzt `DirectoryIndex index.php` ausdrücklich und ist für Plesk und KeyHelp ausgelegt.

## 1.8.4 – 2026-10-16
- Neu: Die Ersteinrichtung (`setup.php`) ist jetzt ein mehrstufiger Assistent mit Fortschrittsanzeige (Zugang, Dienst, Datenbank, E-Mail, Administrator), Prüfung je Schritt und Dunkelmodus. Ohne JavaScript bleibt es ein normales Formular.
- Geändert: Die Repository-Abfrage für Updates entfällt im Setup; es wird automatisch das Standard-Repository verwendet.

## 1.8.3 – 2026-10-16
- Behoben: Fehler „TOO_MANY_REDIRECTS“ (Endlosschleife) bei frischen Installationen hinter Plesk-nginx, Cloudflare oder anderen Proxys. Die `.htaccess` erzwingt HTTPS nicht mehr selbst; HSTS und Secure-Cookies richten sich nach `app.base_url`. Bei bestehenden Installationen bitte die Zeilen „HTTPS erzwingen“ in `public/.htaccess` löschen (siehe docs/PLESK.md).

## 1.8.2 – 2026-10-16
- Behoben: Icons und Layout erschienen nach einem Update kaputt, weil Browser alte CSS-/JS-Dateien bis zu 24 Stunden zwischenspeicherten. Statische Dateien tragen jetzt eine Versionskennung und laden nach jedem Update neu.
- Verbessert: Fehlgeschlagene Social-Logins (z. B. Discord) protokollieren jetzt den genauen Grund (Netzwerkfehler, Antwort des Anbieters). Sichtbar unter Admin → Social Login → Anbieter → „Letzte fehlgeschlagene Anmeldungen“.

## 1.8.1 – 2026-10-17
- Discord-Login: Anfragen an Discord senden jetzt den von Discord verlangten User-Agent (`DiscordBot (URL, 1.0)`); generische Kennungen werden von Discord teils abgewiesen. Außerdem wird ein fehlgeschlagener Profilabruf jetzt mit dem HTTP-Status im Audit-Log vermerkt.
- Neu: Auf der Detailseite eines Social-Anbieters (Admin → Social Login) stehen die letzten fehlgeschlagenen Anmeldungen mit Fehlercode und einer verständlichen Erklärung, so lässt sich die Ursache ohne Log-Suche finden.

## 1.8.0 – 2026-10-16
- Behoben: Der Hell/Dunkel-Umschalter war in manchen Designs kaum zu erkennen. Er hat jetzt Rahmen und Hintergrund und auf dem Handy eine Beschriftung.

## 1.7.0 – 2026-10-16
- Neue Seitenleiste mit ausklappbaren Gruppen im Admin-Bereich und im Konto (auf dem Handy als Menü einklappbar, geöffnete Gruppen werden gemerkt).
- Neue Assistenten, die Schritt für Schritt durch die Einrichtung führen: Anwendung anbinden, Benutzer anlegen, Social-Anbieter einrichten. Die bisherigen Formulare bleiben unverändert verfügbar.
- Die Tab-Leiste im Admin-Bereich entfällt (ersetzt durch die Seitenleiste).

## 1.6.0 – 2026-10-16
- Neu: Änderungsprotokoll. Nach einem Update zeigt der Admin-Bereich beim ersten Aufruf, was sich geändert hat.
- Der Updater überschreibt die .htaccess im Projektstamm nicht mehr und kann bei nicht beschreibbaren Verzeichnissen vorhandene Dateien direkt ersetzen.

## 1.5.4 – 2026-10-16
- Behoben: Update-Download schlug mit HTTP 415 fehl (falsche Header an den GitHub-Download-Server).
- Dokumentation zur geschützten .htaccess ergänzt.

## 1.5.0 – 2026-10-16
- Die Update-Quelle (GitHub-Repository) wird nur serverseitig in der Konfiguration festgelegt.
- Aktualisierung aus GitHub-Releases: automatische Prüfung, Installation per Klick, Sicherung und Rückgängig-Funktion.

