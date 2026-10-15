<?php
declare(strict_types=1);

namespace Vylaris\Branding;

use Vylaris\Core\{Clock, Config, Database, Request, Response};

/**
 * Whitelabel: Name, Texte, Logo/Favicon, Farben, Schrift, Rundungen und Standard-Theme (hell/dunkel).
 * Alles liegt in der Tabelle `settings` (Schlüssel brand.*); Dateien in var/branding (nicht öffentlich).
 * Das Theme wird als /brand/theme.css ausgeliefert (CSS-Variablen) – so bleibt die CSP ohne Inline-Styles intakt.
 */
final class BrandingService
{
    public const SLOTS = ['logo', 'logo_dark', 'favicon'];
    public const RADII = ['sharp' => ['.125rem', '.25rem', '.375rem'], 'normal' => ['.5rem', '.75rem', '1rem'], 'round' => ['.75rem', '1.25rem', '1.75rem']];
    public const FONTS = [
        'modern' => 'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'system' => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        'serif' => 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
    ];
    private const MAX_RASTER = 524288;   // 512 KiB
    private const MAX_SVG = 204800;      // 200 KiB
    private const MIN_CONTRAST = 3.5;    // Markenfarbe gegen weißen Text auf Buttons

    private ?array $cache = null;

    public function __construct(private Database $db, private Config $config, private string $root)
    {
    }

    // ------------------------------------------------------------------ Lesen

    /** Standardwerte; Name/Organisation stammen aus der Installation (config). */
    public function defaults(): array
    {
        $name = trim((string) $this->config->get('app.name', '')) ?: 'Auth';
        $org = trim((string) $this->config->get('app.org', '')) ?: (trim((string) $this->config->get('webauthn.rp_name', '')) ?: $name);
        return [
            'name' => $name, 'org' => $org, 'tagline' => '',
            'login_title' => 'Anmelden', 'login_subtitle' => 'Ein Konto für alle Anwendungen von {org}.',
            'footer' => '© {org} · Sichere Anmeldung für alle Anwendungen',
            'support_email' => '', 'mail_from_name' => '', 'mail_footer' => '',
            'privacy_controller' => '', 'privacy_email' => '', 'privacy_dpo' => '', 'privacy_authority' => '', 'privacy_extra' => '', 'privacy_version' => '1', 'terms_url' => '',
            'legal_1_label' => '', 'legal_1_url' => '', 'legal_2_label' => '', 'legal_2_url' => '', 'legal_3_label' => '', 'legal_3_url' => '',
            'primary' => 'indigo', 'accent' => 'violet', 'neutral' => 'slate',
            'radius' => 'normal', 'font' => 'modern', 'bg' => 'blobs',
            'default_theme' => 'dark', 'allow_toggle' => '1', 'show_name' => '1',
            'custom_css' => '', 'version' => '1',
            'logo' => '', 'logo_dark' => '', 'favicon' => '',
        ];
    }

