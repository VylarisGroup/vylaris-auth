<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Security\Redirect;
use Vylaris\Social\{SocialException, SocialService};

/** Social Login: Anmeldung, Verknüpfung im Konto und Anbieter-Verwaltung im Admin-Bereich. */
final class SocialController extends BaseController
{
    private const KEY = '/^[a-z0-9][a-z0-9-]{1,39}$/';

    public function routes(Router $r): void
    {
        $r->get('/social/{provider}/login', fn (Request $q, array $p) => $this->start($q, $p['provider']));
        $r->get('/social/{provider}/callback', fn (Request $q, array $p) => $this->callback($q, $p['provider']));
        $r->get('/account/social', fn (Request $q) => $this->accountPage($q));
        $r->post('/account/social/{provider}/link', fn (Request $q, array $p) => $this->link($q, $p['provider']));
        $r->post('/account/social/{provider}/unlink', fn (Request $q, array $p) => $this->unlink($q, $p['provider']));
        $r->get('/admin/social', fn (Request $q) => $this->adminGuard($q, fn () => $this->adminIndex()));
        $r->post('/admin/social', fn (Request $q) => $this->adminGuard($q, fn ($u) => $this->adminSave($q, $u, null)));
        $r->get('/admin/social/new', fn (Request $q) => $this->adminGuard($q, fn ($u) => $this->denyFeature($u, 'social_login') ?? $this->render('admin/wizard_social', ['types' => SocialService::TYPES])));
        $r->get('/admin/social/{provider}', fn (Request $q, array $p) => $this->adminGuard($q, fn () => $this->adminEdit($p['provider'])));
        $r->post('/admin/social/{provider}', fn (Request $q, array $p) => $this->adminGuard($q, fn ($u) => $this->adminSave($q, $u, $p['provider'])));
        $r->post('/admin/social/{provider}/action', fn (Request $q, array $p) => $this->adminGuard($q, fn ($u) => $this->adminAction($q, $u, $p['provider'])));
    }

    // ------------------------------------------------------------------ Anmeldung

    private function start(Request $q, string $key): Response
    {
        if (!preg_match(self::KEY, $key)) {
            return $this->failPage('Anmeldung nicht verfügbar', 'Dieser Anmeldeweg ist nicht verfügbar.', 404);
        }
        $next = Redirect::safeLocal($q->str('next') ?: null, '');
        if ($this->app->currentUser()) {
            return $this->redirect($next !== '' ? $next : '/account');
        }
        if (!$this->app->limiter->hit('social-start:' . $q->ip(), 30, 900)) {
            return $this->failPage('Zu viele Versuche', 'Bitte warten Sie einige Minuten und versuchen Sie es dann erneut.', 429);
        }
        try {
            return $this->redirect($this->app->social->begin($this->app->session(), $key, 'login', null, $next));
        } catch (SocialException $e) {
            $this->app->audit->log('social.failed', null, ['provider' => $key, 'reason' => $e->getMessage(), 'mode' => 'login']);
            return $this->failPage('Anmeldung nicht verfügbar', 'Dieser Anmeldeweg ist derzeit nicht verfügbar.', 404);
        }
    }

    private function callback(Request $q, string $key): Response
    {
        if (!preg_match(self::KEY, $key)) {
            return $this->failPage('Anmeldung nicht verfügbar', 'Dieser Anmeldeweg ist nicht verfügbar.', 404);
        }
        if (!$this->app->limiter->hit('social-cb:' . $q->ip(), 40, 900)) {
            return $this->failPage('Zu viele Versuche', 'Bitte warten Sie einige Minuten und versuchen Sie es dann erneut.', 429);
        }
        $session = $this->app->session();
        $user = $this->app->currentUser();
        $flow = $session->get('social_flow');
        // Wer bereits angemeldet ist, wechselt nicht per Anbieter-Login das Konto.
        if ($user !== null && is_array($flow) && ($flow['mode'] ?? '') !== 'link') {
            $session->forget('social_flow');
            return $this->redirect('/account');
        }

        $res = $this->app->social->complete($session, $key, $q->query, $user ? (int) $user['id'] : null);

        if ($res->status === 'error') {
            if ($res->mode === 'link' && $user !== null) {
                $session->flash('err', $res->message);
                return $this->redirect('/account/social');
            }
            return $this->failPage('Anmeldung nicht möglich', $res->message, 400);
        }
        if ($res->status === 'linked') {
            $session->flash('ok', $res->message !== '' ? $res->message : 'Anbieter verknüpft.');
            return $this->redirect('/account/social');
        }

        $uid = (int) $res->userId;
        $next = $res->next;
        if ($this->app->auth->hasMfa($uid)) {
            // Auch bei externem Login bleibt der eigene zweite Faktor verbindlich.
            $this->app->auth->startMfaChallenge($session, $uid, ['fed']);
            $session->set('mfa_next', $next);
            return $this->redirect('/mfa');
        }
        $this->app->auth->establishSession($session, $uid, ['fed']);
        $this->app->audit->log('auth.login', $uid, ['amr' => 'fed', 'provider' => $key]);
        return $this->redirect($next !== '' ? $next : '/account');
    }

