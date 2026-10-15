<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Security\Redirect;
use Vylaris\Service\WebAuthn\WebAuthnException;

/** MFA-Prüfung beim Login, Passkey-Login und Verwaltung der Faktoren im Konto. */
final class MfaController extends BaseController
{
    public function routes(Router $r): void
    {
        // Login-Schritt
        $r->get('/mfa', fn (Request $q) => $this->challengePage($q));
        $r->post('/mfa/totp', fn (Request $q) => $this->verifyTotp($q));
        $r->post('/mfa/recovery', fn (Request $q) => $this->verifyRecovery($q));
        $r->post('/mfa/webauthn/options', fn (Request $q) => $this->mfaPasskeyOptions($q));
        $r->post('/mfa/webauthn/verify', fn (Request $q) => $this->mfaPasskeyVerify($q));
        // Passwortloser Login
        $r->post('/login/passkey/options', fn (Request $q) => $this->loginPasskeyOptions($q));
        $r->post('/login/passkey/verify', fn (Request $q) => $this->loginPasskeyVerify($q));
        // Verwaltung
        $r->get('/account/mfa/totp', fn (Request $q) => $this->totpPage($q));
        $r->post('/account/mfa/totp/start', fn (Request $q) => $this->totpStart($q));
        $r->post('/account/mfa/totp/confirm', fn (Request $q) => $this->totpConfirm($q));
        $r->post('/account/mfa/totp/disable', fn (Request $q) => $this->totpDisable($q));
        $r->get('/account/mfa/passkeys', fn (Request $q) => $this->passkeyPage($q));
        $r->post('/account/mfa/passkeys/options', fn (Request $q) => $this->registerOptions($q));
        $r->post('/account/mfa/passkeys/register', fn (Request $q) => $this->registerFinish($q));
        $r->post('/account/mfa/passkeys/delete', fn (Request $q) => $this->passkeyDelete($q));
        $r->get('/account/mfa/recovery', fn (Request $q) => $this->recoveryPage($q));
        $r->post('/account/mfa/recovery/regenerate', fn (Request $q) => $this->recoveryRegenerate($q));
    }

    // ------------------------------------------------------------------ Login-Schritt

    private function pending(): ?int
    {
        return $this->app->auth->pendingMfaUser($this->app->session());
    }

    private function challengePage(Request $q, ?string $error = null, int $status = 200): Response
    {
        $uid = $this->pending();
        if ($uid === null) {
            return $this->redirect('/login');
        }
        return $this->render('mfa/challenge', [
            'error' => $error,
            'hasTotp' => $this->app->mfa->hasTotp($uid),
            'hasPasskey' => $this->app->passkeys->count($uid) > 0,
        ], $status);
    }

    private function finish(int $uid, array $amr): Response
    {
        $session = $this->app->session();
        $next = Redirect::safeLocal((string) $session->get('mfa_next', ''), '/account');
        // Erster Faktor kann Passwort ('pwd') oder ein externer Anbieter ('fed') gewesen sein.
        $first = array_values(array_filter((array) $session->get('mfa_first_amr', ['pwd']), 'is_string')) ?: ['pwd'];
        $amr = array_values(array_unique(array_merge($first, array_diff($amr, ['pwd']))));
        $this->app->auth->establishSession($session, $uid, $amr);
        $session->forget('mfa_next');
        $session->forget('mfa_first_amr');
        $this->app->audit->log('auth.login', $uid, ['amr' => implode(',', $amr)]);
        return $this->redirect($next);
    }

    private function verifyTotp(Request $q): Response
    {
        $uid = $this->pending();
        if ($uid === null) {
            return $this->redirect('/login');
        }
        return $this->app->mfa->verifyTotp($uid, $q->str('code'))
            ? $this->finish($uid, ['pwd', 'otp', 'mfa'])
            : $this->challengePage($q, 'Der Code ist ungültig oder abgelaufen.', 401);
    }

    private function verifyRecovery(Request $q): Response
    {
        $uid = $this->pending();
        if ($uid === null) {
            return $this->redirect('/login');
        }
        return $this->app->mfa->consumeRecoveryCode($uid, $q->str('code'))
            ? $this->finish($uid, ['pwd', 'mfa'])
            : $this->challengePage($q, 'Der Recovery-Code ist ungültig oder wurde bereits verwendet.', 401);
    }

