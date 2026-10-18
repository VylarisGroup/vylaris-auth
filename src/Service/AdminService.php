<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Config, Database};
use Vylaris\License\{LicenseException, LicenseService};
use Vylaris\Repository\UserRepository;
use Vylaris\Security\Policy;

/** Administrative Aktionen – jede Methode prüft die Berechtigung des Akteurs serverseitig und schreibt ins Audit-Log. */
final class AdminService
{
    public function __construct(
        private Database $db,
        private Config $config,
        private UserRepository $users,
        private Policy $policy,
        private SessionService $sessions,
        private MfaService $mfa,
        private PasswordService $passwords,
        private TokenService $tokens,
        private Mailer $mailer,
        private AuditLogger $audit,
        private ?LicenseService $license = null,
    ) {
    }

    private function target(int $actorId, int $targetId, bool $allowSelf = false): array
    {
        $user = $this->users->findById($targetId) ?? throw new AuthorizationException('Benutzer nicht gefunden.');
        if (!$this->policy->isAdmin($actorId)) {
            $this->audit->log('authz.denied', $actorId, ['action' => 'admin', 'target' => $targetId]);
            throw new AuthorizationException('Keine Berechtigung.');
        }
        if (!$allowSelf && $actorId === $targetId) {
            throw new AuthorizationException('Diese Aktion ist für das eigene Konto nicht erlaubt.');
        }
        if (!$this->policy->canManageUser($actorId, $targetId) && $actorId !== $targetId) {
            $this->audit->log('authz.denied', $actorId, ['action' => 'manage_user', 'target' => $targetId]);
            throw new AuthorizationException('Administratorkonten können nur von Superadmins verwaltet werden.');
        }
        return $user;
    }

    public function lock(int $actorId, int $targetId, string $reason): void
    {
        $this->target($actorId, $targetId);
        $this->users->setLocked($targetId, true, mb_substr(trim($reason), 0, 255) ?: 'Gesperrt durch Administrator');
        $this->sessions->revokeAll($targetId);
        $this->sessions->revokeAllTokensForUser($targetId);
        $this->audit->log('admin.user_locked', $targetId, ['reason' => mb_substr($reason, 0, 120)], $actorId);
    }

    public function unlock(int $actorId, int $targetId): void
    {
        $this->target($actorId, $targetId);
        $this->users->setLocked($targetId, false);
        $this->db->update('users', ['failed_logins' => 0, 'locked_until' => null], 'id', $targetId);
        $this->audit->log('admin.user_unlocked', $targetId, [], $actorId);
    }

    public function grantRole(int $actorId, int $targetId, string $role): void
    {
        $this->target($actorId, $targetId);
        if (!$this->policy->canGrantRole($actorId, $role)) {
            $this->audit->log('authz.denied', $actorId, ['action' => 'grant_role', 'role' => $role]);
            throw new AuthorizationException('Diese Rolle darf nur ein Superadmin vergeben.');
        }
        if (!$this->users->assignRole($targetId, $role)) {
            throw new \InvalidArgumentException('Unbekannte Rolle.');
        }
        $this->audit->log('admin.role_granted', $targetId, ['role' => $role], $actorId);
    }

    public function revokeRole(int $actorId, int $targetId, string $role): void
    {
        $this->target($actorId, $targetId);
        if (!$this->policy->canGrantRole($actorId, $role)) {
            $this->audit->log('authz.denied', $actorId, ['action' => 'revoke_role', 'role' => $role]);
            throw new AuthorizationException('Diese Rolle darf nur ein Superadmin entziehen.');
        }
        if ($role === 'superadmin' && $this->countWithRole('superadmin') <= 1) {
            throw new AuthorizationException('Der letzte Superadmin kann nicht entfernt werden.');
        }
        $this->users->removeRole($targetId, $role);
        $this->audit->log('admin.role_revoked', $targetId, ['role' => $role], $actorId);
    }

