<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Service\AuthorizationException;

/** Admin-Panel. Jede Route prüft serverseitig Admin-Rechte (und MFA-Anmeldung), Aktionen laufen über AdminService. */
final class AdminController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/admin', fn (Request $q) => $this->guard($q, fn ($u) => $this->dashboard($u)));
        $r->post('/admin/changelog/seen', fn (Request $q) => $this->guard($q, fn ($u) => $this->changelogSeen($u)));
        $r->get('/admin/users/new', fn (Request $q) => $this->guard($q, fn ($u) => $this->render('admin/wizard_user', ['licenseRequired' => $this->app->license->required()])));
        $r->get('/admin/apps/new', fn (Request $q) => $this->guard($q, fn ($u) => $this->denyFeature($u, 'oidc_apps') ?? $this->render('admin/wizard_app')));
        $r->get('/admin/users', fn (Request $q) => $this->guard($q, fn ($u) => $this->users($q, $u)));
        $r->post('/admin/users', fn (Request $q) => $this->guard($q, fn ($u) => $this->createUser($q, $u)));
        $r->get('/admin/users/{id}', fn (Request $q, array $p) => $this->guard($q, fn ($u) => $this->userDetail((int) $p['id'], $u)));
        $r->post('/admin/users/{id}/action', fn (Request $q, array $p) => $this->guard($q, fn ($u) => $this->userAction($q, (int) $p['id'], $u)));
        $r->post('/admin/users/{id}/app-role', fn (Request $q, array $p) => $this->guard($q, fn ($u) => $this->appRole($q, (int) $p['id'], $u)));
        $r->get('/admin/apps', fn (Request $q) => $this->guard($q, fn ($u) => $this->apps()));
        $r->post('/admin/apps', fn (Request $q) => $this->guard($q, fn ($u) => $this->createApp($q, $u)));
        $r->get('/admin/apps/{id}', fn (Request $q, array $p) => $this->guard($q, fn ($u) => $this->appDetail((int) $p['id'])));
        $r->post('/admin/apps/{id}/action', fn (Request $q, array $p) => $this->guard($q, fn ($u) => $this->appAction($q, (int) $p['id'], $u)));
        $r->get('/admin/audit', fn (Request $q) => $this->guard($q, fn ($u) => $this->audit($q, $u)));
        $r->get('/admin/keys', fn (Request $q) => $this->guard($q, fn ($u) => $this->keys($u)));
        $r->post('/admin/keys/rotate', fn (Request $q) => $this->guard($q, fn ($u) => $this->rotateKeys($u)));
    }

    /** Zentrale Zugangsprüfung + einheitliche Fehlerbehandlung der Admin-Aktionen. */
    private function guard(Request $q, callable $fn): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        try {
            return $fn($user);
        } catch (AuthorizationException $e) {
            return $this->render('error', ['title' => 'Aktion nicht erlaubt', 'message' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            // Fehlermeldung anzeigen und zur Ursprungsseite zurück (nur lokaler Pfad, kein Open Redirect).
            $this->app->session()->flash('err', $e->getMessage());
            $path = (string) parse_url($q->path, PHP_URL_PATH);
            $back = preg_match('#^/admin/(users|apps)/\d+#', $path, $m) ? $m[0] : (str_starts_with($path, '/admin/users') ? '/admin/users' : (str_starts_with($path, '/admin/apps') ? '/admin/apps' : '/admin'));
            return $this->redirect($back);
        }
    }

    private function dashboard(array $user): Response
    {
        $upd = $this->app->policy->isSuperAdmin((int) $user['id']) ? $this->app->updater->updateAvailable() : null;
        return $this->render('admin/dashboard', ['updateAvailable' => $upd, 'version' => $this->app->updater->currentVersion(), 'changelog' => $this->app->changelog->unseen((int) $user['id'], $this->app->updater->currentVersion()), 'stats' => $this->app->admin->stats(), 'events' => $this->app->audit->recent(10), 'flash' => $this->app->session()->flash('ok')]);
    }

    private function changelogSeen(array $user): Response
    {
        $this->app->changelog->markSeen((int) $user['id'], $this->app->updater->currentVersion());
        return $this->redirect('/admin');
    }

    private function users(Request $q, array $user): Response
    {
        $term = mb_substr($q->str('q'), 0, 100);
        $page = max(1, (int) $q->str('page', '1'));
        return $this->render('admin/users', [
            'users' => $this->app->users->search($term, 50, ($page - 1) * 50),
            'term' => $term, 'page' => $page, 'licenseRequired' => $this->app->license->required(),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    private function createUser(Request $q, array $actor): Response
    {
        $this->app->admin->createUser((int) $actor['id'], $q->str('email'), $q->str('name'), $q->str('license_key'));
        $this->app->session()->flash('ok', 'Konto angelegt, Einladung wird per E-Mail versendet.');
        return $this->redirect('/admin/users');
    }

    private function userDetail(int $id, array $actor): Response
    {
        $u = $this->app->users->findById($id);
        if ($u === null) {
            return $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Benutzer nicht gefunden.'], 404);
        }
        unset($u['password_hash']);
        $apps = [];
        foreach ($this->app->clients->all() as $c) {
            if ($c['revoked_at'] === null) {
                $apps[] = ['client' => $c, 'roles' => $this->app->clients->roles((int) $c['id']), 'assigned' => $this->app->clients->userRoles($id, (int) $c['id'])];
            }
        }
        $actorId = (int) $actor['id'];
        return $this->render('admin/user', [
            'u' => $u,
            'roles' => $this->app->users->roleNames($id),
            'sessions' => $this->app->sessions->listForUser($id),
            'mfa' => ['totp' => $this->app->mfa->hasTotp($id), 'passkeys' => $this->app->passkeys->count($id)],
            'apps' => $apps,
            'audit' => $this->app->audit->recent(15, null, $id),
            'canManage' => $actorId !== $id && $this->app->policy->canManageUser($actorId, $id),
            'isSuper' => $this->app->policy->isSuperAdmin($actorId),
            'self' => $actorId === $id,
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    private function userAction(Request $q, int $id, array $actor): Response
    {
        $a = (int) $actor['id'];
        $svc = $this->app->admin;
        $msg = match ($q->str('action')) {
            'lock' => (function () use ($svc, $a, $id, $q) { $svc->lock($a, $id, $q->str('reason')); return 'Konto gesperrt, Sitzungen und Tokens widerrufen.'; })(),
            'unlock' => (function () use ($svc, $a, $id) { $svc->unlock($a, $id); return 'Konto entsperrt.'; })(),
            'grant_role' => (function () use ($svc, $a, $id, $q) { $svc->grantRole($a, $id, $q->str('role')); return 'Rolle vergeben.'; })(),
            'revoke_role' => (function () use ($svc, $a, $id, $q) { $svc->revokeRole($a, $id, $q->str('role')); return 'Rolle entzogen.'; })(),
            'revoke_sessions' => (function () use ($svc, $a, $id) { $n = $svc->revokeSessions($a, $id); return "$n Sitzung(en) beendet."; })(),
            'reset_mfa' => (function () use ($svc, $a, $id) { $svc->resetMfa($a, $id); return 'MFA zurückgesetzt, Sitzungen beendet.'; })(),
            default => throw new \InvalidArgumentException('Unbekannte Aktion.'),
        };
        $this->app->session()->flash('ok', $msg);
        return $this->redirect('/admin/users/' . $id);
    }

    private function appRole(Request $q, int $userId, array $actor): Response
    {
        $clientPk = (int) $q->str('client');
        $roleId = (int) $q->str('role');
        if ($this->app->users->findById($userId) === null || $this->app->clients->findByPk($clientPk) === null) {
            throw new \InvalidArgumentException('Unbekannter Benutzer oder Anwendung.');
        }
        if ($q->str('op') === 'unassign') {
            $this->app->clients->unassignRole($userId, $clientPk, $roleId);
        } elseif (!$this->app->clients->assignRole($userId, $clientPk, $roleId)) {
            throw new \InvalidArgumentException('Die Rolle gehört nicht zu dieser Anwendung.');
        }
        $this->app->audit->log('admin.app_role_changed', $userId, ['client_pk' => $clientPk, 'role_id' => $roleId, 'op' => $q->str('op') === 'unassign' ? 'unassign' : 'assign'], (int) $actor['id']);
        $this->app->session()->flash('ok', 'Anwendungsrolle aktualisiert.');
        return $this->redirect('/admin/users/' . $userId);
    }

    private function apps(): Response
    {
        return $this->render('admin/apps', ['apps' => $this->app->clients->all(), 'secret' => null, 'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err')]);
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }

    private function createApp(Request $q, array $actor): Response
    {
        if ($deny = $this->denyFeature($actor, 'oidc_apps')) {
            return $deny;
        }
        $res = $this->app->clients->create(
            $q->str('name'), $this->lines($q->str('redirect_uris')), $this->lines($q->str('logout_uris')),
            $q->str('type') !== 'public', $q->str('scopes', 'openid profile email'), $q->str('skip_consent') === '1', $q->str('access_policy', 'all'), (int) $actor['id']
        );
        // Secret wird genau einmal angezeigt (nicht in der Session abgelegt).
        return $this->render('admin/apps', ['apps' => $this->app->clients->all(), 'secret' => $res, 'flash' => null, 'err' => null], 201);
    }

    private function appDetail(int $id, ?string $newSecret = null): Response
    {
        $c = $this->app->clients->findByPk($id);
        if ($c === null) {
            return $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Anwendung nicht gefunden.'], 404);
        }
        unset($c['secret_hash']);
        return $this->render('admin/app', [
            'c' => $c,
            'redirects' => $this->app->clients->uris($id, 'redirect'),
            'logouts' => $this->app->clients->uris($id, 'post_logout'),
            'roles' => $this->app->clients->roles($id),
            'newSecret' => $newSecret,
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
            'issuer' => $this->app->config->baseUrl(),
        ]);
    }

    private function appAction(Request $q, int $id, array $actor): Response
    {
        $a = (int) $actor['id'];
        $svc = $this->app->clients;
        if ($svc->findByPk($id) === null) {
            return $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Anwendung nicht gefunden.'], 404);
        }
        $flash = null;
        if (in_array($q->str('action'), ['settings', 'uris', 'role_create'], true) && ($deny = $this->denyFeature($actor, 'oidc_apps'))) {
            return $deny; // Ändern erfordert den Scope; Deaktivieren/Widerrufen/Secret-Rotation bleiben immer möglich
        }
        switch ($q->str('action')) {
            case 'settings':
                $svc->updateSettings($id, $q->str('name'), $q->str('scopes'), $q->str('skip_consent') === '1', $q->str('access_policy', 'all'), $a);
                $flash = 'Einstellungen gespeichert.';
                break;
            case 'uris':
                $svc->updateUris($id, $this->lines($q->str('redirect_uris')), $this->lines($q->str('logout_uris')), $a);
                $flash = 'Redirect-URIs gespeichert.';
                break;
            case 'rotate_secret':
                return $this->appDetail($id, $svc->rotateSecret($id, $a));
            case 'deactivate':
                $svc->setActive($id, false, $a);
                $flash = 'Anwendung deaktiviert, Tokens widerrufen.';
                break;
            case 'activate':
                if ($svc->findByPk($id)['revoked_at'] !== null) {
                    throw new \InvalidArgumentException('Widerrufene Anwendungen können nicht reaktiviert werden.');
                }
                $svc->setActive($id, true, $a);
                $flash = 'Anwendung aktiviert.';
                break;
            case 'revoke':
                $svc->revoke($id, $a);
                $flash = 'Anwendung endgültig widerrufen.';
                break;
            case 'role_create':
                $svc->createRole($id, $q->str('role_name'), mb_substr($q->str('role_description'), 0, 255) ?: null);
                $flash = 'Rolle angelegt.';
                break;
            case 'role_delete':
                $svc->deleteRole($id, (int) $q->str('role_id'));
                $flash = 'Rolle gelöscht.';
                break;
            default:
                throw new \InvalidArgumentException('Unbekannte Aktion.');
        }
        $this->app->session()->flash('ok', $flash);
        return $this->redirect('/admin/apps/' . $id);
    }

    private function audit(Request $q, array $actor): Response
    {
        if ($deny = $this->denyFeature($actor, 'audit_log')) {
            return $deny;
        }
        $event = preg_match('/^[a-z0-9_.]{1,80}$/', $q->str('event')) ? $q->str('event') : null;
        $uid = ctype_digit($q->str('user')) && $q->str('user') !== '' ? (int) $q->str('user') : null;
        return $this->render('admin/audit', ['events' => $this->app->audit->recent(200, $event, $uid), 'event' => $event ?? '', 'uid' => $uid]);
    }

    private function keys(array $actor): Response
    {
        if (!$this->app->policy->isSuperAdmin((int) $actor['id'])) {
            throw new AuthorizationException('Nur Superadmins verwalten Signaturschlüssel.');
        }
        $this->app->keys->ensureActiveKey();
        return $this->render('admin/keys', ['keys' => $this->app->db->all('SELECT kid, status, created_at, retired_at FROM oidc_keys ORDER BY created_at DESC'), 'flash' => $this->app->session()->flash('ok')]);
    }

    private function rotateKeys(array $actor): Response
    {
        if (!$this->app->policy->isSuperAdmin((int) $actor['id'])) {
            throw new AuthorizationException('Nur Superadmins verwalten Signaturschlüssel.');
        }
        $k = $this->app->keys->rotate();
        $this->app->audit->log('admin.key_rotated', null, ['kid' => $k['kid']], (int) $actor['id']);
        $this->app->session()->flash('ok', 'Neuer Signaturschlüssel aktiv: ' . $k['kid']);
        return $this->redirect('/admin/keys');
    }
}
