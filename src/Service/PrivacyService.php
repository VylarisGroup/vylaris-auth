<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Branding\BrandingService;
use Vylaris\Core\{Clock, Config, Database};
use Vylaris\License\LicenseService;
use Vylaris\Repository\UserRepository;
use Vylaris\Security\Policy;

/**
 * DSGVO-Funktionen: Auskunft/Datenübertragbarkeit (Art. 15, 20), Löschung (Art. 17),
 * Speicherbegrenzung (Art. 5 Abs. 1 lit. e) und Anonymisierung von Protokolldaten.
 */
final class PrivacyService
{
    public const EXPORT_FORMAT = 'vylarisauth-export-1';

    public function __construct(
        private Database $db,
        private Config $config,
        private UserRepository $users,
        private Policy $policy,
        private Mailer $mailer,
        private AuditLogger $audit,
        private BrandingService $brand,
        private ?LicenseService $license = null,
    ) {
    }

    // ------------------------------------------------------------------ Auskunft / Export

    /**
     * Alle zur Person gespeicherten Daten in maschinenlesbarer Form. Enthält bewusst keine
     * Geheimnisse (Passwort-Hash, TOTP-Secret, Recovery-Code-Hashes, Token-Hashes, Passkey-Schlüsselmaterial).
     */
    public function export(int $userId): array
    {
        $u = $this->users->findById($userId) ?? throw new PrivacyException('Benutzer nicht gefunden.');
        $db = $this->db;
        $sessions = $db->all('SELECT created_at, last_seen_at, expires_at, revoked_at, ip, user_agent FROM sessions WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
        return [
            'meta' => [
                'format' => self::EXPORT_FORMAT,
                'exported_at' => gmdate('c', Clock::now()),
                'service' => $this->brand->get('name'),
                'controller' => $this->brand->get('privacy_controller'),
                'note' => 'Enthält Ihre bei diesem Dienst gespeicherten personenbezogenen Daten. Geheimnisse (Passwort-Hash, Schlüsselmaterial) sind aus Sicherheitsgründen nicht enthalten.',
            ],
            'profile' => [
                'id' => (int) $u['id'], 'email' => $u['email'], 'display_name' => $u['display_name'], 'status' => $u['status'],
                'email_verified_at' => $u['email_verified_at'], 'created_at' => $u['created_at'], 'updated_at' => $u['updated_at'],
                'last_login_at' => $u['last_login_at'], 'has_password' => (int) ($u['password_set'] ?? 1) === 1,
                'privacy_accepted_at' => $u['privacy_accepted_at'] ?? null, 'privacy_version' => $u['privacy_version'] ?? null,
            ],
            'roles' => $this->users->roleNames($userId),
            'app_roles' => $db->all('SELECT c.name AS application, r.name AS role FROM user_app_roles ur JOIN app_roles r ON r.id = ur.app_role_id JOIN oauth_clients c ON c.id = r.client_pk WHERE ur.user_id = ? ORDER BY c.name, r.name', [$userId]),
            'app_authorizations' => $db->all('SELECT c.name AS application, o.scopes, o.granted_at FROM oauth_consents o JOIN oauth_clients c ON c.id = o.client_pk WHERE o.user_id = ? ORDER BY c.name', [$userId]),
            'social_logins' => $db->all('SELECT provider_key AS provider, subject, email, display_name, created_at, last_login_at FROM social_identities WHERE user_id = ? ORDER BY provider_key', [$userId]),
            'second_factor' => [
                'totp_enabled' => $db->scalar('SELECT 1 FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId]) !== null,
                'passkeys' => $db->all('SELECT name, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at', [$userId]),
                'recovery_codes_unused' => (int) $db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]),
            ],
            'sessions' => $sessions,
            'activity_log' => $db->all('SELECT created_at, event, ip, user_agent, details FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 5000', [$userId]),
        ];
    }

