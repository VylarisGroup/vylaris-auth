<?php
declare(strict_types=1);

namespace Vylaris\Repository;

use Vylaris\Core\{Clock, Database};

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [self::normalizeEmail($email)]);
    }

    public function create(string $email, string $passwordHash, string $displayName, bool $verified = false): int
    {
        $now = Clock::db();
        return $this->db->insert('users', [
            'email' => self::normalizeEmail($email),
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'status' => 'active',
            'email_verified_at' => $verified ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markVerified(int $id): void
    {
        $this->db->update('users', ['email_verified_at' => Clock::db(), 'updated_at' => Clock::db()], 'id', $id);
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $this->db->update('users', ['password_hash' => $hash, 'password_set' => 1, 'updated_at' => Clock::db()], 'id', $id);
    }

    public function updateProfile(int $id, string $displayName): void
    {
        $this->db->update('users', ['display_name' => $displayName, 'updated_at' => Clock::db()], 'id', $id);
    }

    public function updateEmail(int $id, string $email): void
    {
        $this->db->update('users', ['email' => self::normalizeEmail($email), 'email_verified_at' => null, 'updated_at' => Clock::db()], 'id', $id);
    }

    public function recordLoginSuccess(int $id): void
    {
        $this->db->update('users', ['failed_logins' => 0, 'last_login_at' => Clock::db()], 'id', $id);
    }

    public function recordLoginFailure(int $id): void
    {
        $this->db->run('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$id]);
    }

    public function setLocked(int $id, bool $locked, ?string $reason = null): void
    {
        $this->db->update('users', [
            'status' => $locked ? 'locked' : 'active',
            'locked_reason' => $locked ? $reason : null,
            'updated_at' => Clock::db(),
        ], 'id', $id);
    }

    public function isLocked(array $user): bool
    {
        return $user['status'] === 'locked'
            || ($user['locked_until'] !== null && Clock::fromDb($user['locked_until']) > Clock::now());
    }

    /** @return string[] globale Rollennamen */
    public function roleNames(int $userId): array
    {
        return array_column($this->db->all(
            'SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.name',
            [$userId]
        ), 'name');
    }

    public function assignRole(int $userId, string $role): bool
    {
        $roleId = $this->db->scalar('SELECT id FROM roles WHERE name = ?', [$role]);
        if ($roleId === null) {
            return false;
        }
        $exists = $this->db->scalar('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId]);
        if ($exists === null) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $roleId]);
        }
        return true;
    }

    public function removeRole(int $userId, string $role): void
    {
        $this->db->run(
            'DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT id FROM roles WHERE name = ?)',
            [$userId, $role]
        );
    }

    public function search(string $term = '', int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        if ($term !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
            return $this->db->all(
                "SELECT id, email, display_name, status, email_verified_at, last_login_at, created_at FROM users
                 WHERE email LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT $limit OFFSET $offset",
                [$like, $like]
            );
        }
        return $this->db->all(
            "SELECT id, email, display_name, status, email_verified_at, last_login_at, created_at FROM users ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users');
    }
}
