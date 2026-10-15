<?php
declare(strict_types=1);

namespace Vylaris\License;

/** Lizenzfehler mit Klartext für Anwender. $code ist ein Code der TRS-License-API oder UNAVAILABLE / NOT_CONFIGURED / BAD_FORMAT. */
final class LicenseException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $licenseCode = 'UNAVAILABLE')
    {
        parent::__construct($message);
    }

    /** Nachricht für Anwender zu einem API-Code. */
    public static function forCode(string $code): self
    {
        $msg = match ($code) {
            'NOT_FOUND' => 'Der Lizenzschlüssel ist unbekannt.',
            'BLOCKED' => 'Diese Lizenz ist gesperrt.',
            'EXPIRED' => 'Diese Lizenz ist abgelaufen.',
            'WRONG_PRODUCT' => 'Dieser Lizenzschlüssel gehört zu einem anderen Produkt.',
            'DEVICE_LIMIT' => 'Alle Plätze dieses Lizenzschlüssels sind belegt.',
            'NOT_ACTIVATED' => 'Für diesen Lizenzschlüssel ist kein Platz reserviert.',
            default => 'Der Lizenzschlüssel konnte nicht verwendet werden (' . $code . ').',
        };
        return new self($msg, $code);
    }
}
