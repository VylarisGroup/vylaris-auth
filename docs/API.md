# REST-API für externe Anwendungen (`/api/v1`)

Alle Antworten sind JSON (`Cache-Control: no-store`). Fehler: `{"error":"<code>"}`. Aufrufe sind **nicht** cookie-basiert und CSRF-frei;
Authentifizierung erfolgt ausschließlich per Bearer-Token oder Client-Credentials. Rate-Limits: 429 `rate_limited`.

## `GET /api/v1/health`
Öffentlich. `200 {"status":"ok"}`.

## `GET /api/v1/me`
`Authorization: Bearer <access_token>` (Scope `openid`). Liefert dieselben Claims wie UserInfo, gefiltert nach den gewährten Scopes.
```json
{"sub":"…","name":"Anna Muster","email":"anna@example.org","email_verified":true,"roles":["editor"]}
```
Fehler: `401 invalid_request|invalid_token` (mit `WWW-Authenticate: Bearer`).
Token von gesperrten Benutzern, deaktivierten oder widerrufenen Anwendungen gelten sofort als ungültig.

## `GET /api/v1/app/roles`
`Authorization: Basic base64(client_id:client_secret)` – nur **vertrauliche, aktive** Clients.
```json
{"roles":[{"id":1,"name":"editor","description":"Redakteur"}]}
```

## `GET /api/v1/app/users?role=editor&limit=100&offset=0`
Gleiche Client-Authentifizierung. Liefert die **aktiven, nicht gesperrten** Benutzer mit mindestens einer Rolle **dieser** Anwendung
(optional gefiltert nach `role`; `limit` ≤ 200). Die Felder entsprechen den für die Anwendung erlaubten Scopes (`sub`, `name`, `email`, `email_verified`) plus `roles`.
Daten anderer Anwendungen sind nie enthalten.

Fehler: `401 invalid_client` (falsches Secret, unbekannter/öffentlicher/deaktivierter Client; `WWW-Authenticate: Basic`).
Fehlversuche werden im Audit-Log (`api.client_auth_failed`) festgehalten.

## OIDC-Endpunkte
Siehe [OIDC-INTEGRATION.md](OIDC-INTEGRATION.md). `/oidc/revoke` (RFC 7009) und Token-Introspection sind **nicht** implementiert; Tokens widerrufen Sie über Sperrung/Deaktivierung im Admin-Bereich oder durch Benutzer unter *Konto → Verbundene Anwendungen*.