    private function mfaPasskeyOptions(Request $q): Response
    {
        $uid = $this->pending();
        if ($uid === null) {
            return Response::json(['error' => 'Sitzung abgelaufen'], 401);
        }
        return Response::json($this->app->passkeys->authenticationOptions($this->app->session(), 'mfa', $uid));
    }

    private function mfaPasskeyVerify(Request $q): Response
    {
        $uid = $this->pending();
        if ($uid === null) {
            return Response::json(['error' => 'Sitzung abgelaufen'], 401);
        }
        if (!$this->app->limiter->hit('passkey-mfa:' . $uid, 10, 900)) {
            return Response::json(['error' => 'Zu viele Versuche'], 429);
        }
        try {
            $credUid = $this->app->passkeys->finishAuthentication($this->app->session(), 'mfa', $q->json()['credential'] ?? []);
            if ($credUid !== $uid) {
                throw new WebAuthnException('Falsches Konto.');
            }
        } catch (WebAuthnException) {
            $this->app->audit->log('mfa.passkey_failed', $uid);
            return Response::json(['error' => 'Passkey konnte nicht bestätigt werden.'], 401);
        }
        $r = $this->finish($uid, ['pwd', 'hwk', 'mfa']);
        return Response::json(['redirect' => $r->headers['Location']]);
    }

    // ------------------------------------------------------------------ Passkey-Login (passwortlos)

    private function loginPasskeyOptions(Request $q): Response
    {
        if (!$this->app->limiter->hit('passkey-opts:' . $q->ip(), 60, 900)) {
            return Response::json(['error' => 'Zu viele Versuche'], 429);
        }
        $session = $this->app->session();
        $next = Redirect::safeLocal((string) ($q->json()['next'] ?? ''), '');
        $session->set('passkey_next', $next);
        return Response::json($this->app->passkeys->authenticationOptions($session, 'login'));
    }

    private function loginPasskeyVerify(Request $q): Response
    {
        if (!$this->app->limiter->hit('passkey-login:' . $q->ip(), 20, 900)) {
            return Response::json(['error' => 'Zu viele Versuche'], 429);
        }
        $session = $this->app->session();
        try {
            $uid = $this->app->passkeys->finishAuthentication($session, 'login', $q->json()['credential'] ?? []);
        } catch (WebAuthnException) {
            $this->app->audit->log('auth.passkey_login_failed');
            return Response::json(['error' => 'Anmeldung mit Passkey fehlgeschlagen.'], 401);
        }
        $user = $this->app->users->findById($uid);
        if (!$user || ($this->app->config->get('security.require_email_verification', true) && $user['email_verified_at'] === null)) {
            return Response::json(['error' => 'Konto nicht freigegeben.'], 403);
        }
        $next = Redirect::safeLocal((string) $session->get('passkey_next', ''), '/account');
        $this->app->users->recordLoginSuccess($uid);
        $this->app->auth->establishSession($session, $uid, ['hwk', 'user', 'mfa']);
        $session->forget('passkey_next');
        $this->app->audit->log('auth.login', $uid, ['amr' => 'hwk']);
        return Response::json(['redirect' => $next]);
    }

    // ------------------------------------------------------------------ Verwaltung

    /** Sensible Änderungen verlangen erneute Passwortbestätigung (rate-limitiert). */
    private function confirmPassword(array $user, Request $q): bool
    {
        if (!$this->app->limiter->hit('reauth:' . $user['id'], 5, 900)) {
            return false;
        }
        $ok = $this->app->passwords->verify($q->str('password'), $user['password_hash']);
        if ($ok) {
            $this->app->limiter->clear('reauth:' . $user['id'], 900);
        } else {
            $this->app->audit->log('auth.reauth_failed', (int) $user['id']);
        }
        return $ok;
    }

    private function qr(string $uri): ?string
    {
        try {
            return \Vylaris\Service\QrCode::dataUri($uri);
        } catch (\Throwable) {
            return null;
        }
    }

