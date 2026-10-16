<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Security\Redirect;

final class AuthController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/', fn (Request $q) => $this->redirect($this->app->currentUser() ? '/account' : '/login'));
        $r->get('/login', fn (Request $q) => $this->loginForm($q));
        $r->post('/login', fn (Request $q) => $this->login($q));
        $r->post('/logout', fn (Request $q) => $this->logout($q));
        $r->get('/register', fn (Request $q) => $this->registerForm($q));
        $r->post('/register', fn (Request $q) => $this->register($q));
        $r->get('/verify-email', fn (Request $q) => $this->verifyEmail($q));
        $r->post('/verify-email/resend', fn (Request $q) => $this->resend($q));
        $r->get('/password/forgot', fn (Request $q) => $this->render('auth/forgot'));
        $r->post('/password/forgot', fn (Request $q) => $this->forgot($q));
        $r->get('/password/reset', fn (Request $q) => $this->resetForm($q));
        $r->post('/password/reset', fn (Request $q) => $this->reset($q));
    }

    private function loginForm(Request $q): Response
    {
        if ($this->app->currentUser()) {
            return $this->redirect(Redirect::safeLocal($q->str('next') ?: null));
        }
        return $this->render('auth/login', ['next' => Redirect::safeLocal($q->str('next') ?: null, ''), 'error' => null, 'email' => '', 'socialProviders' => $this->socialProviders()]);
    }

    private function login(Request $q): Response
    {
        $email = $q->str('email');
        $next = Redirect::safeLocal($q->str('next') ?: null, '');
        $res = $this->app->auth->login($email, $q->str('password'), $q->ip());
        $session = $this->app->session();

        switch ($res->status) {
            case 'ok':
                $this->app->auth->establishSession($session, (int) $res->userId, ['pwd']);
                return $this->redirect($next !== '' ? $next : '/account');
            case 'mfa_required':
                $this->app->auth->startMfaChallenge($session, (int) $res->userId);
                $session->set('mfa_next', $next);
                return $this->redirect('/mfa');
            case 'throttled':
                $msg = 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es dann erneut.';
                $code = 429;
                break;
            case 'unverified':
                $msg = 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse. Den Link finden Sie in Ihrem Postfach.';
                $code = 403;
                break;
            case 'locked':
                $msg = 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an den Support.';
                $code = 403;
                break;
            default:
                $msg = 'E-Mail-Adresse oder Passwort ist falsch.';
                $code = 401;
        }
        return $this->render('auth/login', ['next' => $next, 'error' => $msg, 'email' => $email, 'unverified' => $res->status === 'unverified', 'socialProviders' => $this->socialProviders()], $code);
    }

    private function logout(Request $q): Response
    {
        $this->app->auth->logout($this->app->session());
        return $this->redirect('/login');
    }

    private function registerForm(Request $q): Response
    {
        return $this->render('auth/register', ['errors' => [], 'old' => ['email' => '', 'name' => '', 'license_key' => ''], 'licenseRequired' => $this->app->license->required(), 'licenseOptional' => $this->app->license->hasPool()]);
    }

    private function register(Request $q): Response
    {
        $res = $this->app->auth->register($q->str('email'), $q->str('password'), $q->str('name'), $q->ip(), $q->str('accept_privacy') === '1', $this->app->brand->get('privacy_version'), $q->str('license_key'));
        if (!$res['ok']) {
            return $this->render('auth/register', ['errors' => $res['errors'], 'old' => ['email' => $q->str('email'), 'name' => $q->str('name'), 'license_key' => $q->str('license_key')], 'licenseRequired' => $this->app->license->required(), 'licenseOptional' => $this->app->license->hasPool()], 422);
        }
        return $this->render('message', [
            'title' => 'Fast geschafft',
            'message' => 'Wir haben Ihnen eine E-Mail geschickt. Bitte folgen Sie dem Link darin, um Ihr Konto zu aktivieren.',
            'icon' => 'bx-envelope',
        ]);
    }

    private function verifyEmail(Request $q): Response
    {
        $ok = $this->app->auth->verifyEmail($q->str('token'));
        return $this->render('message', $ok
            ? ['title' => 'E-Mail bestätigt', 'message' => 'Ihre E-Mail-Adresse wurde bestätigt. Sie können sich jetzt anmelden.', 'icon' => 'bx-check-circle', 'link' => ['/login', 'Zur Anmeldung']]
            : ['title' => 'Link ungültig', 'message' => 'Der Bestätigungslink ist ungültig oder abgelaufen. Sie können auf der Anmeldeseite einen neuen anfordern.', 'icon' => 'bx-error-circle', 'link' => ['/login', 'Zur Anmeldung']], $ok ? 200 : 400);
    }

    private function resend(Request $q): Response
    {
        $this->app->auth->resendVerification($q->str('email'), $q->ip());
        return $this->render('message', ['title' => 'Gesendet', 'message' => 'Falls ein unbestätigtes Konto mit dieser Adresse existiert, wurde eine neue E-Mail verschickt.', 'icon' => 'bx-envelope']);
    }

    private function forgot(Request $q): Response
    {
        $this->app->auth->requestPasswordReset($q->str('email'), $q->ip());
        return $this->render('message', ['title' => 'Anleitung gesendet', 'message' => 'Falls ein Konto mit dieser Adresse existiert, haben wir eine E-Mail mit weiteren Schritten geschickt.', 'icon' => 'bx-envelope']);
    }

    private function resetForm(Request $q): Response
    {
        $token = $q->str('token');
        if ($this->app->tokens->peek($token, 'reset_password') === null) {
            return $this->render('message', ['title' => 'Link ungültig', 'message' => 'Der Link ist ungültig oder abgelaufen.', 'icon' => 'bx-error-circle', 'link' => ['/password/forgot', 'Neuen Link anfordern']], 400);
        }
        return $this->render('auth/reset', ['token' => $token, 'errors' => []]);
    }

    private function reset(Request $q): Response
    {
        $errors = $this->app->auth->resetPassword($q->str('token'), $q->str('password'));
        if ($errors) {
            return $this->render('auth/reset', ['token' => $q->str('token'), 'errors' => $errors], 422);
        }
        return $this->render('message', ['title' => 'Passwort geändert', 'message' => 'Ihr Passwort wurde geändert. Alle bisherigen Sitzungen wurden beendet.', 'icon' => 'bx-check-circle', 'link' => ['/login', 'Zur Anmeldung']]);
    }
}
