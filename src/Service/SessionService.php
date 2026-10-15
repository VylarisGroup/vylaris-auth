<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Serverseitige Sitzungsverwaltung: Geräteübersicht, gezielter und globaler Widerruf. */
final class SessionService
{
    public function __construct(private Database $db)
    {
    }

    public static function hashId(string $phpSessionId): string
    {
        return hash('sha256', $phpSessionId);
    }

    /** @return array<int,array<string,mixed>> aktive Sitzungen eines Benutzers */
    public function listForUser(int $userId, ?string $currentHash = null): array
    {
        $rows = $this->db->all(
            'SELECT id, ip, user_agent, created_at, last_seen_at, expires_at FROM sessions
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_seen_at DESC',
            [$userId, Clock::db()]
        );
        foreach ($rows as &$r) {
            $r['current'] = $currentHash !== null && hash_equals($r['id'], $currentHash);
            $r['device'] = self::describeAgent((string) $r['user_agent']);
        }
        return $rows;
    }

    public function isActive(string $idHash, int $userId): bool
    {
        $row = $this->db->one('SELECT user_id, revoked_at, expires_at FROM sessions WHERE id = ?', [$idHash]);
        return $row !== null
            && (int) $row['user_id'] === $userId
            && $row['revoked_at'] === null
            && Clock::fromDb($row['expires_at']) > Clock::now();
    }

    /** Widerruf einer einzelnen Sitzung – nur wenn sie dem Benutzer gehört. */
    public function revoke(int $userId, string $idHash): bool
    {
        $n = $this->db->run(
            'UPDATE sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [Clock::db(), $idHash, $userId]
        )->rowCount();
        if ($n > 0) {
            $this->revokeTokensForSession($idHash);
        }
        return $n > 0;
    }

    /** Alle Sitzungen eines Benutzers widerrufen (optional außer der aktuellen). */
    public function revokeAll(int $userId, ?string $exceptHash = null): int
    {
        $rows = $this->db->all('SELECT id FROM sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
        $count = 0;
        foreach ($rows as $row) {
            if ($exceptHash !== null && hash_equals($row['id'], $exceptHash)) {
                continue;
            }
            $this->db->update('sessions', ['revoked_at' => Clock::db()], 'id', $row['id']);
            $this->revokeTokensForSession($row['id']);
            $count++;
        }
        return $count;
    }

    /** Widerruft alle an eine Sitzung gebundenen OAuth-Tokens. */
    public function revokeTokensForSession(string $idHash): void
    {
        $now = Clock::db();
        $this->db->run('UPDATE oauth_access_tokens SET revoked_at = ? WHERE session_id = ? AND revoked_at IS NULL', [$now, $idHash]);
        $this->db->run('UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE session_id = ? AND revoked_at IS NULL', [$now, $idHash]);
    }

    public function revokeAllTokensForUser(int $userId): void
    {
        $now = Clock::db();
        $this->db->run('UPDATE oauth_access_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $userId]);
        $this->db->run('UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->run('DELETE FROM sessions WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)',
            [Clock::db(), Clock::db(Clock::now() - 7 * 86400)])->rowCount();
    }

    /** Grobe Geräteanzeige ohne externe Bibliothek. */
    public static function describeAgent(string $ua): string
    {
        $os = match (true) {
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Unbekanntes Gerät',
        };
        $browser = match (true) {
            (bool) preg_match('/Edg\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/Firefox\//', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\//', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//', $ua) => 'Safari',
            default => 'Browser',
        };
        return "$browser auf $os";
    }
}
