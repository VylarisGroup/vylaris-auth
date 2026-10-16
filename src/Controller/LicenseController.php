<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Core\{Request, Response, Router};
use Vylaris\License\{LicenseException, LicenseService};

/** Lizenzstatus für Nutzer ("Konto → Lizenz") und Übersicht für Administratoren. */
final class LicenseController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/account/license', fn (Request $q) => $this->account($q));
        $r->post('/account/license', fn (Request $q) => $this->attach($q));
        $r->get('/admin/license', fn (Request $q) => $this->admin($q));
        $r->post('/admin/license/refresh', fn (Request $q) => $this->refreshAll($q));
        $r->post('/admin/license/keys', fn (Request $q) => $this->addKey($q));
        $r->post('/admin/license/keys/{id}/remove', fn (Request $q, array $p) => $this->removeKey($q, (int) $p['id']));
    }

    private function account(Request $q, array $errors = [], int $status = 200): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $s = $this->app->license->state((int) $user['id']);
        $s['key'] = $s['key'] !== '' ? LicenseService::mask($s['key']) : '';
        return $this->render('account/license', [
            'lic' => $s, 'features' => LicenseService::FEATURES, 'required' => $this->app->license->required(),
            'errors' => $errors, 'flash' => $this->app->session()->flash('ok'), 'hasPool' => $this->app->license->hasPool(),
        ], $status);
    }

    /** Bestandskonto ohne Schlüssel: Schlüssel hinterlegen (belegt einen Platz). */
    private function attach(Request $q): Response
    {
        $user = $this->requireUser($q);
        if ($user instanceof Response) {
            return $user;
        }
        $uid = (int) $user['id'];
        if (!$this->app->license->state($uid)['keyless']) {
            return $this->account($q, ['Für dieses Konto ist bereits ein Lizenzschlüssel hinterlegt.'], 409);
        }
        if (!$this->app->limiter->hit('license-attach:' . $uid, 5, 3600)) {
            return $this->account($q, ['Zu viele Versuche. Bitte später erneut versuchen.'], 429);
        }
        try {
            $key = $this->app->license->resolveKey($q->str('license_key')); // leer = hinterlegter Schlüssel
            $this->app->license->bind($uid, $key);
        } catch (LicenseException $e) {
            return $this->account($q, [$e->getMessage()], 422);
        }
        $this->app->session()->flash('ok', 'Lizenzschlüssel hinterlegt.');
        return $this->redirect('/account/license');
    }

    private function admin(Request $q): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        $l = $this->app->license;
        $seats = array_map(static function (array $r): array {
            $r['license_key'] = LicenseService::mask((string) $r['license_key']);
            return $r;
        }, $l->seatsByKey());
        return $this->render('admin/license', [
            'required' => $l->required(), 'configured' => $l->client()->configured(), 'fingerprint' => $l->client()->fingerprint(),
            'product' => $l->client()->product(), 'apiUrl' => $l->client()->baseUrl(),
            'features' => LicenseService::FEATURES, 'seats' => $seats, 'keyless' => $l->keylessUsers(),
            'pool' => $this->poolRows(),
            'releases' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM license_releases'),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    /** Hinterlegte Schlüssel mit belegten Plätzen (nur lokale Daten, kein Netzwerk). */
    private function poolRows(): array
    {
        $rows = [];
        foreach ($this->app->license->pool() as $p) {
            $u = $this->app->db->one('SELECT COUNT(*) AS seats, MAX(max_devices) AS max_devices, MAX(plan) AS plan FROM user_licenses WHERE license_key = ?', [$p['license_key']]) ?? [];
            $rows[] = ['id' => (int) $p['id'], 'key' => LicenseService::mask((string) $p['license_key']), 'seats' => (int) ($u['seats'] ?? 0), 'max' => $u['max_devices'] ?? null, 'plan' => $u['plan'] ?? null, 'created_at' => $p['created_at']];
        }
        return $rows;
    }

    private function addKey(Request $q): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->limiter->hit('license-pool:' . (int) $user['id'], 10, 3600)) {
            $this->app->session()->flash('err', 'Bitte später erneut versuchen.');
            return $this->redirect('/admin/license');
        }
        try {
            $this->app->license->addToPool($q->str('license_key'), (int) $user['id']);
            $this->app->session()->flash('ok', 'Lizenzschlüssel hinterlegt. Neue Konten verwenden ihn automatisch, bis alle Plätze belegt sind.');
        } catch (LicenseException $e) {
            $this->app->session()->flash('err', $e->getMessage());
        }
        return $this->redirect('/admin/license');
    }

    private function removeKey(Request $q, int $id): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        $this->app->license->removeFromPool($id, (int) $user['id']);
        $this->app->session()->flash('ok', 'Schlüssel entfernt. Bestehende Konten behalten ihren Platz.');
        return $this->redirect('/admin/license');
    }

    private function refreshAll(Request $q): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->limiter->hit('license-refresh:' . (int) $user['id'], 6, 3600)) {
            $this->app->session()->flash('err', 'Bitte später erneut versuchen.');
            return $this->redirect('/admin/license');
        }
        $n = $this->app->license->refreshStale(200, true);
        $this->app->license->retryReleases();
        $this->app->session()->flash('ok', "$n Lizenz(en) beim Lizenzserver geprüft.");
        return $this->redirect('/admin/license');
    }
}
