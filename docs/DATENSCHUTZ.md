# Datenschutz (DSGVO)

> **Ehrlicher Status:** VylarisAuth bringt die *technischen* Funktionen mit, die ein DSGVO-konformer Betrieb braucht (Auskunft, Export, Löschung, Berichtigung, Speicherbegrenzung, Datenminimierung, Sicherheit, Nachweise). **Konformität entsteht erst durch Ihren Betrieb:** Verantwortlicher, Rechtsgrundlagen, Verträge mit Dienstleistern, Verzeichnis der Verarbeitungstätigkeiten und eine geprüfte Datenschutzerklärung. Die mitgelieferte Erklärung (`/privacy`) ist eine **Vorlage** und ersetzt keine Rechtsberatung. Bitte durch Datenschutzbeauftragte/n oder Anwält:in prüfen lassen.

## 1. Was die Software abdeckt

| DSGVO | Umsetzung | Wo |
|---|---|---|
| Art. 5 (1) a – Transparenz | Öffentliche Datenschutzerklärung `/privacy` (aus Ihren Angaben + aktiven Social-Anbietern + tatsächlichen Fristen generiert); Footer-Link; Hinweis am Social-Login | Admin → Branding → Datenschutz |
| Art. 5 (1) c – Datenminimierung | Nur Name + E-Mail + Passwort-Hash; Claims pro Anwendung begrenzt (Scopes, pseudonymes `sub`); fehlgeschlagene Logins protokollieren nur eine **HMAC-Referenz** der Adresse, keinen Klartext; keine externen Skripte/Fonts/Tracker | `AuthService`, `ClaimsService` |
| Art. 5 (1) e – Speicherbegrenzung | IP/Browser im Audit-Log werden nach `privacy.ip_retention_days` (30) entfernt; Audit-Log nach `security.audit_retention_days` (365); Sitzungen, Tokens, Mail-Queue, Rate-Limits werden per Cron bereinigt; optional automatische Löschung inaktiver Konten | `bin/cron.php`, `Maintenance`, `PrivacyService` |
| Art. 5 (1) f / Art. 32 – Sicherheit | siehe [SICHERHEIT.md](SICHERHEIT.md) (Argon2id, MFA, CSRF, CSP, Rate-Limits, Verschlüsselung von Secrets …) | – |
| Art. 7 – Nachweis / Widerruf | Zeitpunkt + Version der Kenntnisnahme der Datenschutzerklärung bei Registrierung; App-Freigaben jederzeit widerrufbar | `users.privacy_*`, `/account/apps` |
| Art. 13/14 – Informationspflichten | siehe Art. 5 (1) a | `/privacy` |
| Art. 15 – Auskunft, Art. 20 – Übertragbarkeit | Selbstbedienung **Konto → Datenschutz → JSON-Export** (Passwort-Bestätigung, gedrosselt); Admin-Export für Anfragen per Post/Mail | `PrivacyService::export` |
| Art. 16 – Berichtigung | Name ändern; E-Mail ändern mit Bestätigungslink an die neue Adresse + Hinweis an die alte | `/account/privacy` |
| Art. 17 – Löschung | Selbstbedienung mit E-Mail-Bestätigung; Admin-Löschung (nur mit eingetippter Konto-Adresse); löscht Konto, Sitzungen, Tokens, MFA, Freigaben, Verknüpfungen, wartende Mails; **anonymisiert** Audit-Einträge | `PrivacyService::erase` |
| Art. 18 – Einschränkung | Konto sperren (Admin → Benutzer → Sperren) | `AdminService::lock` |
| Art. 21 – Widerspruch | per Kontakt an den Verantwortlichen (Prozess unten) | – |
| Art. 25 – Privacy by Design/Default | Dunkel-Standard, keine Cookies außer Sitzung/Theme, keine Telemetrie, kein Tracking, kein Marketing-Mailversand | – |
| Art. 30 – Verzeichnis | Vorlage unten | – |
| Art. 33/34 – Panne | Ablauf unten, Audit-Log als Quelle | – |

### Löschkonzept (Standardwerte)

