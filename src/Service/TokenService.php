<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Zeitlich begrenzte Einmal-Tokens (E-Mail-Verifizierung, Passwort-Reset). Nur Hash in der DB. */
final class TokenService
{
    public const VERIFY_EMAIL = 'verify_email';
    public const RESET_PASSWORD = 'reset_password';
    public const DELETE_ACCOUNT = 'delete_account';
    public const CHANGE_EMAIL = 'change_email';

    public function __construct(private Database $db)
    {
    }

    /** Erzeugt ein Token (Klartext nur hier verfügbar) und entwertet ältere gleicher Art. */
    public function create(int $userId, string $type, int $ttlSeconds, ?string $payload = null): string
    {
        $this->db->run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [Clock::db(), $userId, $type]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert('user_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => hash('sha256', $token),
            'expires_at' => Clock::db(Clock::now() + $ttlSeconds),
            'created_at' => Clock::db(),
            'payload' => $payload,
        ]);
        return $token;
    }

    /** Verbraucht das Token atomar; gibt user_id oder null zurück. */
    public function consume(string $token, string $type): ?int
    {
        $hash = hash('sha256', $token);
        $row = $this->db->one('SELECT id, user_id, expires_at, used_at FROM user_tokens WHERE token_hash = ? AND type = ?', [$hash, $type]);
        if (!$row || $row['used_at'] !== null || Clock::fromDb($row['expires_at']) < Clock::now()) {
            return null;
        }
        $n = $this->db->run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [Clock::db(), $row['id']])->rowCount();
        return $n === 1 ? (int) $row['user_id'] : null;
    }

    /** Wie peek(), liefert zusätzlich die gespeicherten Zusatzdaten. @return array{user_id:int,payload:?string}|null */
    public function peekWithPayload(string $token, string $type): ?array
    {
        $row = $this->db->one('SELECT user_id, payload, expires_at, used_at FROM user_tokens WHERE token_hash = ? AND type = ?', [hash('sha256', $token), $type]);
        if (!$row || $row['used_at'] !== null || Clock::fromDb($row['expires_at']) < Clock::now()) {
            return null;
        }
        return ['user_id' => (int) $row['user_id'], 'payload' => $row['payload']];
    }

    /** Prüft ohne zu verbrauchen (z. B. um das Reset-Formular anzuzeigen). */
    public function peek(string $token, string $type): ?int
    {
        $row = $this->db->one('SELECT user_id, expires_at, used_at FROM user_tokens WHERE token_hash = ? AND type = ?', [hash('sha256', $token), $type]);
        if (!$row || $row['used_at'] !== null || Clock::fromDb($row['expires_at']) < Clock::now()) {
            return null;
        }
        return (int) $row['user_id'];
    }

    public function purge(): int
    {
        return $this->db->run('DELETE FROM user_tokens WHERE expires_at < ?', [Clock::db(Clock::now() - 86400)])->rowCount();
    }
}