    private function failPage(string $title, string $message, int $status): Response
    {
        return $this->render('message', ['title' => $title, 'message' => $message, 'icon' => 'bx-error-circle', 'link' => ['/login', 'Zur Anmeldung']], $status);
    }

    // ------------------------------------------------------------------ Konto: Verknüpfungen

    private function accountPage(Request $q, array $errors = [], int $status = 200): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $uid = (int) $user['id'];
        $linked = $this->app->social->identitiesFor($uid);
        $linkedKeys = array_column($linked, 'provider_key');
        return $this->render('account/social', [
            'identities' => $linked,
            'available' => array_values(array_filter($this->app->social->providers(true), static fn (array $p) => !in_array($p['provider_key'], $linkedKeys, true))),
            'passwordSet' => (int) $user['password_set'] === 1,
            'flash' => $this->app->session()->flash('ok'),
            'errors' => array_merge($errors, ($f = $this->app->session()->flash('err')) ? [$f] : []),
        ], $status);
    }

    /** Sensible Änderungen verlangen die Passwortbestätigung, sofern ein Passwort gesetzt ist (rate-limitiert). */
    private function confirmed(array $user, Request $q): bool
    {
        if ((int) $user['password_set'] !== 1) {
            return true;
        }
        $key = 'social-confirm:' . $user['id'];
        if ($this->app->limiter->tooMany($key, 5, 900)) {
            return false;
        }
        if ($this->app->passwords->verify($q->str('password'), $user['password_hash'])) {
            return true;
        }
        $this->app->limiter->hit($key, 5, 900);
        $this->app->audit->log('social.confirm_failed', (int) $user['id']);
        return false;
    }

    private function link(Request $q, string $key): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        if ($deny = $this->denyFeature($user, 'social_login')) {
            return $deny;
        }
        if (!preg_match(self::KEY, $key) || !$this->confirmed($user, $q)) {
            return $this->accountPage($q, ['Die Passwortbestätigung ist falsch oder es gab zu viele Versuche.'], 403);
        }
        try {
            return $this->redirect($this->app->social->begin($this->app->session(), $key, 'link', (int) $user['id'], ''));
        } catch (SocialException) {
            return $this->accountPage($q, ['Dieser Anbieter ist derzeit nicht verfügbar.'], 404);
        }
    }

    private function unlink(Request $q, string $key): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!preg_match(self::KEY, $key) || !$this->confirmed($user, $q)) {
            return $this->accountPage($q, ['Die Passwortbestätigung ist falsch oder es gab zu viele Versuche.'], 403);
        }
        $err = $this->app->social->unlink((int) $user['id'], $key);
        if ($err !== null) {
            return $this->accountPage($q, [$err], 422);
        }
        $this->app->session()->flash('ok', 'Verknüpfung entfernt.');
        return $this->redirect('/account/social');
    }

    // ------------------------------------------------------------------ Admin

    private function adminGuard(Request $q, callable $fn): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        try {
            return $fn($user);
        } catch (\InvalidArgumentException $e) {
            $this->app->session()->flash('err', $e->getMessage());
            $path = (string) preg_replace('#/action$#', '', $q->path);
            return $this->redirect(preg_match('#^/admin/social/[a-z0-9-]+$#', $path) ? $path : '/admin/social');
        }
    }

    private function adminIndex(): Response
    {
        $providers = $this->app->social->providers();
        foreach ($providers as &$p) {
            $p['identities'] = $this->app->social->identityCount($p['provider_key']);
        }
        unset($p);
        return $this->render('admin/social', [
            'providers' => $providers, 'types' => SocialService::TYPES,
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    private function adminEdit(string $key): Response
    {
        $p = preg_match(self::KEY, $key) ? $this->app->social->provider($key) : null;
        if ($p === null) {
            return $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Anbieter nicht gefunden.'], 404);
        }
        unset($p['client_secret_enc']);
        return $this->render('admin/social_edit', [
            'p' => $p, 'types' => SocialService::TYPES, 'redirectUri' => $this->app->social->redirectUri($key),
            'identities' => $this->app->social->identityCount($key), 'failures' => $this->recentFailures($key),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    /** Letzte fehlgeschlagene Anmeldungen über diesen Anbieter (aus dem Audit-Log) mit verständlicher Erklärung. */
    private function recentFailures(string $key): array
    {
        $out = [];
        foreach ($this->app->audit->recent(200, 'social.failed') as $row) {
            $d = json_decode((string) $row['details'], true);
            if (!is_array($d) || ($d['provider'] ?? '') !== $key) {
                continue;
            }
            $reason = (string) ($d['reason'] ?? '');
            $out[] = ['at' => $row['created_at'], 'mode' => (string) ($d['mode'] ?? ''), 'reason' => $reason, 'hint' => self::explain($reason)];
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    private static function explain(string $r): string
    {
        return match (true) {
            str_contains($r, 'invalid_client') => 'Client-ID oder Client-Secret stimmen nicht – im Entwicklerportal des Anbieters prüfen (Secret neu erzeugen und hier eintragen).',
            str_contains($r, 'invalid_grant') => 'Der Code wurde abgelehnt: Die Redirect-URI beim Anbieter muss exakt der oben angezeigten entsprechen (https, kein Slash am Ende).',
            str_contains($r, 'redirect_uri') => 'Die Redirect-URI beim Anbieter muss exakt der oben angezeigten entsprechen.',
            (bool) preg_match('/http_(403|1020)$/', $r) => 'Der Anbieter blockiert die Anfrage dieses Servers (z. B. Cloudflare-Schutz gegen Hosting-Adressen). Anbieter-Support oder anderen Ausgangs-IP-Bereich prüfen.',
            (bool) preg_match('/http_429$/', $r) => 'Der Anbieter begrenzt Anfragen von diesem Server (zu viele Versuche). Etwas warten und erneut versuchen.',
            str_contains($r, 'http_failed') => 'Der Anbieter war vom Server aus nicht erreichbar (Firewall, DNS oder Zeitüberschreitung).',
            in_array($r, ['state_mismatch', 'flow_missing_or_expired'], true) => 'Die Sitzung ging beim Wechsel zum Anbieter verloren (Cookies blockiert, anderer Browser oder zu langsam, Domain mit/ohne www).',
            str_starts_with($r, 'provider_error:') => 'Der Anbieter hat die Anmeldung abgelehnt oder der Nutzer hat abgebrochen.',
            str_starts_with($r, 'discord_user') => 'Discord hat das Profil nicht geliefert. Scopes „identify“ und „email“ müssen freigegeben sein.',
            default => 'Technischer Fehler – Code an den Support weitergeben.',
        };
    }

    private function adminSave(Request $q, array $actor, ?string $key): Response
    {
        if ($deny = $this->denyFeature($actor, 'social_login')) {
            return $deny;
        }
        if ($key !== null && !preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException('Anbieter nicht gefunden.');
        }
        $saved = $this->app->social->saveProvider((int) $actor['id'], [
            'type' => $q->str('type'), 'provider_key' => $q->str('provider_key'), 'label' => $q->str('label'),
            'client_id' => $q->str('client_id'), 'client_secret' => $q->str('client_secret'), 'issuer' => $q->str('issuer'),
            'enabled' => $q->str('enabled') === '1', 'allow_signup' => $q->str('allow_signup') === '1',
        ], $key);
        $this->app->session()->flash('ok', 'Anbieter gespeichert.');
        return $this->redirect('/admin/social/' . $saved);
    }

    private function adminAction(Request $q, array $actor, string $key): Response
    {
        if (!preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException('Anbieter nicht gefunden.');
        }
        $uid = (int) $actor['id'];
        switch ($q->str('action')) {
            case 'enable':
                $this->app->social->setEnabled($uid, $key, true);
                break;
            case 'disable':
                $this->app->social->setEnabled($uid, $key, false);
                break;
            case 'delete':
                if ($q->str('confirm') !== '1') {
                    throw new \InvalidArgumentException('Bitte das Löschen bestätigen.');
                }
                $this->app->social->deleteProvider($uid, $key);
                $this->app->session()->flash('ok', 'Anbieter gelöscht.');
                return $this->redirect('/admin/social');
            default:
                throw new \InvalidArgumentException('Unbekannte Aktion.');
        }
        $this->app->session()->flash('ok', 'Gespeichert.');
        return $this->redirect('/admin/social');
    }
}
