<?php
declare(strict_types=1);

namespace Vylaris\Security;

use Vylaris\Repository\UserRepository;

/** Zentrale serverseitige Autorisierung. Jede geschützte Aktion fragt hier nach. */
final class Policy
{
    public function __construct(private UserRepository $users)
    {
    }

    public function hasRole(int $userId, string $role): bool
    {
        return in_array($role, $this->users->roleNames($userId), true);
    }

    public function isAdmin(int $userId): bool
    {
        $roles = $this->users->roleNames($userId);
        return in_array('admin', $roles, true) || in_array('superadmin', $roles, true);
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $this->hasRole($userId, 'superadmin');
    }

    /** Admins verwalten Benutzer, aber nur Superadmins verändern Admin-Rollen/-Konten. */
    public function canManageUser(int $actorId, int $targetId): bool
    {
        if (!$this->isAdmin($actorId)) {
            return false;
        }
        if ($this->isAdmin($targetId) && !$this->isSuperAdmin($actorId)) {
            return false;
        }
        return true;
    }

    public function canGrantRole(int $actorId, string $role): bool
    {
        if (in_array($role, ['admin', 'superadmin'], true)) {
            return $this->isSuperAdmin($actorId);
        }
        return $this->isAdmin($actorId);
    }
}
