# Betrieb auf KeyHelp

VylarisAuth läuft auf KeyHelp (Apache 2.4 hinter nginx, PHP-FPM) wie auf Plesk. Alle `.htaccess`-Dateien nutzen nur Standard-Module (`mod_rewrite`, `mod_headers`, `mod_authz_core`) und sind mit `<IfModule>` bzw. – für ältere Apache-Versionen – mit `Order/Deny` abgesichert.

1. **Dokumentenstamm auf `public/` setzen** (KeyHelp → Domain → Einstellungen → Dokumentenstamm, z. B. `/auth/public`). Das ist die empfohlene Variante; `src/`, `config/`, `var/` usw. liegen dann gar nicht im Web.
2. **PHP ≥ 8.2** für die Domain wählen (Erweiterungen: pdo_mysql, curl, mbstring, openssl, sodium, intl optional).
3. **HTTP → HTTPS** im Panel einschalten (Domain → SSL → „Auf HTTPS umleiten“). Die `.htaccess` leitet bewusst nicht selbst um, sonst droht `TOO_MANY_REDIRECTS` hinter dem nginx-Proxy.
4. `.htaccess`-Auswertung muss aktiv sein (KeyHelp: `AllowOverride All`, Standard). Bei Fehler 500 direkt nach dem Upload hier zuerst prüfen: `Options`, `DirectoryIndex` und `Header` benötigen die Override-Klassen *Options*, *Indexes*, *FileInfo*.
5. Cron-Job `php /pfad/zu/bin/cron.php` unter KeyHelp → Cronjobs anlegen.
6. Bleibt der Dokumentenstamm im Projektstamm (Variante B), leitet die Stamm-`.htaccess` intern nach `public/`. Prüfen Sie dann, dass `/config/config.php`, `/var/` und `/src/` per Browser **403** liefern.
7. Läuft die Domain zusätzlich hinter Cloudflare: SSL-Modus „Full (strict)“.
