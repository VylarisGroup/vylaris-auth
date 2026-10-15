<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Config;
use Vylaris\Service\SmtpMailTransport;
use Vylaris\Tests\Support\{Factory, TestCase};

/** SMTP-Client gegen einen lokalen Fake-Server (kein Internet nötig). */
final class SmtpTest extends TestCase
{
    private function serve(bool $auth = false): array
    {
        $base = sys_get_temp_dir() . '/vsmtp-' . bin2hex(random_bytes(4));
        $proc = proc_open([PHP_BINARY, __DIR__ . '/Support/fake-smtp.php', $base, $auth ? 'auth' : ''], [], $pipes);
        for ($i = 0; $i < 100 && !is_file($base . '.port'); $i++) {
            usleep(50000);
        }
        $this->assertTrue(is_file($base . '.port'), 'Fake-Server gestartet');
        return [$proc, $base, (int) file_get_contents($base . '.port')];
    }

    private function transport(int $port, array $mail = []): SmtpMailTransport
    {
        return new SmtpMailTransport(new Config(['app' => ['base_url' => 'https://auth.test'], 'mail' => $mail + [
            'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => '', 'password' => '',
            'from_email' => 'no-reply@vylaris.ch', 'from_name' => 'VylarisAuth',
        ]]));
    }

    private function finish($proc, string $base): string
    {
        proc_close($proc);
        $log = (string) @file_get_contents($base . '.log');
        @unlink($base . '.log');
        @unlink($base . '.port');
        return $log;
    }

    public function testDeliversUtf8MessageWithDotStuffing(): void
    {
        [$proc, $base, $port] = $this->serve();
        $body = "Hallo Jürgen\n.Punkt am Zeilenanfang\nhttps://auth.test/x?a=1&b=2\n";
        $this->transport($port)->send('anna@example.org', 'Bestätigung Ihrer Adresse', $body);
        $log = $this->finish($proc, $base);
        $this->assertStringContains('C: MAIL FROM:<no-reply@vylaris.ch>', $log);
        $this->assertStringContains('C: RCPT TO:<anna@example.org>', $log);
        $this->assertStringContains('C: Subject: =?UTF-8?B?' . base64_encode('Bestätigung Ihrer Adresse') . '?=', $log);
        $this->assertStringContains('C: Content-Transfer-Encoding: base64', $log);
        $this->assertStringContains('C: QUIT', $log);
        // Der Body ist base64-kodiert, Dot-Stuffing greift trotzdem nie auf Nutzdaten – Nachrichtenende bleibt eindeutig.
        $this->assertSame(1, substr_count($log, "C: .\n"));
    }

    public function testHeaderInjectionIsNeutralised(): void
    {
        [$proc, $base, $port] = $this->serve();
        $this->transport($port)->send('anna@example.org', "Betreff\r\nBcc: evil@example.org", 'Text');
        $log = $this->finish($proc, $base);
        $this->assertStringNotContains("C: Bcc:", $log);
        $this->assertSame(1, substr_count($log, 'RCPT TO'));
        $this->assertThrows(\RuntimeException::class, fn () => $this->transport(1)->send("a@example.org>\r\nRCPT TO:<x@y.z", 's', 'b'));
    }

    public function testAuthPlainIsUsedWhenAnnounced(): void
    {
        [$proc, $base, $port] = $this->serve(true);
        $this->transport($port, ['username' => 'mailer', 'password' => 'geheim'])->send('anna@example.org', 'S', 'B');
        $log = $this->finish($proc, $base);
        $this->assertStringContains('C: AUTH PLAIN ' . base64_encode("\0mailer\0geheim"), $log);
    }

    public function testRefusesPlaintextAuthToRemoteHost(): void
    {
        $t = new SmtpMailTransport(new Config(['app' => ['base_url' => 'https://auth.test'], 'mail' => [
            'host' => '192.0.2.1', 'port' => 25, 'encryption' => 'none', 'username' => 'u', 'password' => 'p', 'from_email' => 'a@b.ch',
        ]]));
        $this->assertThrows(\RuntimeException::class, fn () => $t->send('anna@example.org', 's', 'b'));
    }

    public function testConnectionFailureThrowsWithoutLeakingPassword(): void
    {
        $e = $this->assertThrows(\RuntimeException::class, fn () => $this->transport(1, ['password' => 'TOPSECRET', 'username' => 'u'])->send('anna@example.org', 's', 'b'));
        $this->assertStringNotContains('TOPSECRET', $e->getMessage());
    }
}
