# Aktualisierung aus GitHub

VylarisAuth holt neue Versionen aus den **Releases** Ihres GitHub-Repositorys. Cron prüft regelmäßig, ob es ein neueres Release gibt; im Admin-Bereich erscheint ein Hinweis, und ein Superadmin installiert die Version **per Klick** (Admin → Update). Es braucht weder Shell noch Git auf dem Server, nur PHP mit den Erweiterungen `curl` und `zip`.

## Einrichtung

1. **Repository anlegen** (öffentlich oder privat). Der Inhalt von `vylarisauth/` liegt im **Wurzelverzeichnis** des Repositorys (`src/`, `templates/`, `public/`, `VERSION` … direkt oben). Die mitgelieferten `.gitignore` (hält `config/config.php`, `var/`, `node_modules/` aus dem Repository) und `.gitattributes` (lässt `tests/` aus dem Release-Archiv weg) gehören mit hinein. Den fertig gebauten CSS-Stand (`public/assets/app.css`) einchecken – auf dem Server wird nichts gebaut.
2. **Konfiguration** – Standard ist das Repository `VylarisGroup/vylaris-auth`: fehlt der Block `update` in der Konfiguration (bestehende Installationen), wird es automatisch verwendet; ein anderes Repository oder `''` (aus) tragen Sie wie folgt ein. Bei der Installation im `setup.php` (Abschnitt „Automatische Updates“) oder später in `config/config.php`:

```php
'update' => [
    'repo'        => 'VylarisGroup/vylaris-auth',   // leer ('') = Updates aus
    'token'       => '',                // nur bei privatem Repository
    'check_hours' => 12,
],
```
3. **Privates Repository:** GitHub → Settings → Developer settings → *Fine-grained personal access tokens* → nur dieses Repository, Berechtigung **Contents: Read-only**, Ablaufdatum setzen. Das Token steht nur in `config/config.php` (Rechte 0640), wird nur an `api.github.com` gesendet und nie angezeigt oder protokolliert.
4. **Cron** (`bin/cron.php`) wie in `PLESK.md` einrichten – er prüft nach neuen Releases (höchstens alle `check_hours` Stunden). Ohne Cron geht es auch: Admin → Update → „Jetzt prüfen“.

## Ein Release veröffentlichen

1. Datei `VERSION` anpassen (nur die Nummer, z. B. `1.1.0`) und committen.
2. Auf GitHub: *Releases → Draft a new release*, Tag **`v1.1.0`** (Tag und `VERSION` müssen übereinstimmen, sonst lehnt der Updater ab), Titel und Änderungsbeschreibung eintragen, veröffentlichen. Die Beschreibung sehen Sie im Admin-Bereich vor der Installation.
3. Entwürfe und Vorabversionen (*pre-release*) werden ignoriert; installiert wird nur eine **neuere** Version als die laufende (kein Downgrade).

Neue Datenbankänderungen kommen als neue Migration in `migrations/NNN_name.php` (nur additiv: `CREATE`/`ADD COLUMN`, keine zerstörenden Änderungen).

## Was bei „Aktualisieren“ passiert

1. Das neueste Release wird erneut abgefragt; weicht es von der angezeigten Version ab, bricht der Updater ab (Sie installieren nur, was Sie gesehen haben).
2. ZIP laden (max. 50 MB), prüfen: keine Pfade mit `..`, absolute Pfade oder Backslashes, keine Symlinks, Größenlimits, Platzbedarf.
3. Entpacken in einen Arbeitsordner, `VERSION` muss zum Tag passen, **alle PHP-Dateien werden mit dem PHP Ihres Servers auf Syntaxfehler geprüft** – ein kaputtes Release geht nie live.
4. **Sicherung** der betroffenen Dateien nach `var/backups/` (die letzten 3 bleiben).
5. **Wartungsmodus** (`var/update/maintenance.lock`: alle Seiten antworten 503, Cron pausiert), Dateien austauschen.
6. Weiterleitung auf `/admin/update/finish`: der **neue Code** führt die Migrationen aus und prüft sich. Danach Wartungsmodus aus.
7. Schlägt Schritt 5 oder 6 fehl (auch bei PHP-Timeout), werden die Dateien **automatisch zurückgestellt**.

