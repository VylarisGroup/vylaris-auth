<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Clock, Request, Response, Router};
use Vylaris\Repository\UserRepository;
use Vylaris\Service\{PrivacyException, TokenService};

/**
 * Datenschutz: öffentliche Datenschutzerklärung, Auskunft/Export (Art. 15, 20), Berichtigung der E-Mail (Art. 16),
 * Löschung (Art. 17) für Benutzer sowie Export/Löschung durch Administratoren.
 */
final class PrivacyController extends BaseController
{
    private const TOKEN_TTL = 3600;

    public function routes(Router $r): void
    {
        $r->get('/privacy', fn (Request $q) => $this->notice());
        $r->get('/account/privacy', fn (Request $q) => $this->account($q));
        $r->post('/account/privacy/export', fn (Request $q) => $this->guard($q, fn ($u) => $this->export($q, $u)));
        $r->post('/account/privacy/delete', fn (Request $q) => $this->guard($q, fn ($u) => $this->requestDelete($q, $u)));
        $r->get('/account/privacy/delete/confirm', fn (Request $q) => $this->guard($q, fn ($u) => $this->confirmDeleteForm($q, $u)));
        $r->post('/account/privacy/delete/confirm', fn (Request $q) => $this->guard($q, fn ($u) => $this->confirmDelete($q, $u)));
        $r->post('/account/email', fn (Request $q) => $this->guard($q, fn ($u) => $this->requestEmailChange($q, $u)));
        $r->get('/account/email/confirm', fn (Request $q) => $this->guard($q, fn ($u) => $this->confirmEmailForm($q, $u)));
        $r->post('/account/email/confirm', fn (Request $q) => $this->guard($q, fn ($u) => $this->confirmEmail($q, $u)));
        $r->post('/admin/users/{id}/export', fn (Request $q, array $p) => $this->adminGuard($q, (int) $p['id'], fn ($actor, $target) => $this->adminExport($actor, $target)));
        $r->post('/admin/users/{id}/erase', fn (Request $q, array $p) => $this->adminGuard($q, (int) $p['id'], fn ($actor, $target) => $this->adminErase($q, $actor, $target)));
    }

    // ------------------------------------------------------------------ Datenschutzerklärung (öffentlich)

    private function notice(): Response
    {
        $c = $this->app->config;
        return $this->render('privacy/notice', [
            'title' => 'Datenschutzerklärung',
            'p' => [
                'controller' => $this->app->brand->get('privacy_controller'), 'email' => $this->app->brand->get('privacy_email'),
                'dpo' => $this->app->brand->get('privacy_dpo'), 'authority' => $this->app->brand->get('privacy_authority'),
                'extra' => $this->app->brand->get('privacy_extra'), 'version' => $this->app->brand->get('privacy_version'),
                'auditDays' => max(30, (int) $c->get('security.audit_retention_days', 365)),
                'ipDays' => max(1, (int) $c->get('privacy.ip_retention_days', 30)),
                'inactiveDays' => (int) $c->get('privacy.inactive_account_days', 0),
                'sessionDays' => max(1, (int) round((int) $c->get('session.absolute_ttl', 86400) / 86400)),
            ],
            'providers' => $this->socialProviders(),
        ]);
    }

    // ------------------------------------------------------------------ Hilfen