    public function countWithRole(string $role): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = ?', [$role]);
    }

    public function revokeSessions(int $actorId, int $targetId): int
    {
        $this->target($actorId, $targetId, true);
        $n = $this->sessions->revokeAll($targetId);
        $this->sessions->revokeAllTokensForUser($targetId);
        $this->audit->log('admin.sessions_revoked', $targetId, ['count' => $n], $actorId);
        return $n;
    }

    /** Setzt MFA zurück (verlorenes Gerät). Alle Sitzungen werden beendet. */
    public function resetMfa(int $actorId, int $targetId): void
    {
        $this->target($actorId, $targetId);
        $this->db->run('DELETE FROM mfa_totp WHERE user_id = ?', [$targetId]);
        $this->db->run('DELETE FROM webauthn_credentials WHERE user_id = ?', [$targetId]);
        $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$targetId]);
        $this->sessions->revokeAll($targetId);
        $this->audit->log('admin.mfa_reset', $targetId, [], $actorId);
    }

    /** Legt ein Konto an und versendet einen Einladungslink zum Setzen des Passworts (3 Tage gültig). */
    public function createUser(int $actorId, string $email, string $name, string $licenseKey = ''): int
    {
        if (!$this->policy->isAdmin($actorId)) {
            throw new AuthorizationException('Keine Berechtigung.');
        }
        $email = UserRepository::normalizeEmail($email);
        $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Bitte gültige E-Mail-Adresse und Namen angeben.');
        }
        if ($this->users->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Diese E-Mail-Adresse ist bereits vergeben.');
        }
        $licensed = $this->license !== null && $this->license->required();
        $licenseKey = LicenseService::normalizeKey($licenseKey);
        $explicit = false;
        if ($licensed) {
            if ($licenseKey === '' && !$this->license->hasPool()) {
                throw new \InvalidArgumentException('Es ist keine Lizenz im Pool hinterlegt (Admin → Lizenz) – jedes neue Konto belegt einen Platz.');
            }
            $explicit = $licenseKey !== '';
            try {
                $licenseKey = $this->license->resolveKey($licenseKey);
            } catch (LicenseException $e) {
                throw new \InvalidArgumentException($e->getMessage());
            }
        }
        // Unbenutzbares Zufallspasswort – der Benutzer setzt sein eigenes über den Einladungslink.
        $bound = null;
        try {
            $id = $this->db->transaction(function () use ($email, $name, $licensed, $licenseKey, $explicit, &$bound): int {
                $id = $this->users->create($email, $this->passwords->hash(bin2hex(random_bytes(32))), $name, false);
                $this->users->assignRole($id, 'user');
                if ($licensed) {
                    $bound = $this->license->bind($id, $licenseKey, !$explicit)->key;
                }
                return $id;
            });
        } catch (LicenseException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } catch (\Throwable $e) {
            if ($bound !== null && isset($id)) {
                $this->license->releaseDevice($bound, $this->license->hwid($id));
            }
            throw $e;
        }
        $token = $this->tokens->create($id, TokenService::RESET_PASSWORD, 3 * 86400);
        $this->mailer->queue($email, 'Einladung zu {app}', "Für Sie wurde ein {org}-Konto angelegt.\n\nLegen Sie Ihr Passwort fest (3 Tage gültig):\n" . $this->config->baseUrl() . '/password/reset?token=' . $token . "\n");
        $this->audit->log('admin.user_created', $id, [], $actorId);
        return $id;
    }

    public function stats(): array
    {
        $count = fn (string $sql, array $p = []) => (int) $this->db->scalar($sql, $p);
        return [
            'users' => $count('SELECT COUNT(*) FROM users'),
            'locked' => $count("SELECT COUNT(*) FROM users WHERE status = 'locked'"),
            'unverified' => $count('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL'),
            'clients' => $count('SELECT COUNT(*) FROM oauth_clients WHERE revoked_at IS NULL'),
            'sessions' => $count('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > ?', [Clock::db()]),
            'failed24h' => $count("SELECT COUNT(*) FROM audit_log WHERE event = 'auth.login_failed' AND created_at > ?", [Clock::db(Clock::now() - 86400)]),
        ];
    }
}