| Datum | Frist | Mechanismus |
|---|---|---|
| Konto (Name, E-Mail, Hash, Rollen, MFA, Freigaben, Verknüpfungen) | bis zur Löschung (Nutzer/Admin) bzw. optional `privacy.inactive_account_days` | `erase()` |
| Sitzungen (inkl. IP/Browser) | max. `session.absolute_ttl` (1 Tag), widerrufene nach 7 Tagen | Cron |
| Audit-Log, IP + Browser | 30 Tage (`privacy.ip_retention_days`) | Cron |
| Audit-Log, Ereignisse | 365 Tage (`security.audit_retention_days`, min. 30) | Cron |
| Audit-Log nach Kontolöschung | sofort anonymisiert (kein Konto-/IP-/Detailbezug), Ereignis bleibt bis Fristende | `erase()` |
| Einmal-Tokens, OAuth-Codes/Tokens, Mail-Queue, Rate-Limits | Stunden bis wenige Tage nach Ablauf | Cron |
| Inaktive Konten (optional, Standard **aus**) | Warnung nach X Tagen, Löschung nach weiteren `inactive_warning_days` (30), nur wenn keine Anmeldung seither; Admins ausgenommen | Cron |

**Cron muss laufen** (`php bin/cron.php`, siehe [PLESK.md](PLESK.md)); ohne Cron greifen Fristen nicht.

## 2. Was Sie als Betreiber tun müssen

1. **Datenschutzangaben ausfüllen** (Admin → Branding → Datenschutz): Verantwortlicher inkl. Anschrift, Kontakt-E-Mail, ggf. Datenschutzbeauftragte/r, Aufsichtsbehörde. Erklärung `/privacy` lesen und rechtlich prüfen lassen. Link zu Nutzungsbedingungen optional.
2. **Auftragsverarbeitung (Art. 28):** AV-Vertrag mit Hoster (Plesk/Webspace), E-Mail-Dienstleister/SMTP; Standort/Drittland prüfen.
3. **Social Login:** Jeder aktivierte Anbieter (Google, Microsoft, GitHub, GitLab, Discord …) ist ein eigener Verantwortlicher bzw. Empfänger; Drittlandübermittlung (USA) beachten und in der Erklärung nennen (geschieht automatisch, Zusatztext unter „Weitere Hinweise“). Wer das nicht will: Anbieter deaktivieren.
4. **Verbundene Anwendungen:** Jede Anwendung, die SSO nutzt, braucht **eigene** Datenschutzerklärung und eigene Löschprozesse. *VylarisAuth löscht keine Daten in angeschlossenen Anwendungen* – bei einer Löschanfrage Anwendungen separat bereinigen (Liste: Admin → Anwendungen; Export enthält die Freigaben des Nutzers).
5. **Backups:** Gelöschte Daten bleiben bis zum Ablauf der Datenbank-Backups darin enthalten. Backups kurz halten (Empfehlung ≤ 30 Tage), verschlüsselt speichern, und bei Wiederherstellung Löschungen erneut ausführen (Audit-Log `privacy.account_erased` dokumentiert die IDs).
6. **Lizenzserver:** Mit aktiver Lizenzpflicht erhält der Lizenzserver Lizenzschlüssel und eine pseudonyme Geräte-ID je Konto (keine E-Mail, kein Name; siehe `LIZENZ.md`). Als Empfänger/Auftragsverarbeiter ins Verzeichnis aufnehmen und ggf. in der Datenschutzerklärung unter „Weitere Hinweise“ nennen.
7. **Cron einrichten** und `config/config.php` → `privacy`-Block prüfen (siehe `config.example.php`).
8. **Verzeichnis der Verarbeitungstätigkeiten, TOMs, ggf. DSFA** führen (Vorlagen unten). Für reine SSO-Dienste ist eine DSFA meist nicht erforderlich; prüfen Sie das bei Verarbeitung besonderer Daten in angeschlossenen Anwendungen.
9. **Mitarbeitende** (Admins) auf Vertraulichkeit verpflichten; Admin-Zugriff ist MFA-pflichtig und protokolliert.

## 3. Prozess Betroffenenanfragen (Frist: 1 Monat)

1. Identität prüfen (Antwort an die im Konto hinterlegte E-Mail oder Rückfrage).
2. **Auskunft/Übertragbarkeit:** Nutzer: *Konto → Datenschutz → Export*. Per Anfrage: *Admin → Benutzer → Daten exportieren* und sicher übermitteln. Zusätzlich Daten in angeschlossenen Anwendungen und bei Social-Anbietern abfragen/benennen.
3. **Berichtigung:** Nutzer ändert Name/E-Mail selbst; sonst Admin (Name) oder Löschung + Neuanlage.
4. **Löschung:** Nutzer: *Konto → Datenschutz → Löschen*. Per Anfrage: *Admin → Benutzer → Konto löschen*. Danach Löschung in angeschlossenen Anwendungen veranlassen und – falls das Konto über einen Social-Anbieter verknüpft war – auf dessen Verwaltung der Verknüpfung (Berechtigungen widerrufen) hinweisen.
5. **Einschränkung:** Konto sperren.
6. **Widerspruch (Art. 21, berechtigte Interessen):** prüfen; i. d. R. Konto löschen.
7. Anfrage und Ergebnis intern dokumentieren (nicht im Audit-Log, dort stehen nur Aktionen).

