<?php
declare(strict_types=1);

namespace Vylaris\Social;

/** Normalisierte Identität, wie sie ein externer Anbieter geliefert hat. */
final class SocialIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {
    }
}
