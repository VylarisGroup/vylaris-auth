<?php
declare(strict_types=1);

namespace Vylaris\Controller;

use Vylaris\Branding\{BrandingService, Palettes};
use Vylaris\Core\{Request, Response, Router};

/** Whitelabel-Einstellungen im Admin-Bereich – nur für Superadmins. */
final class BrandController extends BaseController
{
    public function routes(Router $r): void
    {
        $r->get('/admin/branding', fn (Request $q) => $this->guard($q, fn () => $this->index()));
        $r->post('/admin/branding', fn (Request $q) => $this->guard($q, fn ($u) => $this->save($q, $u)));
        $r->post('/admin/branding/reset', fn (Request $q) => $this->guard($q, fn ($u) => $this->reset($u)));
    }

    private function guard(Request $q, callable $fn): Response
    {
        $user = $this->requireAdmin($q);
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->app->policy->isSuperAdmin((int) $user['id'])) {
            $this->app->audit->log('authz.denied', (int) $user['id'], ['path' => $q->path]);
            return $this->render('error', ['title' => 'Kein Zugriff', 'message' => 'Das Erscheinungsbild dürfen nur Superadmins ändern.'], 403);
        }
        try {
            return $fn($user);
        } catch (\InvalidArgumentException $e) {
            $this->app->session()->flash('err', $e->getMessage());
            return $this->redirect('/admin/branding');
        }
    }

    private function index(): Response
    {
        $b = $this->app->brand;
        $values = [];
        foreach (array_keys($b->defaults()) as $k) {
            $values[$k] = $b->get($k);
        }
        return $this->render('admin/branding', [
            'v' => $values, 'brands' => array_keys(Palettes::BRANDS), 'neutrals' => array_keys(Palettes::NEUTRALS),
            'radii' => array_keys(BrandingService::RADII), 'fonts' => array_keys(BrandingService::FONTS),
            'flash' => $this->app->session()->flash('ok'), 'err' => $this->app->session()->flash('err'),
        ]);
    }

    private function save(Request $q, array $actor): Response
    {
        $b = $this->app->brand;
        $actorId = (int) $actor['id'];
        $in = array_filter($q->post, 'is_string');
        // Farben: Hex-Eingabe hat Vorrang vor der Vorgabe, wenn befüllt.
        foreach (['primary', 'accent'] as $k) {
            $hex = trim((string) ($in[$k . '_hex'] ?? ''));
            if ($hex !== '') {
                $in[$k] = $hex;
            }
        }
        foreach (['allow_toggle', 'show_name'] as $k) {
            $in[$k] = isset($in[$k]) ? '1' : '0'; // Checkbox: fehlt = aus
        }
        $b->save($in, $actorId);

        $problems = [];
        foreach (BrandingService::SLOTS as $slot) {
            if (!empty($in['remove_' . $slot])) {
                $b->removeAsset($slot, $actorId);
            }
            $f = $q->files[$slot] ?? null;
            if ($f === null) {
                continue;
            }
            if ($f['bytes'] === '') {
                $problems[] = 'Datei für „' . $slot . '“ ist leer oder zu groß (max. 512 KiB).';
                continue;
            }
            try {
                $b->setAsset($slot, $f['bytes'], $actorId);
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }
        if ($problems) {
            $this->app->session()->flash('err', 'Gespeichert, aber: ' . implode(' ', $problems));
        } else {
            $this->app->session()->flash('ok', 'Erscheinungsbild gespeichert.');
        }
        return $this->redirect('/admin/branding');
    }

    private function reset(array $actor): Response
    {
        $this->app->brand->reset((int) $actor['id']);
        $this->app->session()->flash('ok', 'Alle Branding-Einstellungen wurden auf die Standardwerte zurückgesetzt.');
        return $this->redirect('/admin/branding');
    }
}
