# Social Login (Anmeldung über externe Anbieter)

VylarisAuth kann Benutzer zusätzlich über Konten externer Identitätsanbieter anmelden. Unterstützt werden:

| Typ | Protokoll | Bemerkung |
|---|---|---|
| **Google** | OpenID Connect | E-Mail gilt nur bei `email_verified=true` |
| **Microsoft** (Entra ID / persönliche Konten) | OpenID Connect | Mandant konfigurierbar; **E-Mail wird nie vertraut** (siehe unten) → Registrierung nicht möglich, nur Verknüpfung |
| **GitHub** | OAuth 2.0 | primäre, verifizierte E-Mail aus `/user/emails` |
| **GitLab** (gitlab.com oder selbst gehostet) | OpenID Connect (Discovery) | Issuer-URL konfigurierbar |
| **Discord** | OAuth 2.0 | E-Mail nur bei `verified=true` |
| **OpenID Connect (generisch)** | OpenID Connect (Discovery) | Keycloak, Authentik, Auth0, Okta, LinkedIn, … – beliebig viele |

Nicht enthalten: Apple (verlangt `form_post`-Rückkehr und ein per ES256 signiertes Client-Secret; lässt sich später ergänzen), Facebook/X (kein Standard-OIDC bzw. keine verifizierte E-Mail).
**Hinweis zum Prüfstatus:** Die Anbieteranbindung ist gegen simulierte Anbieter (`tests/Support/FakeIdp.php`) getestet, nicht gegen die echten Dienste. Vor dem Livegang je Anbieter einmal real durchspielen.

## Ablauf in Kürze

1. Der Benutzer klickt auf der Anmeldeseite z. B. auf **Google** → `GET /social/google/login`.
2. VylarisAuth erzeugt `state`, `nonce` (nur OIDC) und einen PKCE-Verifier (S256), legt sie **nur serverseitig in der Session** ab (10 min gültig, einmalig) und leitet zum Anbieter weiter.
3. Rückkehr auf `GET /social/google/callback`: `state` wird geprüft, der Code wird serverseitig mit Client-Secret + `code_verifier` getauscht.
4. OIDC: ID-Token wird geprüft (Signatur RS256 gegen das JWKS des Anbieters, `iss`, `aud`/`azp`, `exp`, `iat`, `nonce`, `sub`). GitHub/Discord: Profil und E-Mail-Status werden über deren API gelesen.
5. Konto-Zuordnung (nächster Abschnitt) → neue Session (Rotation) → Weiterleitung auf `next` (nur lokale Pfade).

Das Token des Anbieters wird **nicht gespeichert**; es wird nur für die Profilabfrage verwendet.

## Regeln für Konten (Schutz vor Account-Übernahme)

- Die Identität wird über **Anbieter + unveränderliches `sub`/ID** erkannt, nie über die E-Mail-Adresse.
- Existiert zu einer vom Anbieter gelieferten E-Mail bereits ein lokales Konto, wird **nicht automatisch verknüpft**. Der Benutzer meldet sich mit seinem Passwort an und verknüpft den Anbieter unter **Konto → Verknüpfte Anmeldungen**.
- Neue Konten entstehen nur, wenn (a) die Registrierung global (`security.allow_registration`) und für den Anbieter erlaubt ist und (b) der Anbieter die E-Mail als **verifiziert** meldet. Das Konto gilt dann als bestätigt, hat aber **kein Passwort** (`users.password_set = 0`); ein Passwort-Login ist unmöglich, bis der Benutzer über „Passwort vergessen“ eines setzt.
- **Microsoft:** Der `email`-Claim ist bei Mehrmandanten-Apps nicht verifiziert und veränderbar („nOAuth“). Er wird deshalb nie zur Kontoerstellung oder -zuordnung benutzt; das Subject wird als `<tid>:<sub>` gespeichert (mandantengebunden).
- **MFA bleibt Pflicht:** Hat das Konto TOTP/Passkey, folgt nach dem externen Login die normale MFA-Abfrage. Der Admin-Bereich verlangt weiterhin eine Anmeldung mit zweitem Faktor (`amr` enthält `mfa`).
- Verknüpfen und Trennen: nur angemeldet, per POST mit CSRF-Token und **Passwortbestätigung** (rate-limitiert: 5 Fehlversuche/15 min). Die letzte verbleibende Anmeldemöglichkeit (Passwort, Passkey, Anbieter) kann nicht getrennt werden.
- Gesperrte Konten können sich auch über Anbieter nicht anmelden.
- Wer bereits angemeldet ist, wechselt nicht per Anbieter-Login das Konto.

## Einrichtung

1. Migration einspielen (bei Updates): `php bin/migrate.php` (legt `002_social_login` an).
2. Beim Anbieter eine OAuth-App registrieren. **Redirect-URI** (exakt):
   `https://auth.vylaris.ch/social/<schlüssel>/callback` – `<schlüssel>` ist der Anbieter-Schlüssel (Standard: Typ, z. B. `google`). Die Admin-Seite zeigt die genaue URI an.
