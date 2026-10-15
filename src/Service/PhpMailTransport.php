<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\Config;

/** Versand über PHP mail() (lokaler MTA des Hosters). Wird verwendet, wenn mail.host leer ist. */
final class PhpMailTransport implements MailTransport
{
    public function __construct(private Config $config)
    {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $from = (string) $this->config->get('mail.from_email');
        $fromName = trim(preg_replace('/[\r\n]+/', ' ', (string) $this->config->get('mail.from_name', 'Auth')));
        foreach ([$to, $from] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $addr)) {
                throw new \RuntimeException('Ungültige E-Mail-Adresse.');
            }
        }
        $headers = implode("\r\n", [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ]);
        $subjectEnc = '=?UTF-8?B?' . base64_encode(trim(preg_replace('/[\r\n]+/', ' ', $subject))) . '?=';
        $body = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $textBody)), 76, "\r\n");
        if (!@mail($to, $subjectEnc, $body, $headers, '-f' . escapeshellarg($from))) {
            throw new \RuntimeException('mail() hat den Versand abgelehnt.');
        }
    }
}
