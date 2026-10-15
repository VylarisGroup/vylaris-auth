# Anwendungen per OpenID Connect anbinden

VylarisAuth ist ein standardkonformer OpenID-Connect-Provider (Authorization Code Flow **mit PKCE S256**).
Verwenden Sie in Ihrer Anwendung eine etablierte OIDC-Client-Bibliothek; konfigurieren Sie nur die Discovery-URL.

**Discovery:** `https://auth.vylaris.ch/.well-known/openid-configuration`

| Endpunkt | URL |
|---|---|
| Authorization | `/oidc/authorize` |
| Token | `/oidc/token` (`client_secret_basic`, `client_secret_post`, bei öffentlichen Clients `none`+PKCE) |
| UserInfo | `/oidc/userinfo` (Bearer) |
| JWKS | `/oidc/jwks` (RS256) |
| End Session | `/oidc/logout` (RP-Initiated Logout 1.0) |

## 1. Anwendung registrieren

*Admin → Anwendungen → Neue Anwendung registrieren*

- **Redirect-URIs**: exakte Übereinstimmung (kein Wildcard, kein Fragment), nur `https://`; `http://` ist ausschließlich für Loopback (`localhost`, `127.0.0.1`, `::1`) zulässig, z. B. für lokale Tests.
- **Post-Logout-URIs**: optional, ebenfalls exakt.
- **Scopes**: `openid` (Pflicht), `profile`, `email`, `roles`, `offline_access` (Refresh Tokens).
- **Typ**: *vertraulich* (Server-Anwendung mit Secret) oder *öffentlich* (SPA/Mobile, nur PKCE).
- **Zugriff**: *alle Benutzer* oder *nur mit Anwendungsrolle* (Benutzer ohne Rolle erhalten `access_denied`).
- **Einwilligung überspringen**: nur für interne First-Party-Anwendungen.

Das **Client-Secret wird nur einmal angezeigt** (gespeichert wird nur ein Argon2id-Hash). Bei Verlust: *Secret erneuern*.

## 2. Ablauf

1. Anwendung erzeugt `state`, `nonce` und PKCE-`code_verifier` (43–128 Zeichen) und leitet um:
   ```
   GET /oidc/authorize?response_type=code&client_id=…&redirect_uri=…&scope=openid%20profile%20email%20roles
       &state=…&nonce=…&code_challenge=BASE64URL(SHA256(verifier))&code_challenge_method=S256
   ```
   `code_challenge_method=plain` und fehlendes PKCE werden abgelehnt. Ohne `nonce` wird abgelehnt (`oidc.require_nonce`).
2. Benutzer meldet sich an (ggf. MFA) und bestätigt ggf. die Einwilligung. Redirect zurück mit `code`, `state` und `iss`.
3. Anwendung prüft `state` (und `iss` == Issuer) und tauscht den Code (60 s gültig, einmalig):
   ```
   POST /oidc/token   Authorization: Basic base64(client_id:client_secret)
   grant_type=authorization_code&code=…&redirect_uri=…&code_verifier=…
   ```
4. Antwort: `access_token` (JWT, 10 min), `id_token` (JWT), `token_type=Bearer`, `expires_in`, ggf. `refresh_token`.
5. **ID-Token prüfen** (Pflicht): Signatur gegen JWKS (per `kid`, nur RS256), `iss` = Issuer, `aud` enthält Ihre `client_id`,
   `exp`/`iat`/`nbf`, `nonce` = gespeicherter Wert. Referenz: [`examples/rp-php/lib.php`](../examples/rp-php/lib.php) (`verify_id_token`).

## 3. Claims

| Scope | Claims |
|---|---|
| `openid` | `sub` (stabil, pro Benutzer, undurchsichtig), `iss`, `aud`, `exp`, `iat`, `nbf`, `nonce`, `auth_time` |
| `profile` | `name`, `updated_at` |
| `email` | `email`, `email_verified` |
| `roles` | `roles`: Liste der **Rollen dieser Anwendung** (nicht die anderer Apps, keine globalen Admin-Rollen) |

Identifizieren Sie Benutzer über `sub`, **nicht** über die E-Mail-Adresse (sie kann sich ändern).

## 4. Refresh Tokens

Nur mit Scope `offline_access`. Refresh Tokens werden bei jeder Nutzung **rotiert**; die Wiederverwendung eines alten Tokens
widerruft die gesamte Kette. Gesperrte Benutzer/deaktivierte Anwendungen: Token werden ungültig.

## 5. Abmelden

- **Lokal**: eigene Sitzung beenden.
- **SSO-Logout**: `GET /oidc/logout?id_token_hint=…&client_id=…&post_logout_redirect_uri=…&state=…` – der Benutzer bestätigt, danach Redirect auf eine registrierte Post-Logout-URI.
  Eine Anwendung kann nur Abmeldungen auslösen, deren URI registriert ist.
- Es gibt kein Front-/Back-Channel-Logout an die anderen Apps; deren Sitzungen enden mit ihrer eigenen Laufzeit. Halten Sie Sitzungslaufzeiten in Ihrer App kurz.

## 6. Fehler

Fehler im Autorisierungsablauf kommen als `error`/`error_description`/`state`/`iss` an die Redirect-URI zurück
(`access_denied`, `invalid_scope`, `invalid_request`, `login_required` bei `prompt=none`, …). Ist die `redirect_uri` oder `client_id`
ungültig, wird **nie** weitergeleitet, sondern eine Fehlerseite angezeigt.

## 7. Beispiele

- **Referenz-App (PHP, ohne Bibliothek)**: [`examples/rp-php`](../examples/rp-php). Zwei unabhängige Instanzen:
  ```bash
  OIDC_ISSUER=https://auth.vylaris.ch OIDC_CLIENT_ID=vy_AAA OIDC_CLIENT_SECRET=… APP_NAME="App A" \
    REDIRECT_URI=https://app-a.example/callback php -S 0.0.0.0:9001 examples/rp-php/index.php
  OIDC_ISSUER=https://auth.vylaris.ch OIDC_CLIENT_ID=vy_BBB OIDC_CLIENT_SECRET=… APP_NAME="App B" \
    REDIRECT_URI=https://app-b.example/callback php -S 0.0.0.0:9002 examples/rp-php/index.php
  ```
  Nach Login in App A erscheint in App B beim Klick auf „Anmelden“ **kein** erneutes Passwortfenster (SSO-Sitzung).
- **Beliebige Bibliotheken**: Node `openid-client`, Python `Authlib`, Java Spring Security, .NET `Microsoft.AspNetCore.Authentication.OpenIdConnect`,
  PHP `jumbojett/openid-connect-php` – jeweils nur Issuer + Client-ID/Secret + `usePKCE=S256` setzen.

## 8. CORS

Cross-Origin-Aufrufe (Token, UserInfo, JWKS, Discovery) sind nur für Origins erlaubt, die sich aus registrierten Redirect-URIs ergeben.
