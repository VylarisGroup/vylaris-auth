<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

use Vylaris\Core\{Clock, Database};
use Vylaris\Service\{AuditLogger, SessionService};

/** Registrierung und Verwaltung von OIDC-Clients (Anwendungen). */
final class ClientService
{
    public const KNOWN_SCOPES = ['openid', 'profile', 'email', 'roles', 'offline_access'];

    public function __construct(private Database $db, private AuditLogger $audit, private SessionService $sessions)
    {
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * @param string[] $redirectUris
     * @param string[] $postLogoutUris
     * @return array{id:int,client_id:string,client_secret:?string}  Secret nur hier im Klartext (nur bei confidential)
     */
    public function create(string $name, array $redirectUris, array $postLogoutUris = [], bool $confidential = true, string $scopes = 'openid profile email', bool $skipConsent = false, string $accessPolicy = 'all', ?int $actorId = null): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Der Anwendungsname muss 2–120 Zeichen lang sein.');
        }
        if (!in_array($accessPolicy, ['all', 'assigned'], true)) {
            throw new \InvalidArgumentException('Ungültige Zugriffsrichtlinie.');
        }
        $scopes = $this->normalizeScopes($scopes);
        $redirectUris = $this->cleanUris($redirectUris);
        $postLogoutUris = $this->cleanUris($postLogoutUris);
        if (!$redirectUris) {
            throw new \InvalidArgumentException('Mindestens eine Redirect-URI ist erforderlich.');
        }

