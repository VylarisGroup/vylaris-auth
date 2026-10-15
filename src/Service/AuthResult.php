<?php
declare(strict_types=1);

namespace Vylaris\Service;

/** Ergebnis eines Login-Versuchs. status: ok | mfa_required | invalid | throttled | unverified | locked */
final class AuthResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $userId = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
