<?php
declare(strict_types=1);

namespace Vylaris\Security;

use Vylaris\Core\{Request, Session};

final class Csrf
{
    public static function token(Session $session): string
    {
        $t = $session->get('csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $session->set('csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $request, Session $session): bool
    {
        $expected = $session->get('csrf');
        $given = $request->post['_csrf'] ?? $request->header('X-CSRF-Token') ?? '';
        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
