<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{App, Request, Response};

abstract class BaseController
{
    public function __construct(protected App $app)
    {
    }

    protected function render(string $tpl, array $data = [], int $status = 200): Response
    {
        return $this->app->page($tpl, $data, $status);
    }

    /** Aktive Social-Login-Anbieter für Login-Seiten. Fehlertolerant (z. B. Migration 002 noch nicht eingespielt). */
    protected function socialProviders(): array
    {
        try {
            return $this->app->social->providers(true);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** @return array|Response Benutzer oder Redirect zum Login (mit Rücksprungziel). */
    protected function requireUser(Request $r): array|Response
    {
        $user = $this->app->currentUser();
        if ($user === null) {
            $next = $r->method === 'GET' ? $r->path . ($r->query ? '?' . http_build_query($r->query) : '') : '/account';
            return $this->redirect('/login?next=' . rawurlencode($next));
        }
        return $user;
    }

    /** Admin-Zugriff: serverseitig bei jeder Aktion, nicht nur im Menü. */
    protected function requireAdmin(Request $r): array|Response
    {
        $user = $this->requireUser($r);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->policy->isAdmin((int) $user['id'])) {
            $this->app->audit->log('authz.denied', (int) $user['id'], ['path' => $r->path]);
            return $this->render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt Ihnen die Berechtigung.'], 403);
        }
        // Administratoren müssen sich mit einem zweiten Faktor angemeldet haben.
        if ($this->app->config->get('security.admin_requires_mfa', true)
            && !in_array('mfa', (array) $this->app->session()->get('amr', []), true)) {
            $this->app->session()->flash('ok', 'Für den Admin-Bereich ist eine Anmeldung mit zweitem Faktor erforderlich. Richten Sie zuerst eine Authenticator-App oder einen Passkey ein und melden Sie sich neu an.');
            return $this->redirect($this->app->auth->hasMfa((int) $user['id']) ? '/account' : '/account/mfa/totp');
        }
        return $user;
    }
}
