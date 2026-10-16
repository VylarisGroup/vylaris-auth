# Änderungsprotokoll

Format: `## Version – Datum`, darunter Stichpunkte mit `- `. Neueste Version zuerst.
Die Einträge bis zur installierten Version werden jedem Administrator einmal im Admin-Bereich angezeigt.

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
