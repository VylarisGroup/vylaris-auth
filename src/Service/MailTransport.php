<?php
declare(strict_types=1);

namespace Vylaris\Service;

interface MailTransport
{
    /** @throws \RuntimeException bei Versandfehler */
    public function send(string $to, string $subject, string $textBody): void;
}
