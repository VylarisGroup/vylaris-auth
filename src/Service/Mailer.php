<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Mail-Queue in der DB; Versand per Cron (bin/cron.php) oder nachgelagert im Request. */
final class Mailer
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private Database $db, private MailTransport $transport, private ?\Vylaris\Branding\BrandingService $brand = null)
    {
    }

    /** Platzhalter {app} und {org} werden durch Branding-Werte ersetzt; optionale Fußzeile wird angehängt. */
    public function queue(string $to, string $subject, string $body): void
    {
        if ($this->brand !== null) {
            $map = ['{app}' => $this->brand->get('name'), '{org}' => $this->brand->get('org')];
            $subject = strtr($subject, $map);
            $body = strtr($body, $map);
            $footer = trim($this->brand->text('mail_footer'));
            if ($footer !== '') {
                $body = rtrim($body) . "\n\n-- \n" . $footer . "\n";
            }
        }
        $this->db->insert('mail_queue', [
            'to_email' => $to,
            'subject' => $subject,
            'body_text' => $body,
            'created_at' => Clock::db(),
        ]);
    }

    /** @return int Anzahl erfolgreich versendeter Mails */
    public function flush(int $limit = 20): int
    {
        $rows = $this->db->all(
            'SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts < ? ORDER BY id ASC LIMIT ' . max(1, $limit),
            [self::MAX_ATTEMPTS]
        );
        $sent = 0;
        foreach ($rows as $row) {
            try {
                $this->transport->send($row['to_email'], $row['subject'], $row['body_text']);
                $this->db->update('mail_queue', ['sent_at' => Clock::db(), 'last_error' => null], 'id', $row['id']);
                $sent++;
            } catch (\Throwable $e) {
                $this->db->update('mail_queue', [
                    'attempts' => (int) $row['attempts'] + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ], 'id', $row['id']);
            }
        }
        return $sent;
    }

    /** Gesendete Mails nach 7 Tagen löschen (enthalten Links/Tokens). */
    public function purge(): int
    {
        return $this->db->run(
            'DELETE FROM mail_queue WHERE (sent_at IS NOT NULL AND sent_at < ?) OR (attempts >= ? AND created_at < ?)',
            [Clock::db(Clock::now() - 7 * 86400), self::MAX_ATTEMPTS, Clock::db(Clock::now() - 7 * 86400)]
        )->rowCount();
    }
}