3. **Admin → Social Login → Anbieter hinzufügen**: Typ, Client-ID, Client-Secret, ggf. Issuer/Mandant, „aktiv“ ankreuzen.
   Das Client-Secret wird mit libsodium (Schlüssel aus `app.secret`) verschlüsselt gespeichert und nie wieder angezeigt; beim Bearbeiten lässt ein leeres Feld das Secret unverändert.
4. Die Anmeldeseite zeigt automatisch Buttons für alle aktiven Anbieter.

### Anbieter-spezifisch

| Anbieter | Wo registrieren | Hinweise |
|---|---|---|
| Google | Google Cloud Console → APIs & Dienste → Anmeldedaten → OAuth-Client-ID (Webanwendung) | Zustimmungsbildschirm konfigurieren; Scopes `openid email profile` |
| Microsoft | Entra ID → App-Registrierungen | „Unterstützte Kontotypen“ passend zum Feld **Mandant** wählen: `common` (alle), `organizations`, `consumers`, oder eine Verzeichnis-ID (nur eigene Organisation). Client-Secret unter „Zertifikate & Geheimnisse“. |
| GitHub | Settings → Developer settings → OAuth Apps | „Authorization callback URL“ = Redirect-URI |
| GitLab | Preferences → Applications (oder Gruppen-/Admin-Bereich) | Scopes `openid email profile`; bei Self-Hosting Issuer = Basis-URL der Instanz |
| Discord | Developer Portal → Applications → OAuth2 | Scopes `identify email` |
| OIDC generisch | Beim jeweiligen IdP | Issuer-URL exakt wie `issuer` in dessen Discovery-Dokument (`/.well-known/openid-configuration`); unterstützt werden RS256-signierte ID-Tokens |

Mehrere Anbieter desselben Typs (z. B. zwei Keycloak-Realms): Schlüssel selbst vergeben (`kc-intern`, `kc-extern`).

## Sicherheitseigenschaften (getestet in `tests/SocialLoginTest.php`)

- `state` (Einmalnutzung, an Session und Anbieter gebunden, 10 min), PKCE S256, `nonce` im ID-Token; Replay des Callbacks wird abgelehnt.
- ID-Token: nur RS256; `alg=none`/HS256, fremde Signaturschlüssel, falscher `iss`/`aud`, fehlendes/falsches `nonce`, abgelaufene Tokens werden abgelehnt; JWKS wird 1 h gecacht und bei unbekanntem/rotiertem Schlüssel einmal neu geladen.
- `email_verified` zählt nur als wahr, wenn es exakt der Boolean `true` (oder String `"true"`) ist.
- Ausgehende Aufrufe nur per HTTPS mit TLS-Prüfung, ohne Redirects, mit Timeouts und 1-MiB-Limit. **SSRF-Schutz** für admin-konfigurierte URLs: nur `https://`, keine Zugangsdaten/Sonderports, keine `localhost`/privaten/reservierten Adressen; DNS-Auflösung wird geprüft und bei cURL fest an die geprüften Adressen gebunden.
- Fehlermeldungen an Benutzer sind generisch; im Audit-Log stehen nur Ereignis, Anbieter und ein kurzer Fehlercode (`social.signup`, `social.linked`, `social.unlinked`, `social.failed`, `social.signup_refused`, `social.provider_*`) – keine Codes, Tokens oder Secrets.
- Rate-Limits: Start 30/15 min, Callback 40/15 min je IP.

## Betrieb und Fehlersuche

- **Hosting-Voraussetzung:** ausgehende HTTPS-Verbindungen (PHP-Erweiterung `curl` oder `allow_url_fopen`). `php bin/hosting-check.php` prüft das. Manche Hoster sperren ausgehende Verbindungen – dann Support fragen.
- „Die Anmeldung über den externen Anbieter ist fehlgeschlagen“: Grund im **Admin → Audit-Log** (`social.failed`, Feld `reason`), z. B. `token_exchange_failed:invalid_client` (falsche Client-ID/-Secret), `id_token_iss` (Issuer/Mandant falsch), `discovery_invalid` (Issuer-URL stimmt nicht mit Discovery überein), `http_failed` (keine ausgehende Verbindung), `flow_missing_or_expired` (Cookie blockiert oder zu lange gewartet).
- Anbieter deaktivieren: sperrt sofort den Login darüber; Verknüpfungen bleiben bestehen. **Löschen** entfernt auch alle Verknüpfungen (Konten bleiben, sind aber ggf. nur noch per „Passwort vergessen“ erreichbar).
- `app.secret` ändern macht gespeicherte Client-Secrets unlesbar → Secrets in der Admin-Oberfläche neu eintragen.
- Benutzer ohne Passwort, der den Anbieter verliert: „Passwort vergessen“ auf der Anmeldeseite (E-Mail-Adresse des Kontos) oder Admin-Einladung/CLI `bin/create-admin.php`.

## Grenzen

- Kein Apple-Login, keine Anbieter mit ES256-/EdDSA-signierten ID-Tokens (nur RS256).
- Kein automatisches Zusammenführen von Konten über die E-Mail (bewusst).
- Kein Single Logout zum Anbieter: Abmelden beendet nur die VylarisAuth-Sitzung.
- Profildaten (Name/E-Mail) werden nach der Registrierung nicht mit dem Anbieter synchronisiert.
