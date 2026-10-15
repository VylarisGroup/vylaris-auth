<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};

/**
 * REST-API für angebundene Anwendungen (alle Antworten JSON, kein Cookie-/CSRF-Kontext).
 *  - /api/v1/health            öffentlich
 *  - /api/v1/me                Bearer Access Token (Scope openid)
 *  - /api/v1/app/roles         Client-Authentifizierung (HTTP Basic, vertraulicher Client)
 *  - /api/v1/app/users         Client-Authentifizierung; Benutzer mit Rolle in dieser Anwendung
 */
final class ApiController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/api/v1/health', fn (Request $q) => Response::json(['status' => 'ok']));
        $r->get('/api/v1/me', fn (Request $q) => $this->me($q));
        $r->get('/api/v1/app/roles', fn (Request $q) => $this->withClient($q, fn (array $c) => $this->roles($c)));
        $r->get('/api/v1/app/users', fn (Request $q) => $this->withClient($q, fn (array $c) => $this->users($q, $c)));
    }

    private function error(string $code, int $status, array $headers = []): Response
    {
        return Response::json(['error' => $code], $status, $headers);
    }

    private function me(Request $q): Response
    {
        if (!$this->app->limiter->hit('api-me:' . $q->ip(), 300, 300)) {
            return $this->error('rate_limited', 429);
        }
        $bad = fn (string $e) => $this->error($e, 401, ['WWW-Authenticate' => 'Bearer error="' . $e . '"']);
        $jwt = $q->bearerToken();
        if ($jwt === null) {
            return $bad('invalid_request');
        }
        $t = $this->app->oauth->validateAccessToken($jwt);
        if ($t === null || !in_array('openid', $t['scopes'], true)) {
            return $bad('invalid_token');
        }
        return Response::json($this->app->claims->forUser($t['user'], $t['scopes'], $t['client']));
    }

    /** Client-Authentifizierung per HTTP Basic (client_id:secret), mit Rate-Limit pro IP und Client. */
    private function withClient(Request $q, callable $fn): Response
    {
        $h = $q->header('Authorization') ?? ($q->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        $challenge = ['WWW-Authenticate' => 'Basic realm="API", charset="UTF-8"'];
        if (!is_string($h) || !preg_match('/^Basic\s+([A-Za-z0-9+\/]+=*)$/', $h, $m)) {
            return $this->error('invalid_client', 401, $challenge);
        }
        $pair = base64_decode($m[1], true);
        if ($pair === false || !str_contains($pair, ':')) {
            return $this->error('invalid_client', 401, $challenge);
        }
        [$id, $secret] = explode(':', $pair, 2);
        $id = rawurldecode($id);
        $secret = rawurldecode($secret);
        if (!$this->app->limiter->hit('api-client-ip:' . $q->ip(), 60, 300)
            || !$this->app->limiter->hit('api-client:' . $id, 120, 300)) {
            return $this->error('rate_limited', 429);
        }
        $client = $this->app->clients->findByClientId($id);
        // Nur vertrauliche, aktive Clients; verifySecret läuft auch bei unbekanntem Client mit konstantem Aufwand ab.
        $ok = $client !== null && (int) $client['is_confidential'] === 1
            && $this->app->clients->verifySecret($client, $secret)
            && $this->app->clients->isUsable($client);
        if (!$ok) {
            if ($client === null) {
                $this->app->clients->verifySecret(['is_confidential' => 1, 'secret_hash' => ''], $secret);
            }
            $this->app->audit->log('api.client_auth_failed', null, [], null, mb_substr($id, 0, 80));
            return $this->error('invalid_client', 401, $challenge);
        }
        return $fn($client);
    }

    private function roles(array $client): Response
    {
        return Response::json(['roles' => $this->app->clients->roles((int) $client['id'])]);
    }

    private function users(Request $q, array $client): Response
    {
        $role = $q->str('role');
        $limit = max(1, min(200, (int) ($q->str('limit', '100'))));
        $offset = max(0, (int) $q->str('offset', '0'));
        $scopes = preg_split('/\s+/', (string) $client['allowed_scopes'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rows = $this->app->db->all(
            "SELECT DISTINCT u.* FROM users u
             JOIN user_app_roles ur ON ur.user_id = u.id
             JOIN app_roles r ON r.id = ur.app_role_id
             WHERE r.client_pk = ? AND u.status = 'active'" . ($role !== '' ? ' AND r.name = ?' : '') . '
             ORDER BY u.id LIMIT ' . $limit . ' OFFSET ' . $offset,
            $role !== '' ? [(int) $client['id'], $role] : [(int) $client['id']]
        );
        $out = [];
        foreach ($rows as $u) {
            if ($this->app->users->isLocked($u)) {
                continue;
            }
            // Nur Claims, die der Client per Scope beanspruchen darf; Rollen immer.
            $out[] = $this->app->claims->forUser($u, array_values(array_unique([...$scopes, 'roles'])), $client);
        }
        return Response::json(['users' => $out, 'limit' => $limit, 'offset' => $offset]);
    }
}
