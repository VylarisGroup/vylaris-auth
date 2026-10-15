<?php
declare(strict_types=1);

namespace Vylaris\Security;

/** Schutz vor Open Redirects: nur interne, relative Pfade zulassen. */
final class Redirect
{
    public static function safeLocal(?string $target, string $fallback = '/account'): string
    {
        if ($target === null || $target === '' || $target[0] !== '/') {
            return $fallback;
        }
        // "//host", "/\host", Steuerzeichen und Schemata ausschließen
        if (str_starts_with($target, '//') || str_contains($target, '\\') || preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return $fallback;
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }
        return $target;
    }
}
