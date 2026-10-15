<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

/** Opaker, stabiler `sub`-Wert (verrät keine fortlaufende Benutzer-ID). */
final class Subject
{
    public static function forUser(int $userId, string $secret): string
    {
        return 'u_' . rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 'sub:' . $userId, $secret, true), 0, 18)), '+/', '-_'), '=');
    }
}
