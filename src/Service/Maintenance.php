<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Hintergrundarbeiten (Cron oder manuell): Mailversand und Bereinigung abgelaufener Daten. */
final class Maintenance
{
    public function __construct(
        private Database $db,
        private Mailer $mailer,
        private SessionService $sessions,
        private TokenService $tokens,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private \Vylaris\Oidc\KeyService $keys,
        private int $auditRetentionDays = 365,
        private ?PrivacyService $privacy = null,
        private int $ipRetentionDays = 30,
        private ?\Vylaris\Update\Updater $updater = null,
    ) {
    }

    /** @return array<string,int> */
    public function run(int $mailBatch = 50): array
    {
        $old = Clock::db(Clock::now() - 7 * 86400);
        $now = Clock::db();
        $privacy = $this->privacy;
        return [
            'mails_sent' => $this->mailer->flush($mailBatch),
            'mails_purged' => $this->mailer->purge(),
            'sessions_purged' => $this->sessions->purgeExpired(),
            'user_tokens_purged' => $this->tokens->purge(),
            'rate_limits_purged' => $this->limiter->purge(),
            'auth_codes_purged' => $this->db->run('DELETE FROM oauth_auth_codes WHERE expires_at < ?', [$now])->rowCount(),
            'access_tokens_purged' => $this->db->run('DELETE FROM oauth_access_tokens WHERE expires_at < ?', [$old])->rowCount(),
            'refresh_tokens_purged' => $this->db->run('DELETE FROM oauth_refresh_tokens WHERE expires_at < ?', [$old])->rowCount(),
            'keys_purged' => $this->keys->purgeRetired(),
            'audit_purged' => $this->audit->purge($this->auditRetentionDays),
        ] + ($privacy === null ? [] : ['audit_ips_removed' => $privacy->anonymizeAuditData($this->ipRetentionDays)] + (function () use ($privacy) {
            $r = $privacy->processInactiveAccounts();
            return ['inactive_warned' => $r['warned'], 'inactive_erased' => $r['erased']];
        })()) + ($this->updater === null ? [] : ['update_checked' => (int) $this->updater->checkIfDue()]);
    }
}
