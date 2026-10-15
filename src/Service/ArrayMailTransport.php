<?php
declare(strict_types=1);

namespace Vylaris\Service;

/** Sammelt Mails im Speicher (Tests / Entwicklung). */
final class ArrayMailTransport implements MailTransport
{
    /** @var array<int,array{to:string,subject:string,body:string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $textBody): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $textBody];
    }

    public function last(): ?array
    {
        return $this->sent ? $this->sent[array_key_last($this->sent)] : null;
    }
}
