<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};

final class AccountController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/account', fn (Request $q) => $this->dashboard($q));
        $r->post('/account/profile', fn (Request $q) => $this->profile($q));
        $r->post('/account/password', fn (Request $q) => $this->password($q));
        $r->get('/account/sessions', fn (Request $q) => $this->sessions($q));
        $r->post('/account/sessions/revoke', fn (Request $q) => $this->revoke($q));
        $r->post('/account/sessions/revoke-all', fn (Request $q) => $this->revokeAll($q));
    }

    private function dashboard(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        return $this->render('account/dashboard', [
            'user' => $user,
            'flash' => $this->app->session()->flash('ok'),
            'errors' => [],
            'roles' => $this->app->users->roleNames((int) $user['id']),
            'mfa' => $this->mfaSummary((int) $user['id']),
        ]);
    }

    private function mfaSummary(int $uid): array
    {
        $db = $this->app->db;
        return [
            'totp' => $db->scalar('SELECT 1 FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NOT NULL', [$uid]) !== null,
            'passkeys' => (int) $db->scalar('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$uid]),
            'recovery' => (int) $db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$uid]),
        ];
    }

    private function profile(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $name = trim(preg_replace('/\s+/u', ' ', $q->str('name')) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->render('account/dashboard', [
                'user' => $user, 'flash' => null, 'errors' => ['Der Name muss zwischen 2 und 120 Zeichen lang sein.'],
                'roles' => $this->app->users->roleNames((int) $user['id']), 'mfa' => $this->mfaSummary((int) $user['id']),
            ], 422);
        }
        $this->app->users->updateProfile((int) $user['id'], $name);
        $this->app->audit->log('account.profile_updated', (int) $user['id']);
        $this->app->session()->flash('ok', 'Profil gespeichert.');
        return $this->redirect('/account');
    }

    private function password(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $errors = $this->app->auth->changePassword((int) $user['id'], $q->str('current_password'), $q->str('new_password'), $this->app->session()->idHash());
        if ($errors) {
            return $this->render('account/dashboard', [
                'user' => $user, 'flash' => null, 'errors' => $errors,
                'roles' => $this->app->users->roleNames((int) $user['id']), 'mfa' => $this->mfaSummary((int) $user['id']),
            ], 422);
        }
        $this->app->session()->flash('ok', 'Passwort geändert. Andere Geräte wurden abgemeldet.');
        return $this->redirect('/account');
    }

    private function sessions(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        return $this->render('account/sessions', [
            'sessions' => $this->app->sessions->listForUser((int) $user['id'], $this->app->session()->idHash()),
            'flash' => $this->app->session()->flash('ok'),
        ]);
    }

    private function revoke(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $target = $q->str('session');
        if (preg_match('/^[a-f0-9]{64}$/', $target) && $this->app->sessions->revoke((int) $user['id'], $target)) {
            $this->app->audit->log('session.revoked', (int) $user['id']);
            $this->app->session()->flash('ok', 'Sitzung beendet.');
        }
        return $this->redirect('/account/sessions');
    }

    private function revokeAll(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $n = $this->app->sessions->revokeAll((int) $user['id'], $this->app->session()->idHash());
        $this->app->audit->log('session.revoked_all', (int) $user['id'], ['count' => $n]);
        $this->app->session()->flash('ok', "$n andere Sitzung(en) beendet.");
        return $this->redirect('/account/sessions');
    }
}