**Ausgetauscht** werden: `src/ templates/ migrations/ bin/ docs/ examples/ scripts/ assets-src/ tests/ public/assets/` (veraltete Dateien darin werden entfernt, sofern der Ordner im Release vorkommt) sowie alle weiteren Dateien des Releases einzeln (`public/index.php`, `public/.htaccess`, `.htaccess`, `README.md`, `VERSION` …). **Nie angefasst:** `config/config.php`, `config/setup.token` und alles unter `var/` (Schlüssel, Logs, Branding, Sicherungen). Eigene Dateien, die nicht im Release stehen, bleiben liegen.

## Notfall und Rückstellen

* **Admin → Update → Sicherungen → Zurückstellen** stellt die Dateien einer Sicherung wieder her. Die **Datenbank wird nicht verändert**; da Migrationen additiv sind, läuft ein älterer Programmstand damit in der Regel weiter. Machen Sie vor jedem Update eine **Datenbanksicherung**.
* Bleibt der Wartungsmodus hängen (z. B. Browser nach der Installation geschlossen): angemeldet unter `/admin/update/finish` abschließen, oder per Konsole/Plesk-Aufgabe:

```
php bin/update.php status
php bin/update.php finish      # Update abschließen
php bin/update.php rollback    # auf die letzte Sicherung zurück
php bin/update.php install     # Update ohne Browser (check + install + finish)
```
* Letzter Ausweg: `var/update/maintenance.lock` und `var/update/state.json` löschen und Dateien aus `var/backups/update-*.zip` von Hand entpacken.

## Sicherheit – bitte lesen

Ein Update ersetzt Programmcode auf Ihrem Server. Der Updater vertraut daher dem, was GitHub für Ihr Repository ausliefert (Absicherung nur über GitHub und TLS, keine eigene Signatur):

* **Wer Ihr GitHub-Konto oder Schreibrechte am Repository übernimmt, kann Code auf Ihren Server bringen.** Aktivieren Sie 2FA, vergeben Sie Schreibrechte sparsam, schützen Sie den Hauptbranch und die Tags (*Rulesets*) und prüfen Sie jedes Release, bevor Sie „Aktualisieren“ klicken. Deshalb installiert nichts automatisch – es braucht einen Klick eines Superadmins (mit MFA).
* Das Token ist read-only und auf das eine Repository begrenzt; ein Diebstahl erlaubt Lesen, nicht Ändern.
* Nur Superadmins dürfen aktualisieren; alle Schritte stehen im Audit-Log (`update.checked`, `update.installed`, `update.completed`, `update.rolled_back`, `update.failed`).
* Eine zusätzliche Release-Signatur (Ed25519, geprüft gegen einen fest eingebauten Schlüssel) würde dieses Restrisiko beseitigen; sie ist nicht eingebaut.

## Grenzen

* Die Anbindung an die GitHub-API (`GithubSource`) ist nicht gegen das echte GitHub getestet (in der Entwicklungsumgebung gesperrt); Ablauf, Archivprüfung, Austausch, Wartungsmodus und Rückstellung sind getestet. Probieren Sie es nach dem Einrichten einmal mit einem Test-Release (z. B. Version `0.0.1` → `0.0.2`) auf einer Staging-Installation.
* Änderungen an `public/.htaccess`/`.htaccess` im Release überschreiben Ihre lokalen Anpassungen (die Sicherung enthält die alte Datei).
* Läuft die Installation über Windows-Hosting oder ein Dateisystem ohne Schreibrecht des Webserver-Benutzers auf `src/`, `templates/` usw., scheitert sie vor jeder Änderung mit einer Fehlermeldung.
