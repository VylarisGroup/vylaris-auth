# Änderungsprotokoll

Format: `## Version – Datum`, darunter Stichpunkte mit `- `. Neueste Version zuerst.
Die Einträge bis zur installierten Version werden jedem Administrator einmal im Admin-Bereich angezeigt.

## 1.8.2 – 2026-10-16
- Behoben: Icons und Layout erschienen nach einem Update kaputt, weil Browser alte CSS-/JS-Dateien bis zu 24 Stunden zwischenspeicherten. Statische Dateien tragen jetzt eine Versionskennung und laden nach jedem Update neu.
- Verbessert: Fehlgeschlagene Social-Logins (z. B. Discord) protokollieren jetzt den genauen Grund (Netzwerkfehler, Antwort des Anbieters). Sichtbar unter Admin → Social Login → Anbieter → „Letzte fehlgeschlagene Anmeldungen“.

## 1.8.1 – 2026-10-17
- Discord-Login: Anfragen an Discord senden jetzt den von Discord verlangten User-Agent (`DiscordBot (URL, 1.0)`); generische Kennungen werden von Discord teils abgewiesen. Außerdem wird ein fehlgeschlagener Profilabruf jetzt mit dem HTTP-Status im Audit-Log vermerkt.
- Neu: Auf der Detailseite eines Social-Anbieters (Admin → Social Login) stehen die letzten fehlgeschlagenen Anmeldungen mit Fehlercode und einer verständlichen Erklärung, so lässt sich die Ursache ohne Log-Suche finden.

## 1.8.0 – 2026-10-16
- Lizenzschlüssel müssen nur noch einmal eingegeben werden: Unter Admin → Lizenz hinterlegte Schlüssel (oder der Schlüssel, den ein Administrator beim Anlegen eines Kontos eingibt) werden für jedes neue Konto automatisch verwendet, bis alle Plätze belegt sind; danach wird der nächste hinterlegte Schlüssel genommen. Das Schlüsselfeld in der Registrierung entfällt, solange ein Schlüssel hinterlegt ist.
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
- Lizenzserver, Produkt und öffentlicher Schlüssel sind fest eingebaut und nicht mehr konfigurierbar.
- Die Update-Quelle (GitHub-Repository) wird nur serverseitig in der Konfiguration festgelegt.
- Aktualisierung aus GitHub-Releases: automatische Prüfung, Installation per Klick, Sicherung und Rückgängig-Funktion.

## 1.0.0 – 2026-10-14
- Erste Version: SSO/OIDC-Anbieter mit Social Login, Passkeys, Lizenzsystem und DSGVO-Funktionen.