    /** Eingeloggter Benutzer + Fehlerbehandlung. */
    private function guard(Request $q, callable $fn): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        try {
            return $fn($user);
        } catch (PrivacyException $e) {
            return $this->accountPage($user, [$e->getMessage()], 422);
        }
    }

    /** Bestätigung per Passwort (sofern vorhanden); gedrosselt gegen Raten. */
    private function reauth(Request $q, array $user): void
    {
        if ((int) $user['password_set'] !== 1) {
            return;
        }
        if (!$this->app->limiter->hit('privacy-reauth:' . $user['id'], 10, 3600)) {
            throw new PrivacyException('Zu viele Versuche. Bitte später erneut versuchen.');
        }
        if (!$this->app->passwords->verify($q->str('password'), (string) $user['password_hash'])) {
            $this->app->audit->log('privacy.reauth_failed', (int) $user['id']);
            throw new PrivacyException('Das Passwort ist nicht korrekt.');
        }
    }

    private function account(Request $q): Response
    {
        $user = $this->requireUser($q);
        return $user instanceof Response ? $user : $this->accountPage($user);
    }

    private function accountPage(array $user, array $errors = [], int $status = 200): Response
    {
        $s = $this->app->session();
        $uid = (int) $user['id'];
        return $this->render('account/privacy', [
            'user' => $user, 'errors' => $errors, 'flash' => $s->flash('ok'),
            'blocker' => $this->app->privacy->erasureBlocker($uid),
            'counts' => [
                'sessions' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?', [$uid, Clock::db()]),
                'apps' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM oauth_consents WHERE user_id = ?', [$uid]),
                'social' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM social_identities WHERE user_id = ?', [$uid]),
            ],
        ], $status);
    }

    // ------------------------------------------------------------------ Auskunft / Export (Art. 15, 20)

    private function export(Request $q, array $user): Response
    {
        $this->reauth($q, $user);
        $uid = (int) $user['id'];
        $this->app->audit->log('privacy.export', $uid);
        return $this->download($this->app->privacy->exportJson($uid), 'datenexport-' . $uid . '-' . gmdate('Ymd', Clock::now()) . '.json');
    }

    private function download(string $json, string $filename): Response
    {
        return new Response(200, $json, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------------------------------ Löschung (Art. 17)

    private function requestDelete(Request $q, array $user): Response
    {
        $this->reauth($q, $user);
        $uid = (int) $user['id'];
        if (($why = $this->app->privacy->erasureBlocker($uid)) !== null) {
            throw new PrivacyException($why);
        }
        $token = $this->app->tokens->create($uid, TokenService::DELETE_ACCOUNT, self::TOKEN_TTL);
        $this->app->mailer->queue((string) $user['email'], 'Konto bei {app} löschen – Bestätigung', "Sie haben die Löschung Ihres Kontos bei {app} angefordert.\n\nZum endgültigen Löschen bestätigen Sie bitte (1 Stunde gültig, Anmeldung erforderlich):\n" . $this->app->config->baseUrl() . '/account/privacy/delete/confirm?token=' . $token . "\n\nDie Löschung ist unwiderruflich. Wenn Sie das nicht waren, ignorieren Sie diese Nachricht und ändern Sie Ihr Passwort.\n");
        $this->app->audit->log('privacy.erasure_requested', $uid);
        $this->app->session()->flash('ok', 'Wir haben Ihnen eine Bestätigungs-E-Mail geschickt. Erst nach Klick auf den Link wird das Konto gelöscht.');
        return $this->redirect('/account/privacy');
    }

    private function confirmDeleteForm(Request $q, array $user): Response
    {
        $t = $q->str('token');
        if ($t === '' || $this->app->tokens->peek($t, TokenService::DELETE_ACCOUNT) !== (int) $user['id']) {
            return $this->render('error', ['title' => 'Link ungültig', 'message' => 'Der Bestätigungslink ist ungültig oder abgelaufen. Bitte fordern Sie die Löschung erneut an.'], 400);
        }
        return $this->render('account/privacy_delete', ['token' => $t, 'user' => $user]);
    }

    private function confirmDelete(Request $q, array $user): Response
    {
        $uid = (int) $user['id'];
        $t = $q->str('token');
        if ($t === '' || $this->app->tokens->peek($t, TokenService::DELETE_ACCOUNT) !== $uid) {
            return $this->render('error', ['title' => 'Link ungültig', 'message' => 'Der Bestätigungslink ist ungültig oder abgelaufen. Bitte fordern Sie die Löschung erneut an.'], 400);
        }
        if ($q->str('confirm') !== '1') {
            return $this->render('account/privacy_delete', ['token' => $t, 'user' => $user, 'errors' => ['Bitte bestätigen Sie die Löschung.']], 422);
        }
        if ($this->app->tokens->consume($t, TokenService::DELETE_ACCOUNT) !== $uid) {
            return $this->render('error', ['title' => 'Link ungültig', 'message' => 'Der Bestätigungslink wurde bereits verwendet.'], 400);
        }
        $this->app->privacy->erase($uid, null, 'user_request');
        $this->app->session()->destroy();
        return $this->render('message', ['title' => 'Konto gelöscht', 'message' => 'Ihr Konto und die zugehörigen Daten wurden gelöscht. Anonymisierte Sicherheitsprotokolle ohne Personenbezug bleiben für die in der Datenschutzerklärung genannte Frist bestehen.', 'icon' => 'bx-check-circle', 'link' => ['/login', 'Zur Anmeldung']]);
    }

    // ------------------------------------------------------------------ Berichtigung E-Mail (Art. 16)

    private function requestEmailChange(Request $q, array $user): Response
    {
        $this->reauth($q, $user);
        $new = UserRepository::normalizeEmail($q->str('new_email'));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 255) {
            throw new PrivacyException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        $uid = (int) $user['id'];
        if ($new !== $user['email'] && $this->app->limiter->hit('email-change:' . $uid, 5, 3600)) {
            if ($this->app->users->findByEmail($new) === null) {
                $token = $this->app->tokens->create($uid, TokenService::CHANGE_EMAIL, self::TOKEN_TTL, $new);
                $this->app->mailer->queue($new, 'Neue E-Mail-Adresse bei {app} bestätigen', "Für ein Konto bei {app} wurde diese Adresse als neue E-Mail-Adresse angegeben.\n\nBestätigen Sie die Änderung (1 Stunde gültig, Anmeldung im Konto erforderlich):\n" . $this->app->config->baseUrl() . '/account/email/confirm?token=' . $token . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht.\n");
            } else {
                $this->app->mailer->queue($new, 'Hinweis zu Ihrer E-Mail-Adresse bei {app}', "Jemand wollte diese Adresse bei {app} einem anderen Konto zuordnen. Sie ist bereits registriert, es wurde nichts geändert.\n");
            }
            $this->app->audit->log('privacy.email_change_requested', $uid);
        }
        // Gleiche Antwort in allen Fällen (keine Kontenerkennung).
        $this->app->session()->flash('ok', 'Falls die Adresse verwendet werden kann, haben wir dorthin einen Bestätigungslink geschickt.');
        return $this->redirect('/account/privacy');
    }

    private function confirmEmailForm(Request $q, array $user): Response
    {
        $t = $this->app->tokens->peekWithPayload($q->str('token'), TokenService::CHANGE_EMAIL);
        if ($t === null || $t['user_id'] !== (int) $user['id']) {
            return $this->render('error', ['title' => 'Link ungültig', 'message' => 'Der Link ist ungültig oder abgelaufen. Bitte melden Sie sich mit dem betroffenen Konto an und fordern Sie die Änderung erneut an.'], 400);
        }
        return $this->render('account/email_confirm', ['token' => $q->str('token'), 'newEmail' => (string) $t['payload']]);
    }

    private function confirmEmail(Request $q, array $user): Response
    {
        $uid = (int) $user['id'];
        $t = $this->app->tokens->peekWithPayload($q->str('token'), TokenService::CHANGE_EMAIL);
        if ($t === null || $t['user_id'] !== $uid || !$t['payload'] || $this->app->tokens->consume($q->str('token'), TokenService::CHANGE_EMAIL) !== $uid) {
            return $this->render('error', ['title' => 'Link ungültig', 'message' => 'Der Link ist ungültig oder abgelaufen.'], 400);
        }
        $new = (string) $t['payload'];
        if ($this->app->users->findByEmail($new) !== null) {
            throw new PrivacyException('Diese E-Mail-Adresse ist inzwischen vergeben.');
        }
        $old = (string) $user['email'];
        $this->app->users->updateEmail($uid, $new);
        $this->app->users->markVerified($uid); // Adresse wurde soeben per Link bestätigt
        $this->app->mailer->queue($old, 'Ihre E-Mail-Adresse bei {app} wurde geändert', "Die E-Mail-Adresse Ihres Kontos bei {app} wurde geändert. Wenn Sie das nicht waren, setzen Sie Ihr Passwort zurück und kontaktieren Sie den Support.\n");
        $this->app->audit->log('privacy.email_changed', $uid);
        $this->app->session()->flash('ok', 'E-Mail-Adresse geändert.');
        return $this->redirect('/account/privacy');
    }

    // ------------------------------------------------------------------ Administration

    private function adminGuard(Request $q, int $targetId, callable $fn): Response
    {
        $actor = $this->requireAdmin($q);
        if ($actor instanceof Response) {
            return $actor;
        }
        $target = $this->app->users->findById($targetId);
        if ($target === null) {
            return $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Benutzer nicht gefunden.'], 404);
        }
        if (!$this->app->policy->canManageUser((int) $actor['id'], $targetId) || (int) $actor['id'] === $targetId) {
            $this->app->audit->log('authz.denied', (int) $actor['id'], ['path' => 'admin_privacy', 'target' => $targetId]);
            return $this->render('error', ['title' => 'Kein Zugriff', 'message' => 'Dafür fehlt Ihnen die Berechtigung. Für das eigene Konto nutzen Sie bitte „Konto → Datenschutz“.'], 403);
        }
        return $fn($actor, $target);
    }

    private function adminExport(array $actor, array $target): Response
    {
        $this->app->audit->log('privacy.export_by_admin', (int) $target['id'], [], (int) $actor['id']);
        return $this->download($this->app->privacy->exportJson((int) $target['id']), 'datenexport-' . (int) $target['id'] . '-' . gmdate('Ymd', Clock::now()) . '.json');
    }

    private function adminErase(Request $q, array $actor, array $target): Response
    {
        if (!hash_equals(strtolower((string) $target['email']), strtolower(trim($q->str('confirm_email'))))) {
            $this->app->session()->flash('err', 'Zur Bestätigung muss die E-Mail-Adresse des Kontos exakt eingegeben werden.');
            return $this->redirect('/admin/users/' . (int) $target['id']);
        }
        try {
            $this->app->privacy->erase((int) $target['id'], (int) $actor['id'], 'admin');
        } catch (PrivacyException $e) {
            $this->app->session()->flash('err', $e->getMessage());
            return $this->redirect('/admin/users/' . (int) $target['id']);
        }
        $this->app->session()->flash('ok', 'Konto und zugehörige Daten wurden gelöscht.');
        return $this->redirect('/admin/users');
    }
}