    public function exportJson(int $userId): string
    {
        return json_encode($this->export($userId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ Löschung

    /** Darf das Konto gelöscht werden? Gibt eine anzeigbare Begründung zurück, wenn nicht. */
    public function erasureBlocker(int $userId): ?string
    {
        if ($this->policy->isSuperAdmin($userId) && $this->countSuperAdmins() <= 1) {
            return 'Das letzte Superadmin-Konto kann nicht gelöscht werden. Ernennen Sie zuerst einen weiteren Superadmin.';
        }
        return null;
    }

    private function countSuperAdmins(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id WHERE r.name = 'superadmin'");
    }

    /**
     * Löscht das Konto samt aller zugeordneten Daten unwiderruflich. Audit-Einträge bleiben als
     * anonymisierte Sicherheitsprotokolle erhalten (Art. 6 Abs. 1 lit. f, Rechenschaft/IT-Sicherheit), ohne
     * Personenbezug (kein Benutzerbezug, keine IP/Browserkennung, keine Details).
     * @param string $reason user_request | admin | inactivity
     */
    public function erase(int $userId, ?int $actorId, string $reason): void
    {
        $user = $this->users->findById($userId) ?? throw new PrivacyException('Benutzer nicht gefunden.');
        if (($why = $this->erasureBlocker($userId)) !== null) {
            throw new PrivacyException($why);
        }
        $email = (string) $user['email'];
        $seat = $this->license !== null ? $this->db->one('SELECT license_key, hwid FROM user_licenses WHERE user_id = ?', [$userId]) : null;
        $this->db->transaction(function () use ($userId, $email) {
            foreach (['sessions', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'user_tokens', 'mfa_totp', 'mfa_recovery_codes',
                      'webauthn_credentials', 'user_licenses', 'user_roles', 'user_app_roles', 'oauth_consents', 'social_identities'] as $table) {
                $this->db->run("DELETE FROM $table WHERE user_id = ?", [$userId]);
            }
            $this->db->run('DELETE FROM mail_queue WHERE to_email = ?', [$email]);
            // Protokolle anonymisieren: Personenbezug (Benutzer-ID, IP, Browser, Details) entfernen, Ereignistyp und Zeit bleiben.
            $this->db->run('UPDATE audit_log SET user_id = NULL, ip = NULL, user_agent = NULL, details = NULL WHERE user_id = ?', [$userId]);
            $this->db->run('UPDATE audit_log SET ip = NULL, user_agent = NULL WHERE actor_id = ?', [$userId]);
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $email) . '%';
            $this->db->run("UPDATE audit_log SET details = NULL WHERE details LIKE ? ESCAPE '!'", [$like]);
            $this->db->run('DELETE FROM users WHERE id = ?', [$userId]);
        });
        if ($seat !== null) {
            $this->license->releaseDevice($seat['license_key'], $seat['hwid']); // Platz beim Lizenzserver wieder freigeben
        }
        $this->audit->log('privacy.account_erased', null, ['reason' => $reason, 'ref' => $userId], $actorId);
    }

    // ------------------------------------------------------------------ Speicherbegrenzung

    /** IP-Adresse und Browserkennung in Protokollen nach Ablauf der Frist entfernen (Datenminimierung). */
    public function anonymizeAuditData(int $days): int
    {
        $cut = Clock::db(Clock::now() - max(1, $days) * 86400);
        return $this->db->run('UPDATE audit_log SET ip = NULL, user_agent = NULL WHERE created_at < ? AND (ip IS NOT NULL OR user_agent IS NOT NULL)', [$cut])->rowCount();
    }

    /**
     * Inaktive Konten: erst Vorwarnung per E-Mail, nach Ablauf der Warnfrist Löschung – nur wenn seit der Warnung
     * keine Anmeldung erfolgte. Administratoren werden nie automatisch gelöscht. 0 = deaktiviert.
     * @return array{warned:int,erased:int}
     */
    public function processInactiveAccounts(): array
    {
        $days = (int) $this->config->get('privacy.inactive_account_days', 0);
        $out = ['warned' => 0, 'erased' => 0];
        if ($days < 90) {
            return $out; // Sicherheitsnetz gegen versehentlich zu kurze Fristen
        }
        $warnDays = max(7, (int) $this->config->get('privacy.inactive_warning_days', 30));
        $inactiveBefore = Clock::db(Clock::now() - $days * 86400);
        $rows = $this->db->all(
            "SELECT id, email, display_name, last_login_at, created_at, inactivity_warned_at FROM users WHERE COALESCE(last_login_at, created_at) < ? ORDER BY id LIMIT 200",
            [$inactiveBefore]
        );
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($this->policy->isAdmin($id)) {
                continue;
            }
            $last = (string) ($r['last_login_at'] ?? $r['created_at']);
            $warned = $r['inactivity_warned_at'];
            if ($warned === null || $warned < $last) {
                $this->mailer->queue((string) $r['email'], 'Ihr Konto bei {app} wird bald gelöscht', "Hallo,\n\nSie haben sich seit längerer Zeit nicht mehr bei {app} angemeldet. Aus Gründen des Datenschutzes löschen wir inaktive Konten.\n\nWenn Sie Ihr Konto behalten möchten, melden Sie sich innerhalb von {$warnDays} Tagen an:\n" . $this->config->baseUrl() . "/login\n\nOhne Anmeldung wird das Konto samt Daten danach unwiderruflich gelöscht.\n");
                $this->db->update('users', ['inactivity_warned_at' => Clock::db()], 'id', $id);
                $this->audit->log('privacy.inactivity_warned', $id);
                $out['warned']++;
                continue;
            }
            if (Clock::fromDb((string) $warned) <= Clock::now() - $warnDays * 86400) {
                try {
                    $this->erase($id, null, 'inactivity');
                    $out['erased']++;
                } catch (PrivacyException) {
                    // geschützte Konten (z. B. letzter Superadmin) überspringen
                }
            }
        }
        return $out;
    }
}
