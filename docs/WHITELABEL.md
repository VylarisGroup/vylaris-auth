# Whitelabel & Themes

Die gesamte Oberfläche lässt sich ohne Codeänderung auf Ihre Marke anpassen: **Admin → Branding** (nur Superadmins).
Standard ist der **dunkle Modus**; ein heller Modus ist enthalten und per Umschalter (Sonne/Mond im Kopf) wählbar.

## Was ist anpassbar?

| Bereich | Einstellungen |
|---|---|
| Identität | Name der Anwendung, Organisation, Slogan |
| Logo | Logo (für hellen Hintergrund), optional separates Logo für dunklen Hintergrund, Favicon; „Namen neben dem Logo anzeigen“ |
| Farben | Primär- und Akzentfarbe (16 Vorgaben **oder** eigener Hex-Wert), neutrale Töne (Slate, Zinc, Stone, Neutral) |
| Stil | Rundung (eckig/normal/rund), Schrift (modern, System, Serif, Monospace), Hintergrund (Farbverlauf/einfarbig) |
| Theme | Standard dunkel/hell/System; Umschalter für Benutzer an/aus |
| Texte | Titel und Untertitel der Anmeldeseite, Fußzeile, Support-E-Mail, bis zu 3 rechtliche Links (Impressum, Datenschutz …) |
| E-Mails | Absendername, Fußzeile; Texte nutzen automatisch Ihren Namen |
| Experten | Eigenes CSS (nach dem Theme geladen) |

Platzhalter in Texten: `{app}` (Name), `{org}` (Organisation).

Beim Einrichten (`setup.php`) können Name und Organisation direkt gesetzt werden; ohne Eingabe gilt „Auth“.
Zurücksetzen: *Branding → Auf Standard zurücksetzen* (löscht auch hochgeladene Dateien).

## Wie es technisch funktioniert

* Werte liegen in der Tabelle `settings` (`brand.*`), Dateien in `var/branding/` (nicht öffentlich, im Backup mitsichern!).
* Farben, Rundung und Schrift werden als **CSS-Variablen** über `/brand/theme.css` ausgeliefert. Das Tailwind-CSS (`public/assets/app.css`) referenziert nur diese Variablen – ein Neubau des CSS ist **nie** nötig.
* `/brand/*` (Theme, Logo, Favicon) ist öffentlich, ohne Sitzung/Cookie, mit ETag und `?v=`-Versionierung (Cache-Busting bei jeder Änderung).
* Die strenge CSP bleibt erhalten (`script-src 'self'`; `style-src 'self'` plus jsDelivr für Boxicons, abschaltbar mit `ui.icons_cdn`): keine Inline-Styles/-Skripte.
* Theme-Wahl: Cookie `vtheme` (`light`/`dark`, 1 Jahr, SameSite=Lax, nur Darstellung). Bei „System“ löst ui.js per `prefers-color-scheme` auf; ohne JavaScript gilt dunkel.
* E-Mails, Passkey-Anzeigename und Authenticator-App-Aussteller übernehmen den Markennamen.

## Sicherheitsregeln für Uploads und Eingaben

* Nur **Superadmins**, nur mit CSRF-Token; jede Änderung landet im Audit-Log (`branding.updated`, `branding.asset_set`, `branding.reset`).
* Bilder werden am **Inhalt** erkannt (PNG/JPEG/WebP/SVG, Favicon zusätzlich ICO), nicht an Dateiname oder Client-MIME. Limits: 512 KiB, Seitenlänge 16–4096 px; SVG max. 200 KiB.
* SVG ohne `DOCTYPE`/`ENTITY`, `<script>`, `<foreignObject>`, `on…`-Handler und `javascript:`. Zusätzlich werden alle Bilder mit `Content-Security-Policy: sandbox` und `nosniff` ausgeliefert, und sie liegen unter festem Namen außerhalb des Webroots.
* Farben: Hex oder Vorgabe; eigene Primär-/Akzentfarben brauchen mind. **3,5:1** Kontrast zu weißem Text (Buttons bleiben lesbar).
* Links: nur `https://…` oder lokale Pfade (`/impressum`) – kein `javascript:`, `data:`, `//host`.
* Eigenes CSS: kein `@import`, keine externen `url()`, kein `expression()`/`javascript:`; zusätzlich blockiert die Seiten-CSP externe Ressourcen.
* Alle Texte werden beim Ausgeben HTML-escaped.

## Grenzen (ehrlich)

* Die Oberfläche ist **deutschsprachig** (kein i18n-System). Texte lassen sich nur dort ändern, wo oben aufgeführt.
* `setup.php` behält bewusst sein eigenes, minimales Aussehen (läuft vor der Installation, ohne Datenbank).
* Eigene Schriftarten (Webfonts) werden nicht hochgeladen; die Schriftwahl nutzt Systemschriften (Inter nur, wenn lokal installiert), damit keine externen Fonts nötig sind.
* Eigenes CSS kann das Layout beschädigen – im Zweifel *Zurücksetzen*.
* Per CSS-Variablen anpassbare Klassen: `--brand-50…950`, `--accent-50…950`, `--n-50…950`, `--r-lg/xl/2xl`, `--font-sans`.
