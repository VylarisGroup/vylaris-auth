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
            'errors' => $errors, 'flash' => $this->app->session()->flash('ok'),
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
            $key = LicenseService::normalizeKey($q->str('license_key'));
            $this->app->license->precheck($key);
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
            'releases' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM license_releases'),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
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