        $clientId = 'vy_' . self::random(16);
        $secret = $confidential ? self::random(32) : null;
        $pk = $this->db->transaction(function (Database $db) use ($name, $clientId, $secret, $confidential, $scopes, $skipConsent, $accessPolicy, $redirectUris, $postLogoutUris) {
            $pk = $db->insert('oauth_clients', [
                'client_id' => $clientId,
                'name' => $name,
                'secret_hash' => $secret !== null ? password_hash($secret, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT) : null,
                'is_confidential' => $confidential ? 1 : 0,
                'is_active' => 1,
                'allowed_scopes' => $scopes,
                'skip_consent' => $skipConsent ? 1 : 0,
                'access_policy' => $accessPolicy,
                'created_at' => Clock::db(),
            ]);
            $this->writeUris($db, $pk, 'redirect', $redirectUris);
            $this->writeUris($db, $pk, 'post_logout', $postLogoutUris);
            return $pk;
        });
        $this->audit->log('client.created', null, ['client_id' => $clientId, 'name' => $name], $actorId, $clientId);
        return ['id' => $pk, 'client_id' => $clientId, 'client_secret' => $secret];
    }

    public function normalizeScopes(string $scopes): string
    {
        $list = array_values(array_unique(preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        foreach ($list as $s) {
            if (!in_array($s, self::KNOWN_SCOPES, true)) {
                throw new \InvalidArgumentException("Unbekannter Scope: $s");
            }
        }
        if (!in_array('openid', $list, true)) {
            array_unshift($list, 'openid');
        }
        return implode(' ', $list);
    }

    /** @return string[] */
    private function cleanUris(array $uris): array
    {
        $out = [];
        foreach ($uris as $u) {
            $u = trim((string) $u);
            if ($u === '') {
                continue;
            }
            if (($err = RedirectUri::validate($u)) !== null) {
                throw new \InvalidArgumentException($u . ': ' . $err);
            }
            $out[$u] = $u;
        }
        return array_values($out);
    }

    private function writeUris(Database $db, int $pk, string $type, array $uris): void
    {
        $db->run('DELETE FROM oauth_client_uris WHERE client_pk = ? AND type = ?', [$pk, $type]);
        foreach ($uris as $u) {
            $db->insert('oauth_client_uris', ['client_pk' => $pk, 'type' => $type, 'uri' => $u]);
        }
    }

    public function updateUris(int $pk, array $redirectUris, array $postLogoutUris, ?int $actorId = null): void
    {
        $redirectUris = $this->cleanUris($redirectUris);
        if (!$redirectUris) {
            throw new \InvalidArgumentException('Mindestens eine Redirect-URI ist erforderlich.');
        }
        $postLogoutUris = $this->cleanUris($postLogoutUris);
        $this->db->transaction(function (Database $db) use ($pk, $redirectUris, $postLogoutUris) {
            $this->writeUris($db, $pk, 'redirect', $redirectUris);
            $this->writeUris($db, $pk, 'post_logout', $postLogoutUris);
        });
        $this->audit->log('client.uris_updated', null, ['client_pk' => $pk], $actorId);
    }

    public function updateSettings(int $pk, string $name, string $scopes, bool $skipConsent, string $accessPolicy, ?int $actorId = null): void
    {
        if (!in_array($accessPolicy, ['all', 'assigned'], true) || mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 120) {
            throw new \InvalidArgumentException('Ungültige Angaben.');
        }
        $this->db->update('oauth_clients', [
            'name' => trim($name), 'allowed_scopes' => $this->normalizeScopes($scopes),
            'skip_consent' => $skipConsent ? 1 : 0, 'access_policy' => $accessPolicy,
        ], 'id', $pk);
        $this->audit->log('client.updated', null, ['client_pk' => $pk], $actorId);
    }

    public function findByClientId(string $clientId): ?array
    {
        return $this->db->one('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
    }

    public function findByPk(int $pk): ?array
    {
        return $this->db->one('SELECT * FROM oauth_clients WHERE id = ?', [$pk]);
    }

    public function all(): array
    {
        return $this->db->all('SELECT id, client_id, name, is_confidential, is_active, allowed_scopes, skip_consent, access_policy, created_at, revoked_at FROM oauth_clients ORDER BY id DESC');
    }

    /** @return string[] */
    public function uris(int $pk, string $type = 'redirect'): array
    {
        return array_column($this->db->all('SELECT uri FROM oauth_client_uris WHERE client_pk = ? AND type = ? ORDER BY id', [$pk, $type]), 'uri');
    }

    public function isUsable(array $client): bool
    {
        return (int) $client['is_active'] === 1 && $client['revoked_at'] === null;
    }

    public function verifySecret(array $client, ?string $secret): bool
    {
        if ((int) $client['is_confidential'] !== 1) {
            return true; // öffentliche Clients: Schutz ausschließlich über PKCE
        }
        static $dummy = null;
        $dummy ??= password_hash(bin2hex(random_bytes(16)), defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        $hash = (string) ($client['secret_hash'] ?? '');
        // Konstante Arbeit auch bei fehlendem Secret/Hash
        $ok = password_verify((string) $secret, $hash !== '' ? $hash : $dummy) && $hash !== '';
        return $ok && $secret !== null && $secret !== '';
    }

    /** Neues Secret erzeugen (altes sofort ungültig); Klartext nur hier. */
    public function rotateSecret(int $pk, ?int $actorId = null): string
    {
        $client = $this->findByPk($pk) ?? throw new \InvalidArgumentException('Unbekannte Anwendung.');
        if ((int) $client['is_confidential'] !== 1) {
            throw new \InvalidArgumentException('Öffentliche Clients haben kein Secret.');
        }
        $secret = self::random(32);
        $this->db->update('oauth_clients', ['secret_hash' => password_hash($secret, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)], 'id', $pk);
        $this->audit->log('client.secret_rotated', null, ['client_id' => $client['client_id']], $actorId, $client['client_id']);
        return $secret;
    }

    public function setActive(int $pk, bool $active, ?int $actorId = null): void
    {
        $client = $this->findByPk($pk) ?? throw new \InvalidArgumentException('Unbekannte Anwendung.');
        $this->db->update('oauth_clients', ['is_active' => $active ? 1 : 0], 'id', $pk);
        if (!$active) {
            $this->revokeTokens($pk);
        }
        $this->audit->log($active ? 'client.activated' : 'client.deactivated', null, ['client_id' => $client['client_id']], $actorId, $client['client_id']);
    }

    /** Endgültiger Widerruf: Client deaktiviert, alle Tokens ungültig, Einwilligungen gelöscht. */
    public function revoke(int $pk, ?int $actorId = null): void
    {
        $client = $this->findByPk($pk) ?? throw new \InvalidArgumentException('Unbekannte Anwendung.');
        $this->db->update('oauth_clients', ['is_active' => 0, 'revoked_at' => Clock::db()], 'id', $pk);
        $this->revokeTokens($pk);
        $this->db->run('DELETE FROM oauth_consents WHERE client_pk = ?', [$pk]);
        $this->audit->log('client.revoked', null, ['client_id' => $client['client_id']], $actorId, $client['client_id']);
    }

    public function revokeTokens(int $clientPk, ?int $userId = null): void
    {
        $now = Clock::db();
        $extra = $userId !== null ? ' AND user_id = ' . (int) $userId : '';
        $this->db->run('UPDATE oauth_access_tokens SET revoked_at = ? WHERE client_pk = ? AND revoked_at IS NULL' . $extra, [$now, $clientPk]);
        $this->db->run('UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE client_pk = ? AND revoked_at IS NULL' . $extra, [$now, $clientPk]);
        $this->db->run('UPDATE oauth_auth_codes SET used_at = ? WHERE client_pk = ? AND used_at IS NULL' . $extra, [$now, $clientPk]);
    }

    // ---------------------------------------------------------------- Einwilligungen

    public function hasConsent(int $userId, array $client, array $scopes): bool
    {
        if ((int) $client['skip_consent'] === 1) {
            return true;
        }
        $row = $this->db->one('SELECT scopes FROM oauth_consents WHERE user_id = ? AND client_pk = ?', [$userId, $client['id']]);
        if (!$row) {
            return false;
        }
        $granted = explode(' ', (string) $row['scopes']);
        return array_diff($scopes, $granted) === [];
    }

    public function grantConsent(int $userId, array $client, array $scopes): void
    {
        $this->db->run('DELETE FROM oauth_consents WHERE user_id = ? AND client_pk = ?', [$userId, $client['id']]);
        $this->db->insert('oauth_consents', ['user_id' => $userId, 'client_pk' => $client['id'], 'scopes' => implode(' ', $scopes), 'granted_at' => Clock::db()]);
        $this->audit->log('oidc.consent_granted', $userId, ['scopes' => implode(' ', $scopes)], null, $client['client_id']);
    }

    /** Benutzer entzieht einer Anwendung den Zugriff. */
    public function revokeConsent(int $userId, int $clientPk): void
    {
        $this->db->run('DELETE FROM oauth_consents WHERE user_id = ? AND client_pk = ?', [$userId, $clientPk]);
        $this->revokeTokens($clientPk, $userId);
        $this->audit->log('oidc.consent_revoked', $userId);
    }

    public function connectedApps(int $userId): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.client_id, o.scopes, o.granted_at FROM oauth_consents o JOIN oauth_clients c ON c.id = o.client_pk WHERE o.user_id = ? ORDER BY o.granted_at DESC',
            [$userId]
        );
    }

    // ---------------------------------------------------------------- Anwendungsrollen

    public function createRole(int $clientPk, string $name, ?string $description = null): int
    {
        $name = trim($name);
        if (!preg_match('/^[a-z][a-z0-9_.:-]{1,58}$/', $name)) {
            throw new \InvalidArgumentException('Rollenname: 2–59 Zeichen, Kleinbuchstaben, Ziffern und _ . : -');
        }
        return $this->db->insert('app_roles', ['client_pk' => $clientPk, 'name' => $name, 'description' => $description]);
    }

    public function roles(int $clientPk): array
    {
        return $this->db->all('SELECT id, name, description FROM app_roles WHERE client_pk = ? ORDER BY name', [$clientPk]);
    }

    public function deleteRole(int $clientPk, int $roleId): void
    {
        $this->db->run('DELETE FROM app_roles WHERE id = ? AND client_pk = ?', [$roleId, $clientPk]);
    }

    public function assignRole(int $userId, int $clientPk, int $roleId): bool
    {
        $ok = $this->db->scalar('SELECT 1 FROM app_roles WHERE id = ? AND client_pk = ?', [$roleId, $clientPk]);
        if ($ok === null) {
            return false;
        }
        if ($this->db->scalar('SELECT 1 FROM user_app_roles WHERE user_id = ? AND app_role_id = ?', [$userId, $roleId]) === null) {
            $this->db->insert('user_app_roles', ['user_id' => $userId, 'app_role_id' => $roleId]);
        }
        return true;
    }

    public function unassignRole(int $userId, int $clientPk, int $roleId): void
    {
        $this->db->run(
            'DELETE FROM user_app_roles WHERE user_id = ? AND app_role_id = ? AND app_role_id IN (SELECT id FROM app_roles WHERE client_pk = ?)',
            [$userId, $roleId, $clientPk]
        );
    }

    /** @return string[] Rollennamen eines Benutzers in genau dieser Anwendung */
    public function userRoles(int $userId, int $clientPk): array
    {
        return array_column($this->db->all(
            'SELECT r.name FROM app_roles r JOIN user_app_roles u ON u.app_role_id = r.id WHERE u.user_id = ? AND r.client_pk = ? ORDER BY r.name',
            [$userId, $clientPk]
        ), 'name');
    }

    /** Zugriff auf die Anwendung gemäß Richtlinie ("all" oder nur Benutzer mit Anwendungsrolle). */
    public function userMayAccess(int $userId, array $client): bool
    {
        if (!$this->isUsable($client)) {
            return false;
        }
        if ($client['access_policy'] === 'all') {
            return true;
        }
        return $this->userRoles($userId, (int) $client['id']) !== [];
    }
}
