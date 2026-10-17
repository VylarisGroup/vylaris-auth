# Anmeldung per Magic Link

Benutzer können sich ohne Passwort mit einem einmaligen Link anmelden, der an ihre E-Mail-Adresse geschickt wird (Login-Seite → „Anmeldelink per E-Mail“).

- **Zweiter Faktor bleibt bestehen:** Ist TOTP oder ein Passkey eingerichtet, folgt nach dem Klick auf den Link die normale MFA-Abfrage.
- **Admin-Bereich:** Eine Anmeldung per Magic Link allein genügt dafür nicht (wie beim Passwort wird ein zweiter Faktor verlangt).
- **Sicherheit:** Link 15 Minuten gültig, nur einmal nutzbar, ein neuer Link entwertet den alten. Der Link in der E-Mail zeigt zuerst eine Bestätigungsseite („Sie melden sich an als …“); erst der Klick auf „Jetzt anmelden“ löst ihn ein. So verbrauchen Mail-Scanner den Link nicht. Unbekannte und gesperrte Adressen erhalten dieselbe Antwort, aber keine Mail.
- **E-Mail-Bestätigung:** Ein eingelöster Link beweist den Zugriff auf das Postfach und markiert die Adresse als bestätigt.

## Konfiguration (`config/config.php`, Abschnitt `security`)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `magic_link_enabled` | `true` | Funktion ein-/ausschalten (aus: Button verschwindet, Links werden nicht mehr akzeptiert) |
| `magic_link_ttl` | `900` | Gültigkeit in Sekunden (120–3600) |
| `magic_link_same_browser` | `false` | `true`: der Link funktioniert nur im Browser, in dem er angefordert wurde. Strenger gegen untergeschobene Links, aber unbequem, wenn die Mail in einer anderen App oder auf einem anderen Gerät geöffnet wird. |