    private function totpPage(Request $q, array $errors = [], int $status = 200): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $uid = (int) $user['id'];
        $pending = $this->app->mfa->hasTotp($uid) ? null : $this->app->mfa->pendingSetup($uid, $user['email']);
        return $this->render('mfa/totp', [
            'enabled' => $this->app->mfa->hasTotp($uid),
            'pending' => $pending,
            'qr' => $pending ? $this->qr($pending['uri']) : null,
            'errors' => $errors,
            'codes' => $this->app->session()->flash('new_recovery_codes'),
        ], $status);
    }

    private function totpStart(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->mfa->hasTotp((int) $user['id'])) {
            $this->app->mfa->beginTotpSetup((int) $user['id'], $user['email']);
        }
        return $this->redirect('/account/mfa/totp');
    }

    private function totpConfirm(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $codes = $this->app->mfa->confirmTotp((int) $user['id'], $q->str('code'));
        if ($codes === null) {
            return $this->totpPage($q, ['Der Code ist ungültig. Bitte prüfen Sie die Uhrzeit Ihres Geräts und versuchen Sie es erneut.'], 422);
        }
        // Recovery-Codes direkt im Response zeigen (nicht in der Session ablegen).
        return $this->render('mfa/totp', ['enabled' => true, 'pending' => null, 'qr' => null, 'errors' => [], 'codes' => $codes ?: null]);
    }

    private function totpDisable(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->confirmPassword($user, $q)) {
            return $this->totpPage($q, ['Das Passwort ist falsch.'], 403);
        }
        $this->app->mfa->disableTotp((int) $user['id']);
        $this->app->session()->flash('ok', 'Authenticator-App entfernt.');
        return $this->redirect('/account');
    }

    private function passkeyPage(Request $q, array $errors = [], int $status = 200): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        return $this->render('mfa/passkeys', ['passkeys' => $this->app->passkeys->credentials((int) $user['id']), 'errors' => $errors], $status);
    }

    private function registerOptions(Request $q): Response
    {
        $user = $this->app->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Nicht angemeldet'], 401);
        }
        if (!$this->app->license->allows((int) $user['id'], 'passkeys')) {
            $this->app->audit->log('license.feature_denied', (int) $user['id'], ['feature' => 'passkeys']);
            return Response::json(['error' => 'Passkeys sind in Ihrer Lizenz nicht enthalten.'], 403);
        }
        return Response::json($this->app->passkeys->registrationOptions($this->app->session(), (int) $user['id']));
    }

    private function registerFinish(Request $q): Response
    {
        $user = $this->app->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Nicht angemeldet'], 401);
        }
        if (!$this->app->license->allows((int) $user['id'], 'passkeys')) {
            return Response::json(['error' => 'Passkeys sind in Ihrer Lizenz nicht enthalten.'], 403);
        }
        $body = $q->json() ?? [];
        try {
            $this->app->passkeys->finishRegistration($this->app->session(), (int) $user['id'], $body['credential'] ?? [], (string) ($body['name'] ?? ''));
        } catch (WebAuthnException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $codes = $this->app->session()->flash('new_recovery_codes');
        return Response::json(['ok' => true, 'recoveryCodes' => $codes]);
    }

    private function passkeyDelete(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->confirmPassword($user, $q)) {
            return $this->passkeyPage($q, ['Das Passwort ist falsch.'], 403);
        }
        $this->app->passkeys->delete((int) $user['id'], (int) $q->str('id'));
        return $this->redirect('/account/mfa/passkeys');
    }

    private function recoveryPage(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $uid = (int) $user['id'];
        return $this->render('mfa/recovery', [
            'remaining' => $this->app->mfa->recoveryRemaining($uid),
            'hasFactor' => $this->app->auth->hasMfa($uid),
            'codes' => null,
            'errors' => [],
        ]);
    }

    private function recoveryRegenerate(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $uid = (int) $user['id'];
        if (!$this->app->auth->hasMfa($uid)) {
            return $this->redirect('/account/mfa/recovery');
        }
        if (!$this->confirmPassword($user, $q)) {
            return $this->render('mfa/recovery', ['remaining' => $this->app->mfa->recoveryRemaining($uid), 'hasFactor' => true, 'codes' => null, 'errors' => ['Das Passwort ist falsch.']], 403);
        }
        $codes = $this->app->mfa->regenerateRecoveryCodes($uid);
        return $this->render('mfa/recovery', ['remaining' => count($codes), 'hasFactor' => true, 'codes' => $codes, 'errors' => []]);
    }
}
