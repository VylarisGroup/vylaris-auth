<?php
declare(strict_types=1);

namespace Vylaris\Social;

final class SocialResult
{
    /** @param 'login'|'linked'|'error' $status */
    public function __construct(
        public readonly string $status,
        public readonly ?int $userId = null,
        public readonly string $next = '',
        public readonly string $message = '',
        public readonly string $mode = 'login',
    ) {
    }

    public static function error(string $message, string $mode = 'login'): self
    {
        return new self('error', null, '', $message, $mode);
    }
}
