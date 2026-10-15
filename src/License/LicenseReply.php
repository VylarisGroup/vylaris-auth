<?php
declare(strict_types=1);

namespace Vylaris\License;

/** Geprüfte (signierte) Antwort der Prüf-API. */
final class LicenseReply
{
    /** @param string[] $features */
    public function __construct(
        public readonly bool $valid,
        public readonly string $code,
        public readonly string $key,
        public readonly string $plan,
        public readonly array $features,
        public readonly ?string $expiresAt,
        public readonly ?int $maxDevices,
        public readonly ?int $devices,
        public readonly bool $trial,
    ) {
    }
}
