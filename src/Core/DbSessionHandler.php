<?php
declare(strict_types=1);

namespace Vylaris\Core;

/**
 * PHP-Session-Handler auf Tabelle `sessions`. Die Session-ID wird nur als SHA-256 gespeichert,
 * widerrufene oder abgelaufene Sitzungen liefern leere Daten (=> Neuanmeldung).
 */
final class DbSessionHandler implements \SessionHandlerInterface
{
    private ?int $userId = null;
    private bool $userIdSet = false;

    public function __construct(
        private Database $db,
        private int $idleTimeout,
        private int $absoluteTtl,
        private string $ip = '',
        private string $userAgent = '',
    ) {
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
        $this->userIdSet = true;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->db->one('SELECT data, revoked_at, expires_at, created_at, user_id FROM sessions WHERE id = ?', [hash('sha256', $id)]);
        if (!$row) {
            return '';
        }
        if ($row['revoked_at'] !== null || Clock::fromDb($row['expires_at']) <= Clock::now()) {
            return '';
        }
        // Base64 gespeichert: PHP-Session-Daten können Binärbytes enthalten, die utf8mb4-Spalten (MySQL strict) ablehnen würden.
        $raw = base64_decode((string) $row['data'], true);
        return $raw === false ? '' : $raw;
    }

    public function write(string $id, string $data): bool
    {
        $hash = hash('sha256', $id);
        $now = Clock::now();
        $row = $this->db->one('SELECT created_at, revoked_at FROM sessions WHERE id = ?', [$hash]);
        if ($row && $row['revoked_at'] !== null) {
            return true; // widerrufene Sitzung nie wiederbeleben
        }
        $created = $row ? Clock::fromDb($row['created_at']) : $now;
        $expires = min($created + $this->absoluteTtl, $now + $this->idleTimeout);
        if ($row) {
            $set = ['data' => base64_encode($data), 'last_seen_at' => Clock::db($now), 'expires_at' => Clock::db($expires)];
            if ($this->userIdSet) {
                $set['user_id'] = $this->userId;
            }
            $this->db->update('sessions', $set, 'id', $hash);
        } else {
            $this->db->insert('sessions', [
                'id' => $hash,
                'user_id' => $this->userId,
                'data' => base64_encode($data),
                'ip' => $this->ip ? substr($this->ip, 0, 45) : null,
                'user_agent' => $this->userAgent !== '' ? mb_substr($this->userAgent, 0, 250) : null,
                'created_at' => Clock::db($now),
                'last_seen_at' => Clock::db($now),
                'expires_at' => Clock::db($expires),
            ]);
        }
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->run('DELETE FROM sessions WHERE id = ?', [hash('sha256', $id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->run('DELETE FROM sessions WHERE expires_at < ?', [Clock::db()])->rowCount();
    }
}
