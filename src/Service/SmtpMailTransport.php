<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\Config;

/**
 * Schlanker SMTP-Client ohne Abhängigkeiten: SSL (Port 465), STARTTLS (587) oder unverschlüsselt (nur für interne Relays),
 * AUTH PLAIN/LOGIN, UTF-8-Text-Mails. Zertifikate werden geprüft. Header-Injection wird durch strikte Validierung verhindert.
 */
final class SmtpMailTransport implements MailTransport
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(private Config $config)
    {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $from = (string) $this->config->get('mail.from_email');
        $fromName = (string) $this->config->get('mail.from_name', 'Auth');
        foreach ([$to, $from] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $addr)) {
                throw new \RuntimeException('Ungültige E-Mail-Adresse.');
            }
        }
        $message = $this->build($from, $fromName, $to, $subject, $textBody);
        try {
            $this->connect();
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            // Dot-Stuffing (RFC 5321 §4.5.2) und CRLF-Normalisierung
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
            $this->write(str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
            $this->expect([250]);
            $this->cmd('QUIT', [221], true);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Mailversand fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($this->fp)) {
                @fclose($this->fp);
            }
            $this->fp = null;
        }
    }

    public function build(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $host = parse_url($this->config->baseUrl(), PHP_URL_HOST) ?: 'localhost';
        $enc = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $clean = static fn (string $s): string => trim(preg_replace('/[\r\n]+/', ' ', $s));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $enc($clean($fromName)) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
    }

    private function connect(): void
    {
        $host = (string) $this->config->get('mail.host');
        $port = (int) $this->config->get('mail.port', 587);
        $mode = (string) $this->config->get('mail.encryption', 'tls');
        if ($host === '') {
            throw new \RuntimeException('SMTP-Host nicht konfiguriert.');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'allow_self_signed' => false]]);
        $fp = @stream_socket_client(($mode === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException("Verbindung zu $host:$port fehlgeschlagen ($errstr)");
        }
        stream_set_timeout($fp, 15);
        $this->fp = $fp;
        $this->expect([220]);
        $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) (parse_url($this->config->baseUrl(), PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
        $caps = $this->cmd('EHLO ' . $ehloHost, [250]);
        if ($mode === 'tls') {
            if (!preg_match('/STARTTLS/i', $caps)) {
                throw new \RuntimeException('Server bietet kein STARTTLS an.');
            }
            $this->cmd('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('TLS-Aushandlung fehlgeschlagen.');
            }
            $caps = $this->cmd('EHLO ' . $ehloHost, [250]);
        }
        $user = (string) $this->config->get('mail.username', '');
        if ($user !== '') {
            $pass = (string) $this->config->get('mail.password', '');
            if ($mode === 'none' && !str_starts_with($host, '127.') && $host !== 'localhost') {
                throw new \RuntimeException('Anmeldung über unverschlüsselte Verbindung wird verweigert.');
            }
            if (preg_match('/AUTH[ =].*PLAIN/i', $caps)) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], false, true);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($user), [334], false, true);
                $this->cmd(base64_encode($pass), [235], false, true);
            }
        }
    }

    /** @param int[] $ok */
    private function cmd(string $line, array $ok, bool $lenient = false, bool $secret = false): string
    {
        $this->write($line . "\r\n");
        try {
            return $this->expect($ok);
        } catch (\RuntimeException $e) {
            if ($lenient) {
                return '';
            }
            throw $secret ? new \RuntimeException('SMTP-Anmeldung abgelehnt.') : $e;
        }
    }

    private function write(string $data): void
    {
        if (!is_resource($this->fp) || @fwrite($this->fp, $data) === false) {
            throw new \RuntimeException('Schreiben zum SMTP-Server fehlgeschlagen.');
        }
    }

    /** Liest eine (ggf. mehrzeilige) Antwort und prüft den Statuscode. @param int[] $ok */
    private function expect(array $ok): string
    {
        $all = '';
        while (true) {
            $line = is_resource($this->fp) ? fgets($this->fp, 1024) : false;
            if ($line === false) {
                throw new \RuntimeException('Keine Antwort vom SMTP-Server (Zeitüberschreitung).');
            }
            $all .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($all, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException('Unerwartete SMTP-Antwort: ' . trim(substr($all, 0, 120)));
        }
        return $all;
    }
}
