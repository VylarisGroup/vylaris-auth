<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

/** Validierung registrierbarer Redirect-URIs (exakter Vergleich beim Einsatz, keine Wildcards). */
final class RedirectUri
{
    /** @return string|null Fehlermeldung oder null wenn gültig */
    public static function validate(string $uri): ?string
    {
        $uri = trim($uri);
        if ($uri === '' || strlen($uri) > 500) {
            return 'Die URI ist leer oder zu lang.';
        }
        if (preg_match('/[\x00-\x20\x7F\\\\*]/', $uri)) {
            return 'Die URI enthält unzulässige Zeichen (Leerzeichen, Backslash oder Wildcard *).';
        }
        $p = parse_url($uri);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return 'Die URI muss absolut sein (https://host/pfad).';
        }
        if (isset($p['fragment']) || str_contains($uri, '#')) {
            return 'Fragmente (#) sind in Redirect-URIs nicht erlaubt.';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'Zugangsdaten in der URI sind nicht erlaubt.';
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower(trim($p['host'], '[]'));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme === 'https') {
            return null;
        }
        if ($scheme === 'http' && $loopback) {
            return null; // nur für lokale Entwicklung
        }
        return 'Nur https:// ist erlaubt (http:// nur für localhost/127.0.0.1).';
    }

    /** Exakter String-Vergleich gegen die Registrierung. */
    public static function matches(string $requested, array $registered): bool
    {
        foreach ($registered as $r) {
            if (hash_equals((string) $r, $requested)) {
                return true;
            }
        }
        return false;
    }
}