    /** @return array<string,string> */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $vals = $this->defaults();
        try {
            foreach ($this->db->all("SELECT k, v FROM settings WHERE k LIKE 'brand.%'") as $r) {
                $k = substr((string) $r['k'], 6);
                if (array_key_exists($k, $vals) && $r['v'] !== null && $r['v'] !== '') {
                    $vals[$k] = (string) $r['v'];
                }
            }
        } catch (\Throwable) {
            // Tabelle fehlt (Setup) -> Standardwerte
        }
        return $this->cache = $vals;
    }

    /** Roher Wert (ohne Platzhalter-Ersetzung). */
    public function get(string $key): string
    {
        return $this->all()[$key] ?? '';
    }

    /** Wert mit ersetzten Platzhaltern {app}/{name} und {org}. */
    public function text(string $key): string
    {
        return strtr($this->get($key), ['{app}' => $this->get('name'), '{name}' => $this->get('name'), '{org}' => $this->get('org')]);
    }

    public function isCustomized(string $key): bool
    {
        return $this->get($key) !== ($this->defaults()[$key] ?? '');
    }

    /** Daten für Templates. */
    public function view(): array
    {
        $v = $this->get('version');
        $legal = [];
        foreach ([1, 2, 3] as $i) {
            if ($this->get("legal_{$i}_label") !== '' && $this->get("legal_{$i}_url") !== '') {
                $legal[] = [$this->get("legal_{$i}_label"), $this->get("legal_{$i}_url")];
            }
        }
        $logo = $this->get('logo') !== '';
        $logoDark = $this->get('logo_dark') !== '';
        return [
            'name' => $this->get('name'), 'org' => $this->get('org'), 'tagline' => $this->text('tagline'),
            'loginTitle' => $this->text('login_title'), 'loginSubtitle' => $this->text('login_subtitle'),
            'footer' => $this->text('footer'), 'supportEmail' => $this->get('support_email'), 'legal' => $legal,
            'hasLogo' => $logo || $logoDark,
            'logoUrl' => $logo || $logoDark ? '/brand/logo?v=' . $v : null,
            'logoDarkUrl' => $logoDark || $logo ? '/brand/logo-dark?v=' . $v : null,
            'hasSeparateDarkLogo' => $logo && $logoDark,
            'faviconUrl' => '/brand/favicon?v=' . $v,
            'themeCssUrl' => '/brand/theme.css?v=' . $v,
            'privacyUrl' => '/privacy', 'termsUrl' => $this->get('terms_url'),
            'defaultTheme' => $this->get('default_theme'), 'allowToggle' => $this->get('allow_toggle') === '1',
            'showName' => $this->get('show_name') === '1' || !($logo || $logoDark),
            'primaryHex' => $this->shades($this->get('primary'))['600'] ?? '',
        ];
    }

    // ------------------------------------------------------------------ Speichern

    /**
     * Validiert und speichert Branding-Einstellungen. Nicht übergebene Felder bleiben unverändert.
     * @throws \InvalidArgumentException anzeigbare Fehlermeldung
     */
    public function save(array $in, int $actorId): void
    {
        $errors = [];
        $set = [];
        $text = static fn (string $k): string => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($in[$k] ?? '')) ?? '');
        $len = static fn (string $s): int => mb_strlen($s);

        $rules = ['name' => [1, 60, 'Name'], 'org' => [0, 80, 'Organisation'], 'tagline' => [0, 160, 'Slogan'],
            'login_title' => [1, 60, 'Titel der Anmeldeseite'], 'login_subtitle' => [0, 200, 'Untertitel der Anmeldeseite'],
            'footer' => [0, 300, 'Fußzeile'], 'mail_from_name' => [0, 60, 'Absendername'], 'mail_footer' => [0, 500, 'E-Mail-Fußzeile'],
            'privacy_controller' => [0, 600, 'Verantwortlicher'], 'privacy_dpo' => [0, 400, 'Datenschutzbeauftragte/r'], 'privacy_authority' => [0, 300, 'Aufsichtsbehörde'], 'privacy_extra' => [0, 4000, 'Zusätzliche Hinweise']];
        foreach ($rules as $k => [$min, $max, $label]) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $text($k);
            if (!in_array($k, ['mail_footer', 'footer', 'privacy_controller', 'privacy_dpo', 'privacy_extra'], true)) {
                $v = preg_replace('/\s+/u', ' ', $v) ?? '';
            }
            if ($len($v) < $min || $len($v) > $max) {
                $errors[] = "$label: " . ($min > 0 ? "{$min}–{$max}" : "höchstens $max") . ' Zeichen.';
                continue;
            }
            if (in_array($k, ['name', 'mail_from_name'], true) && preg_match('/[<>"\r\n]/', $v)) {
                $errors[] = "$label darf keine Zeichen wie < > \" enthalten.";
                continue;
            }
            $set[$k] = $v;
        }
        foreach (['support_email' => 'Support-E-Mail', 'privacy_email' => 'Datenschutz-Kontakt (E-Mail)'] as $k => $label) {
            if (array_key_exists($k, $in)) {
                $m = $text($k);
                if ($m !== '' && (!filter_var($m, FILTER_VALIDATE_EMAIL) || $len($m) > 255)) {
                    $errors[] = "$label ist ungültig.";
                } else {
                    $set[$k] = $m;
                }
            }
        }
        if (array_key_exists('terms_url', $in)) {
            $t = $text('terms_url');
            if ($t !== '' && (strlen($t) > 500 || !self::safeLink($t))) {
                $errors[] = 'Nutzungsbedingungen-URL: https://-URL oder lokaler Pfad erforderlich.';
            } else {
                $set['terms_url'] = $t;
            }
        }
        foreach ([1, 2, 3] as $i) {
            if (!array_key_exists("legal_{$i}_label", $in) && !array_key_exists("legal_{$i}_url", $in)) {
                continue;
            }
            $label = preg_replace('/\s+/u', ' ', $text("legal_{$i}_label")) ?? '';
            $url = $text("legal_{$i}_url");
            if ($label === '' && $url === '') {
                $set["legal_{$i}_label"] = '';
                $set["legal_{$i}_url"] = '';
                continue;
            }
            if ($label === '' || $len($label) > 40 || $url === '' || strlen($url) > 500 || !self::safeLink($url)) {
                $errors[] = "Rechtlicher Link $i: Bezeichnung (max. 40 Zeichen) und eine https://-URL oder ein Pfad wie /impressum sind nötig.";
                continue;
            }
            $set["legal_{$i}_label"] = $label;
            $set["legal_{$i}_url"] = $url;
        }
        foreach (['primary', 'accent'] as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = strtolower($text($k));
            if (isset(Palettes::BRANDS[$v])) {
                $set[$k] = $v;
            } elseif (preg_match('/^#[0-9a-f]{6}$/', $v)) {
                if (self::contrast(self::hexToRgb($v), [255, 255, 255]) < self::MIN_CONTRAST) {
                    $errors[] = ($k === 'primary' ? 'Primärfarbe' : 'Akzentfarbe') . ' ist zu hell für weißen Text auf Buttons (Kontrast mind. ' . self::MIN_CONTRAST . ':1). Bitte dunkler wählen.';
                } else {
                    $set[$k] = $v;
                }
            } else {
                $errors[] = ($k === 'primary' ? 'Primärfarbe' : 'Akzentfarbe') . ': Vorgabe wählen oder Hex-Wert wie #4f46e5 angeben.';
            }
        }
        $enums = ['neutral' => array_keys(Palettes::NEUTRALS), 'radius' => array_keys(self::RADII), 'font' => array_keys(self::FONTS), 'bg' => ['blobs', 'plain'], 'default_theme' => ['dark', 'light', 'system']];
        foreach ($enums as $k => $allowed) {
            if (array_key_exists($k, $in)) {
                $v = $text($k);
                in_array($v, $allowed, true) ? $set[$k] = $v : $errors[] = "Ungültiger Wert für $k.";
            }
        }
        foreach (['allow_toggle', 'show_name'] as $k) {
            if (array_key_exists($k, $in)) {
                $set[$k] = !empty($in[$k]) && $in[$k] !== '0' ? '1' : '0';
            }
        }
        if (array_key_exists('custom_css', $in)) {
            $css = str_replace("\r\n", "\n", (string) $in['custom_css']);
            if (strlen($css) > 20000) {
                $errors[] = 'Eigenes CSS ist zu lang (max. 20 000 Zeichen).';
            } elseif (($bad = self::unsafeCss($css)) !== null) {
                $errors[] = 'Eigenes CSS: ' . $bad;
            } else {
                $set['custom_css'] = trim($css);
            }
        }
        if ($errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        foreach ($set as $k => $v) {
            if (str_starts_with($k, 'privacy_') && $k !== 'privacy_version' && $v !== $this->get($k)) {
                $set['privacy_version'] = gmdate('Y-m-d', Clock::now()); // Stand der Datenschutzerklärung
                break;
            }
        }
        $this->write($set);
        $this->bump();
        $this->audit('branding.updated', $actorId, ['fields' => implode(',', array_keys($set))]);
    }

    /** Setzt alle Branding-Werte (inkl. Dateien) auf die Standardwerte zurück. */
    public function reset(int $actorId): void
    {
        $this->db->run("DELETE FROM settings WHERE k LIKE 'brand.%'");
        foreach (self::SLOTS as $slot) {
            foreach (glob($this->dir() . '/' . $slot . '.*') ?: [] as $f) {
                @unlink($f);
            }
        }
        $this->cache = null;
        $this->bump();
        $this->audit('branding.reset', $actorId);
    }

    private function write(array $set): void
    {
        foreach ($set as $k => $v) {
            $this->db->run('DELETE FROM settings WHERE k = ?', ['brand.' . $k]);
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?)', ['brand.' . $k, $v]);
        }
        $this->cache = null;
    }

    private function bump(): void
    {
        $this->write(['version' => (string) (time() . random_int(10, 99))]);
    }

    private function audit(string $event, int $actor, array $details = []): void
    {
        (new \Vylaris\Service\AuditLogger($this->db))->log($event, null, $details, $actor);
    }

    // ------------------------------------------------------------------ Dateien (Logo, Favicon)

    private function dir(): string
    {
        return rtrim((string) $this->config->get('paths.branding_dir', $this->root . '/var/branding'), '/');
    }

    /** @throws \InvalidArgumentException */
    public function setAsset(string $slot, string $bytes, int $actorId): void
    {
        if (!in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException('Unbekannter Dateityp.');
        }
        [$ext, $mime] = self::sniff($bytes, $slot === 'favicon');
        if (!is_dir($this->dir()) && !@mkdir($this->dir(), 0750, true) && !is_dir($this->dir())) {
            throw new \InvalidArgumentException('Das Verzeichnis var/branding ist nicht beschreibbar.');
        }
        foreach (glob($this->dir() . '/' . $slot . '.*') ?: [] as $f) {
            @unlink($f);
        }
        if (@file_put_contents($this->dir() . '/' . $slot . '.' . $ext, $bytes, LOCK_EX) === false) {
            throw new \InvalidArgumentException('Datei konnte nicht gespeichert werden.');
        }
        @chmod($this->dir() . '/' . $slot . '.' . $ext, 0640);
        $this->write([$slot => $ext]);
        $this->bump();
        $this->audit('branding.asset_set', $actorId, ['slot' => $slot, 'type' => $ext, 'bytes' => strlen($bytes)]);
    }

    public function removeAsset(string $slot, int $actorId): void
    {
        if (!in_array($slot, self::SLOTS, true)) {
            return;
        }
        foreach (glob($this->dir() . '/' . $slot . '.*') ?: [] as $f) {
            @unlink($f);
        }
        $this->db->run('DELETE FROM settings WHERE k = ?', ['brand.' . $slot]);
        $this->cache = null;
        $this->bump();
        $this->audit('branding.asset_removed', $actorId, ['slot' => $slot]);
    }

    /** @return array{0:string,1:string} [Dateiendung, MIME] */
    public static function sniff(string $b, bool $allowIco): array
    {
        $len = strlen($b);
        if ($len < 16) {
            throw new \InvalidArgumentException('Die Datei ist leer oder zu klein.');
        }
        $raster = null;
        if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) {
            $raster = ['png', 'image/png'];
        } elseif (str_starts_with($b, "\xFF\xD8\xFF")) {
            $raster = ['jpg', 'image/jpeg'];
        } elseif (str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP') {
            $raster = ['webp', 'image/webp'];
        } elseif ($allowIco && str_starts_with($b, "\x00\x00\x01\x00")) {
            if ($len > 102400) {
                throw new \InvalidArgumentException('Das Favicon ist zu groß (max. 100 KB).');
            }
            return ['ico', 'image/x-icon'];
        }
        if ($raster !== null) {
            if ($len > self::MAX_RASTER) {
                throw new \InvalidArgumentException('Das Bild ist zu groß (max. 512 KB).');
            }
            $info = @getimagesizefromstring($b);
            if ($info === false || $info[0] < 16 || $info[1] < 16 || $info[0] > 4096 || $info[1] > 4096) {
                throw new \InvalidArgumentException('Ungültiges Bild (Seitenlänge 16–4096 Pixel).');
            }
            return $raster;
        }
        // SVG
        $head = ltrim(substr($b, 0, 2048));
        if ($len <= self::MAX_SVG && (str_starts_with($head, '<svg') || str_starts_with($head, '<?xml') || str_starts_with($head, '<!--')) && stripos($b, '<svg') !== false) {
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $b)) {
                throw new \InvalidArgumentException('SVG mit DOCTYPE/ENTITY ist nicht erlaubt.');
            }
            $prev = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $ok = $dom->loadXML($b, LIBXML_NONET | LIBXML_NOENT * 0) && $dom->documentElement && strtolower($dom->documentElement->localName) === 'svg';
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (!$ok) {
                throw new \InvalidArgumentException('Ungültige SVG-Datei.');
            }
            if (preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:/i', $b)) {
                throw new \InvalidArgumentException('SVG darf keine Skripte oder Event-Handler enthalten.');
            }
            return ['svg', 'image/svg+xml'];
        }
        throw new \InvalidArgumentException('Erlaubt sind PNG, JPEG, WebP und SVG' . ($allowIco ? ' (Favicon zusätzlich ICO)' : '') . ', max. 512 KB.');
    }

    /** @return array{path:string,mime:string}|null */
    public function asset(string $slot): ?array
    {
        $ext = $this->get($slot);
        if ($ext === '' || !in_array($ext, ['png', 'jpg', 'webp', 'svg', 'ico'], true)) {
            return null;
        }
        $path = $this->dir() . '/' . $slot . '.' . $ext;
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'][$ext];
        return is_file($path) ? ['path' => $path, 'mime' => $mime] : null;
    }

    // ------------------------------------------------------------------ Auslieferung (/brand/*)

    public function serve(Request $r): Response
    {
        $immutable = isset($r->query['v']) && $r->query['v'] === $this->get('version');
        $cache = $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=300';
        $assetCsp = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

        switch ($r->path) {
            case '/brand/theme.css':
                $css = $this->themeCss();
                $etag = '"' . substr(sha1($css), 0, 16) . '"';
                if (($r->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                    return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
                }
                return new Response(200, $css, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => $cache, 'ETag' => $etag]);
            case '/brand/logo':
            case '/brand/logo-dark':
                $a = $r->path === '/brand/logo' ? ($this->asset('logo') ?? $this->asset('logo_dark')) : ($this->asset('logo_dark') ?? $this->asset('logo'));
                break;
            case '/brand/favicon':
                $a = $this->asset('favicon');
                if ($a === null) {
                    return new Response(200, $this->defaultFavicon(), ['Content-Type' => 'image/svg+xml', 'Cache-Control' => $cache, 'Content-Security-Policy' => $assetCsp]);
                }
                break;
            default:
                return new Response(404, 'Nicht gefunden', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($a === null) {
            return new Response(404, 'Nicht gefunden', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response(200, (string) file_get_contents($a['path']), [
            'Content-Type' => $a['mime'], 'Cache-Control' => $cache, 'Content-Security-Policy' => $assetCsp, 'Content-Disposition' => 'inline',
        ]);
    }

    private function defaultFavicon(): string
    {
        $c = $this->shades($this->get('primary'))['600'];
        $d = $this->shades($this->get('accent'))['600'];
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c . '"/><stop offset="1" stop-color="' . $d . '"/></linearGradient></defs>'
            . '<rect width="64" height="64" rx="14" fill="url(#g)"/><path d="M32 12 16 18v12c0 11 7 19 16 22 9-3 16-11 16-22V18z" fill="#fff"/></svg>';
    }

    // ------------------------------------------------------------------ Theme-CSS

    public function themeCss(): string
    {
        $out = ":root{\n";
        foreach ([['brand', 'primary'], ['accent', 'accent']] as [$var, $key]) {
            foreach ($this->rgbShades($this->get($key)) as $step => $rgb) {
                $out .= "  --$var-$step: $rgb;\n";
            }
        }
        foreach (Palettes::NEUTRALS[$this->get('neutral')] ?? Palettes::NEUTRALS['slate'] as $step => $rgb) {
            $out .= "  --n-$step: $rgb;\n";
        }
        [$lg, $xl, $x2] = self::RADII[$this->get('radius')] ?? self::RADII['normal'];
        $out .= "  --r-lg: $lg;\n  --r-xl: $xl;\n  --r-2xl: $x2;\n";
        $out .= '  --font-sans: ' . (self::FONTS[$this->get('font')] ?? self::FONTS['modern']) . ";\n}\n";
        if ($this->get('bg') === 'plain') {
            $out .= ".app-bg::before,.app-bg::after{display:none}\n";
        }
        if ($this->get('custom_css') !== '') {
            $out .= "/* Eigenes CSS */\n" . $this->get('custom_css') . "\n";
        }
        return $out;
    }

    /** @return array<string,string> Stufe => "r g b" */
    private function rgbShades(string $spec): array
    {
        if (isset(Palettes::BRANDS[$spec])) {
            return Palettes::BRANDS[$spec];
        }
        $base = self::hexToRgb(preg_match('/^#[0-9a-f]{6}$/i', $spec) ? $spec : '#4f46e5');
        $mix = static fn (array $to, float $t): string => implode(' ', array_map(static fn ($a, $b) => (int) round($a + ($b - $a) * $t), $base, $to));
        $w = [255, 255, 255];
        $k = [0, 0, 0];
        return ['50' => $mix($w, .95), '100' => $mix($w, .9), '200' => $mix($w, .78), '300' => $mix($w, .6), '400' => $mix($w, .4), '500' => $mix($w, .18),
            '600' => implode(' ', $base), '700' => $mix($k, .16), '800' => $mix($k, .32), '900' => $mix($k, .46), '950' => $mix($k, .64)];
    }

    /** @return array<string,string> Stufe => #rrggbb */
    public function shades(string $spec): array
    {
        return array_map(static fn (string $rgb) => sprintf('#%02x%02x%02x', ...array_map('intval', explode(' ', $rgb))), $this->rgbShades($spec));
    }

    // ------------------------------------------------------------------ Hilfsfunktionen

    public static function hexToRgb(string $hex): array
    {
        $n = hexdec(ltrim($hex, '#'));
        return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
    }

    /** WCAG-Kontrastverhältnis. */
    public static function contrast(array $a, array $b): float
    {
        $lum = static function (array $c): float {
            [$r, $g, $bl] = array_map(static fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $bl;
        };
        $l1 = $lum($a);
        $l2 = $lum($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Erlaubt nur https-URLs und lokale Pfade (kein //host, kein javascript:). */
    public static function safeLink(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\<>"\']/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        $p = parse_url($url);
        return $p !== false && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']);
    }

    private static function unsafeCss(string $css): ?string
    {
        if (preg_match('/@import|<\/?style|<\/?script|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css)) {
            return '@import, Skripte und veraltete Konstrukte sind nicht erlaubt.';
        }
        if (preg_match('/url\(\s*[\'"]?\s*(?:https?:|\/\/|ftp:)/i', $css)) {
            return 'Externe URLs sind nicht erlaubt (nur eigene Pfade oder data:-URIs).';
        }
        return null;
    }
}