## 4. Datenpanne (Art. 33/34)

1. Erkennen/Eindämmen: betroffene Konten sperren, Sitzungen widerrufen (Admin → Benutzer), bei Schlüsselverdacht Signaturschlüssel rotieren ([BETRIEB.md](BETRIEB.md)), Passwörter zurücksetzen.
2. Umfang ermitteln: Audit-Log (`/admin/audit`), Server-Logs des Hosters.
3. **Innerhalb von 72 Stunden** an die Aufsichtsbehörde melden, sofern ein Risiko für Betroffene besteht; bei hohem Risiko Betroffene informieren.
4. Dokumentieren (auch nicht meldepflichtige Vorfälle).

## 5. Vorlage: Verzeichnis von Verarbeitungstätigkeiten (Art. 30)

| Feld | Eintrag |
|---|---|
| Verarbeitung | Zentrale Benutzerauthentifizierung (SSO/OIDC) |
| Verantwortlicher / Kontakt / DSB | *ausfüllen* |
| Zweck | Kontoverwaltung, Authentifizierung, Autorisierung für angeschlossene Anwendungen, IT-Sicherheit |
| Betroffene | Registrierte Benutzer, Administratoren, Bewerber:innen/Anfragende (E-Mail-Versand) |
| Datenkategorien | Stammdaten (Name, E-Mail), Zugangsdaten (Hash, MFA-Material), Nutzungs-/Protokolldaten (IP, Browser, Zeit), Rollen/Freigaben, optional Social-Identifier |
| Empfänger | Angeschlossene Anwendungen (nur freigegebene Claims); Hoster, Mail-Dienstleister (Auftragsverarbeiter); Social-Anbieter (bei Nutzung) |
| Drittlandübermittlung | nur bei Social-Login mit US-Anbietern (Zertifizierung/SCC des Anbieters), sonst keine |
| Löschfristen | siehe Löschkonzept oben |
| TOMs | siehe unten und SICHERHEIT.md |

## 6. TOMs (Kurzfassung)

Zutritt/Zugang/Zugriff: Hoster-Standards, Admin nur mit MFA, Rollen- und Berechtigungskonzept serverseitig · Weitergabe: TLS/HSTS, keine Secrets in Logs, Secrets verschlüsselt (libsodium) · Eingabekontrolle: Audit-Log aller Admin-Aktionen · Auftragskontrolle: AV-Verträge · Verfügbarkeit: Backups (DB + `config` + `var/keys` + `var/branding`), siehe [BETRIEB.md](BETRIEB.md) · Trennung: eigene Datenbank/Domain · Pseudonymisierung: `sub` je Anwendung pseudonym, HMAC-Referenzen in Logs · Wirksamkeit: Tests (`php tests/run.php`), Updates.

## 7. Grenzen (ehrlich)

* Kein Rechtsgutachten; Textvorlagen (Erklärung, Mails) müssen geprüft werden. Die UI ist deutschsprachig.
* **Daten in Backups, angeschlossenen Anwendungen und bei Drittanbietern** sind nicht automatisch betroffen (siehe oben).
* Es gibt **kein** automatisches Propagieren von Löschungen an Anwendungen (Webhook/SCIM nicht implementiert).
* Rechtsgrundlagen sind Vorschläge für den typischen Fall (Vertrag Art. 6 (1) b, berechtigtes Interesse f für Sicherheit). Für abweichende Einsatzzwecke (z. B. Beschäftigte, Kinder unter 16) gelten Sonderregeln – Altersverifikation ist **nicht** implementiert.
* Die Aussage „keine Einwilligung für Cookies nötig“ gilt nur, solange Sie keine Tracker o. Ä. per eigenem CSS/Footer-Link einbinden (CSP blockiert externe Ressourcen ohnehin).
* Export enthält bewusst keine Secrets; der Audit-Export ist auf 5000 Einträge begrenzt.
* Beim Löschen werden Audit-Zeilen, deren Details die Adresse enthalten, per Textsuche geleert. Neue Einträge enthalten ohnehin keine Klartext-Adressen von Nichtbenutzern; bei Altbeständen aus früheren Versionen bitte stichprobenartig prüfen.
