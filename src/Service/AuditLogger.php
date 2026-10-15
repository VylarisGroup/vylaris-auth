<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Sicherheits-Audit-Log. Niemals Passwörter, Tokens oder Geheimnisse übergeben. */
final class AuditLogger
{
    private const FORBIDDEN_KEYS = ['password', 'passwort', 'token', 'secret', 'code', 'authorization', 'cookie', 'assertion', 'challenge'];

    private ?string $ip = null;
    private ?string $ua = null;

    public function __construct(private Database $db)
    {
    }

    public function setContext(string $ip, string $userAgent): void
    {
        $this->ip = $ip;
        $this->ua = mb_substr($userAgent, 0, 255);
    }

    public function log(string $event, ?int $userId = null, array $details = [], ?int $actorId = null, ?string $clientId = null): void
    {
        $this->db->insert('audit_log', [
            'created_at' => Clock::db(),
            'event' => $event,
            'user_id' => $userId,
            'actor_id' => $actorId,
            'client_id' => $clientId,
            'ip' => $this->ip,
            'user_agent' => $this->ua,
            'details' => $details ? json_encode(self::scrub($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /** Entfernt vorsorglich sensible Schlüssel aus Detaildaten. */
    public static function scrub(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::FORBIDDEN_KEYS as $bad) {
                if (str_contains($lk, $bad)) {
                    $out[$k] = '[entfernt]';
                    continue 2;
                }
            }
            $out[$k] = is_array($v) ? self::scrub($v) : (is_string($v) ? mb_substr($v, 0, 300) : $v);
        }
        return $out;
    }

    public function recent(int $limit = 100, ?string $event = null, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM audit_log WHERE 1=1';
        $p = [];
        if ($event !== null && $event !== '') {
            $sql .= ' AND event = ?';
            $p[] = $event;
        }
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $p[] = $userId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        return $this->db->all($sql, $p);
    }

    /** Entfernt Einträge, die älter als die Aufbewahrungsfrist sind. */
    public function purge(int $days): int
    {
        return $this->db->run('DELETE FROM audit_log WHERE created_at < ?', [\Vylaris\Core\Clock::db(\Vylaris\Core\Clock::now() - max(30, $days) * 86400)])->rowCount();
    }
}
