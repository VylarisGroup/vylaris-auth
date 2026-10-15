<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\Update\UpdateException;

/** Aktualisierung aus GitHub-Releases – nur Superadmins (der Vorgang ersetzt Programmcode). */
final class UpdateController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/admin/update', fn (Request $q) => $this->guard($q, fn ($u) => $this->index()));
        $r->post('/admin/update/check', fn (Request $q) => $this->guard($q, fn ($u) => $this->check($u)));
        $r->post('/admin/update/install', fn (Request $q) => $this->guard($q, fn ($u) => $this->install($q, $u)));
        $r->get('/admin/update/finish', fn (Request $q) => $this->guard($q, fn ($u) => $this->finish($u)));
        $r->post('/admin/update/rollback', fn (Request $q) => $this->guard($q, fn ($u) => $this->rollback($q, $u)));
    }

    private function guard(Request $q, callable $fn): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->policy->isSuperAdmin((int) $user['id'])) {
            $this->app->audit->log('authz.denied', (int) $user['id'], ['path' => $q->path]);
            return $this->render('error', ['title' => 'Kein Zugriff', 'message' => 'Updates dürfen nur Superadmins durchführen.'], 403);
        }
        return $fn($user);
    }

    private function index(): Response
    {
        $u = $this->app->updater;
        return $this->render('admin/update', [
            'configured' => $u->configured(), 'current' => $u->currentVersion(), 'latest' => $u->latest(), 'available' => $u->updateAvailable(),
            'pending' => $u->pending(), 'locked' => $u->locked(), 'last' => $u->last(), 'backups' => $u->backups(),
            'repo' => (string) $this->app->config->get('update.repo', ''), 'private' => (string) $this->app->config->get('update.token', '') !== '',
            'zip' => class_exists(\ZipArchive::class),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    private function back(?string $ok = null, ?string $err = null): Response
    {
        if ($ok !== null) {
            $this->app->session()->flash('ok', $ok);
        }
        if ($err !== null) {
            $this->app->session()->flash('err', $err);
        }
        return $this->redirect('/admin/update');
    }

    private function check(array $user): Response
    {
        if (!$this->app->limiter->hit('update-check:' . (int) $user['id'], 10, 3600)) {
            return $this->back(null, 'Bitte später erneut versuchen.');
        }
        try {
            $rel = $this->app->updater->check();
        } catch (UpdateException $e) {
            return $this->back(null, $e->getMessage());
        }
        $this->app->audit->log('update.checked', (int) $user['id'], ['latest' => $rel['version']]);
        return $this->back($this->app->updater->updateAvailable() ? 'Neue Version ' . $rel['version'] . ' verfügbar.' : 'Sie nutzen die aktuelle Version.');
    }

    private function install(Request $q, array $user): Response
    {
        $uid = (int) $user['id'];
        try {
            $r = $this->app->updater->install($q->str('version'));
        } catch (UpdateException $e) {
            $this->app->audit->log('update.failed', $uid, ['reason' => mb_substr($e->getMessage(), 0, 250)]);
            return $this->back(null, $e->getMessage());
        }
        $this->app->audit->log('update.installed', $uid, ['from' => $r['from'], 'to' => $r['to']]);
        // Abschluss (Migrationen) läuft in einer neuen Anfrage mit dem neuen Code.
        return $this->redirect('/admin/update/finish');
    }

    private function finish(array $user): Response
    {
        $uid = (int) $user['id'];
        try {
            $r = $this->app->updater->finish();
        } catch (UpdateException $e) {
            $this->app->audit->log('update.rolled_back', $uid, ['reason' => mb_substr($e->getMessage(), 0, 250)]);
            return $this->back(null, $e->getMessage());
        }
        $this->app->audit->log('update.completed', $uid, ['from' => $r['from'], 'to' => $r['to'], 'migrations' => count($r['migrations'])]);
        return $this->back('Update auf Version ' . $r['to'] . ' abgeschlossen' . ($r['migrations'] ? ' (' . count($r['migrations']) . ' Migration(en)).' : '.'));
    }

    private function rollback(Request $q, array $user): Response
    {
        try {
            $v = $this->app->updater->rollback($q->str('backup') ?: null);
        } catch (UpdateException $e) {
            return $this->back(null, $e->getMessage());
        }
        $this->app->audit->log('update.rolled_back', (int) $user['id'], ['version' => $v, 'manual' => true]);
        return $this->back('Dateien auf Version ' . $v . ' zurückgestellt. Die Datenbank wurde nicht verändert.');
    }
}
