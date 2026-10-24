<?php
declare(strict_types=1);

namespace Vylaris\Core;

/**
 * Herkunftshinweis „powered by VylarisAuth“ am Ende jeder Seitenleiste.
 *
 * Der Hinweis ist bewusst nicht konfigurierbar (kein Branding-Feld, keine Einstellung). Er wird
 * dreifach abgesichert: Das Seitenleisten-Template gibt ihn aus, View::render() ergänzt ihn,
 * falls ein angepasstes Template ihn nicht mehr enthält, und das Theme-CSS hängt am Ende
 * (nach dem eigenen CSS) Regeln mit !important an. Eigenes CSS, das den Hinweis ansprechen
 * will, wird beim Speichern abgelehnt. Updates überschreiben src/ und templates/ wieder.
 */
final class Watermark
{
    public const URL = 'https://vylaris.ch';
    public const TEXT = 'powered by VylarisAuth';

    public static function html(bool $fallback = false): string
    {
        return '<p class="vy-wm' . ($fallback ? ' vy-wm-fb' : '') . '" data-vy-wm="1"><a href="' . self::URL . '" target="_blank" rel="noopener">' . self::TEXT . '</a></p>';
    }

    /** Stellt sicher, dass eine Seite mit Seitenleiste den unveränderten Hinweis enthält. */
    public static function ensure(string $page): string
    {
        if (!str_contains($page, '<details class="sidebar"') || str_contains($page, self::html()) || str_contains($page, self::html(true))) {
            return $page;
        }
        $fb = self::html(true);
        $pos = strripos($page, '</body>');
        return $pos === false ? $page . $fb : substr($page, 0, $pos) . $fb . substr($page, $pos);
    }

    /** Nicht überschreibbare Darstellung; wird in BrandingService::themeCss() ans Ende gesetzt. */
    public static function css(): string
    {
        $on = 'html body .vy-wm';
        $base = 'display:block!important;visibility:visible!important;opacity:1!important;position:static!important;float:none!important;width:auto!important;height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important;clip:auto!important;clip-path:none!important;transform:none!important;filter:none!important;z-index:auto!important;'
            . 'margin:.75rem 0 0!important;padding:.6rem .75rem 0!important;border:0!important;border-top:1px solid rgb(var(--n-200))!important;background:none!important;'
            . 'font:500 .75rem/1.2 var(--font-sans)!important;letter-spacing:0!important;text-align:left!important;text-indent:0!important;color:rgb(var(--n-500))!important;pointer-events:auto!important';
        $link = 'display:inline!important;visibility:visible!important;opacity:1!important;position:static!important;font-size:inherit!important;font-weight:600!important;text-decoration:none!important;color:rgb(var(--brand-600))!important;pointer-events:auto!important';
        return "/* Herkunftshinweis (nicht änderbar) */\n"
            . "$on{{$base}}\n"
            . "html body .vy-wm.vy-wm-fb{position:fixed!important;left:.75rem!important;bottom:.5rem!important;z-index:60!important;margin:0!important;padding:.35rem .6rem!important;border:1px solid rgb(var(--n-200))!important;border-radius:.5rem!important;background:rgb(var(--n-50))!important}\n"
            . "$on a{{$link}}\n$on a:hover{text-decoration:underline!important}\n"
            . "html[data-theme=dark] body .vy-wm{border-top-color:rgb(var(--n-700))!important;color:rgb(var(--n-400))!important}\n"
            . "html[data-theme=dark] body .vy-wm.vy-wm-fb{border-color:rgb(var(--n-700))!important;background:rgb(var(--n-900))!important}\n"
            . "html[data-theme=dark] body .vy-wm a{color:rgb(var(--brand-300))!important}\n";
    }

    /** Eigenes CSS darf den Hinweis weder ansprechen noch die Seitenleiste insgesamt ausblenden. */
    public static function touchesCss(string $css): bool
    {
        return (bool) preg_match('/vy-?wm|watermark|vylaris\.ch|powered|data-vy|\[href\s*[\^$*~|]?=|:last-(?:child|of-type)|:nth-last|sidebar[^{]*\{[^}]*(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $css);
    }
}
