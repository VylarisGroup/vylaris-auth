<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Config, Database, Session};
use Vylaris\License\{LicenseException, LicenseService};
use Vylaris\Repository\UserRepository;

final class AuthService
{
    private const VERIFY_TTL = 86400;   // 24 h
    private const RESET_TTL = 3600;     // 1 h
    private const PENDING_MFA_TTL = 300; // 5 min

    public function __construct(
        private Database $db,
        private Config $config,
        private UserRepository $users,
        private PasswordService $passwords,
        private TokenService $tokens,
        private RateLimiter $limiter,
        private Mailer $mailer,
        private AuditLogger $audit,
        private SessionService $sessions,
        private ?LicenseService $license = null,
    ) {
    }

    // ---------------------------------------------------------------- Registrierung

    /** @return array{ok:bool,errors:string[]} – bei vorhandener E-Mail bewusst gleiche Antwort (keine Enumeration) */
    public function register(string $email, string $password, string $displayName, string $ip = '', bool $privacyAccepted = false, string $privacyVersion = '', string $licenseKey = ''): array
    {
        if (!$this->config->get('security.allow_registration', true)) {
            return ['ok' => false, 'errors' => ['Die Registrierung ist derzeit deaktiviert.']];
        }
        $email = UserRepository::normalizeEmail($email);
        $displayName = trim(preg_replace('/\s+/u', ' ', $displayName) ?? '');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 120) {
            $errors[] = 'Der Name muss zwischen 2 und 120 Zeichen lang sein.';
        }
        $errors = array_merge($errors, $this->passwords->validate($password, [$email, $displayName, strstr($email, '@', true) ?: '']));
        if ($this->config->get('privacy.require_acceptance', true) && !$privacyAccepted) {
            $errors[] = 'Bitte bestätigen Sie, dass Sie die Datenschutzerklärung zur Kenntnis genommen haben.';
        }
        $licensed = $this->license !== null && $this->license->required();
        $licenseKey = LicenseService::normalizeKey($licenseKey);
        if ($licensed && $licenseKey === '' && !$this->license->hasPool()) {
            $errors[] = 'Bitte geben Sie Ihren Lizenzschlüssel ein.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if ($ip !== '' && !$this->limiter->hit('register:' . $ip, 10, 3600)) {
            return ['ok' => false, 'errors' => ['Zu viele Registrierungen. Bitte später erneut versuchen.']];
        }
        if ($licensed) {
            // Schlüssel vorab prüfen (gültig, richtiges Produkt, freier Platz) – bevor irgendetwas angelegt wird.
            try {
                $licenseKey = $this->license->resolveKey($licenseKey);
            } catch (LicenseException $e) {
                $this->audit->log('auth.register_license_refused', null, ['reason' => $e->licenseCode, 'ref' => $this->emailRef($email)]);
                return ['ok' => false, 'errors' => [$e->getMessage()]];
            }
        }

        $existing = $this->users->findByEmail($email);
        if ($existing) {
            $this->mailer->queue($email, 'Registrierung bei {app}', "Für diese E-Mail-Adresse existiert bereits ein Konto.\n\nFalls Sie sich nicht erneut registrieren wollten, können Sie diese Nachricht ignorieren. Ihr Passwort können Sie bei Bedarf über \"Passwort vergessen\" zurücksetzen:\n" . $this->config->baseUrl() . "/password/forgot\n");
            $this->audit->log('auth.register_duplicate', (int) $existing['id']);
            return ['ok' => true, 'errors' => []];
        }

        $verified = !$this->config->get('security.require_email_verification', true);
        $bound = null;
        try {
            $id = $this->db->transaction(function () use ($email, $password, $displayName, $verified, $privacyAccepted, $privacyVersion, $licensed, $licenseKey, &$bound): int {
                $id = $this->users->create($email, $this->passwords->hash($password), $displayName, $verified);
                $this->users->assignRole($id, 'user');
                if ($privacyAccepted) {
                    $this->db->update('users', ['privacy_accepted_at' => Clock::db(), 'privacy_version' => mb_substr($privacyVersion, 0, 20)], 'id', $id);
                }
                if ($licensed) {
                    // Belegt den Platz beim Lizenzserver; schlägt das fehl, wird das Konto nicht angelegt.
                    $this->license->bind($id, $licenseKey);
                    $bound = [$licenseKey, $this->license->hwid($id)];
                }
                return $id;
            });
        } catch (LicenseException $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        } catch (\Throwable $e) {
            if ($bound !== null) {
                $this->license->releaseDevice($bound[0], $bound[1]); // Platz nicht verfallen lassen
            }
            throw $e;
        }
        $this->audit->log('auth.register', $id);
        if (!$verified) {
            $this->sendVerification($id, $email);
        }
        return ['ok' => true, 'errors' => []];
    }

    /** Pseudonyme Referenz auf eine E-Mail-Adresse für Protokolle (keine Klartext-Adressen von Nichtbenutzern im Audit-Log). */
    private function emailRef(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), $this->config->secretKey()), 0, 16);
    }

    public function sendVerification(int $userId, string $email): void
    {
        $token = $this->tokens->create($userId, TokenService::VERIFY_EMAIL, self::VERIFY_TTL);
        $this->mailer->queue($email, 'Bitte bestätigen Sie Ihre E-Mail-Adresse', "Willkommen bei {app}.\n\nBitte bestätigen Sie Ihre E-Mail-Adresse (24 Stunden gültig):\n" . $this->config->baseUrl() . '/verify-email?token=' . $token . "\n\nWenn Sie sich nicht registriert haben, ignorieren Sie diese Nachricht.\n");
    }

    public function resendVerification(string $email, string $ip): void
    {
        if (!$this->limiter->hit('resend:' . $ip, 5, 3600) || !$this->limiter->hit('resend-acct:' . UserRepository::normalizeEmail($email), 3, 3600)) {
            return;
        }
        $user = $this->users->findByEmail($email);
        if ($user && $user['email_verified_at'] === null && !$this->users->isLocked($user)) {
            $this->sendVerification((int) $user['id'], $user['email']);
        }
    }

    public function verifyEmail(string $token): bool
    {
        $userId = $this->tokens->consume($token, TokenService::VERIFY_EMAIL);
        if ($userId === null) {
            return false;
        }
        $this->users->markVerified($userId);
        $this->audit->log('auth.email_verified', $userId);
        return true;
    }

    // ---------------------------------------------------------------- Login

    public function login(string $email, string $password, string $ip): AuthResult
    {
        $email = UserRepository::normalizeEmail($email);
        $max = (int) $this->config->get('security.login_max_attempts_ip', 20);
        $maxAcct = (int) $this->config->get('security.login_max_attempts_account', 5);
        $window = (int) $this->config->get('security.login_window_seconds', 900);

        if ($this->limiter->tooMany('login-ip:' . $ip, $max, $window) || $this->limiter->tooMany('login-acct:' . $email, $maxAcct, $window)) {
            $this->audit->log('auth.login_throttled', null, ['email_ref' => $this->emailRef($email)]);
            return new AuthResult('throttled');
        }

        $user = $this->users->findByEmail($email);
        // Gleiche Rechenzeit, egal ob das Konto existiert.
        $hash = $user['password_hash'] ?? $this->passwords->dummyHash();
        $passwordOk = $this->passwords->verify($password, $hash) && $user !== null;

        if (!$passwordOk) {
            $this->limiter->hit('login-ip:' . $ip, $max, $window);
            $this->limiter->hit('login-acct:' . $email, $maxAcct, $window);
            if ($user) {
                $this->users->recordLoginFailure((int) $user['id']);
            }
            $this->audit->log('auth.login_failed', $user ? (int) $user['id'] : null, ['email_ref' => $this->emailRef($email)]);
            return new AuthResult('invalid');
        }

        $uid = (int) $user['id'];
        if ($this->users->isLocked($user)) {
            $this->audit->log('auth.login_blocked_locked', $uid);
            return new AuthResult('locked');
        }
        if ($this->config->get('security.require_email_verification', true) && $user['email_verified_at'] === null) {
            return new AuthResult('unverified', $uid);
        }

        $this->limiter->clear('login-acct:' . $email, $window);
        if ($this->passwords->needsRehash($user['password_hash'])) {
            $this->users->setPasswordHash($uid, $this->passwords->hash($password));
        }
        $this->users->recordLoginSuccess($uid);

        if ($this->hasMfa($uid)) {
            return new AuthResult('mfa_required', $uid);
        }
        $this->audit->log('auth.login', $uid);
        return new AuthResult('ok', $uid);
    }

    public function hasMfa(int $userId): bool
    {
        $totp = $this->db->scalar('SELECT 1 FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId]);
        if ($totp !== null) {
            return true;
        }
        return $this->db->scalar('SELECT 1 FROM webauthn_credentials WHERE user_id = ?', [$userId]) !== null;
    }

    /** Merkt den Benutzer nach erfolgreichem Passwort-Schritt für die MFA-Prüfung vor (noch nicht angemeldet). */
    public function startMfaChallenge(Session $session, int $userId, array $firstFactorAmr = ['pwd']): void
    {
        $session->regenerate();
        $session->set('mfa_first_amr', $firstFactorAmr);
        $session->set('mfa_pending_uid', $userId);
        $session->set('mfa_pending_at', Clock::now());
    }

    public function pendingMfaUser(Session $session): ?int
    {
        $uid = $session->get('mfa_pending_uid');
        $at = (int) $session->get('mfa_pending_at', 0);
        if ($uid === null || Clock::now() - $at > self::PENDING_MFA_TTL) {
            $session->forget('mfa_pending_uid');
            return null;
        }
        return (int) $uid;
    }

    /** Schließt die Anmeldung ab: neue Session-ID, Benutzerbindung. $amr z. B. ['pwd'] oder ['pwd','otp']. */
    public function establishSession(Session $session, int $userId, array $amr = ['pwd']): void
    {
        $session->regenerate();
        $session->forget('mfa_pending_uid');
        $session->forget('mfa_pending_at');
        $session->bindUser($userId);
        $session->set('auth_time', Clock::now());
        $session->set('amr', $amr);
        $session->set('csrf', bin2hex(random_bytes(32)));
    }

    public function logout(Session $session): void
    {
        $uid = $session->userId();
        if ($uid !== null) {
            $this->sessions->revokeTokensForSession($session->idHash());
            $this->audit->log('auth.logout', $uid);
        }
        $session->destroy();
    }

    /** Aktueller Benutzer, sofern die Sitzung serverseitig noch gültig ist (nicht widerrufen/gesperrt). */
    public function currentUser(Session $session): ?array
    {
        $uid = $session->userId();
        if ($uid === null) {
            return null;
        }
        $user = $this->users->findById($uid);
        if (!$user || $this->users->isLocked($user) || !$this->sessions->isActive($session->idHash(), $uid)) {
            return null;
        }
        return $user;
    }

    // ---------------------------------------------------------------- Passwort

    public function requestPasswordReset(string $email, string $ip): void
    {
        if (!$this->limiter->hit('reset-ip:' . $ip, 10, 3600) || !$this->limiter->hit('reset-acct:' . UserRepository::normalizeEmail($email), 3, 3600)) {
            return;
        }
        $user = $this->users->findByEmail($email);
        if (!$user || $this->users->isLocked($user)) {
            return; // gleiche Antwort nach außen
        }
        $token = $this->tokens->create((int) $user['id'], TokenService::RESET_PASSWORD, self::RESET_TTL);
        $this->mailer->queue($user['email'], 'Passwort zurücksetzen', "Für Ihr Konto wurde ein Passwort-Reset angefordert (1 Stunde gültig):\n" . $this->config->baseUrl() . '/password/reset?token=' . $token . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht – Ihr Passwort bleibt unverändert.\n");
        $this->audit->log('auth.reset_requested', (int) $user['id']);
    }

    /** @return string[] Fehler; leer = Erfolg */
    public function resetPassword(string $token, string $newPassword): array
    {
        $userId = $this->tokens->peek($token, TokenService::RESET_PASSWORD);
        if ($userId === null) {
            return ['Der Link ist ungültig oder abgelaufen.'];
        }
        $user = $this->users->findById($userId);
        $errors = $this->passwords->validate($newPassword, [$user['email'] ?? '', $user['display_name'] ?? '']);
        if ($errors) {
            return $errors;
        }
        if ($this->tokens->consume($token, TokenService::RESET_PASSWORD) === null) {
            return ['Der Link ist ungültig oder abgelaufen.'];
        }
        $this->users->setPasswordHash($userId, $this->passwords->hash($newPassword));
        $this->users->markVerified($userId); // Zugriff auf das Postfach wurde nachgewiesen
        $this->sessions->revokeAll($userId);
        $this->sessions->revokeAllTokensForUser($userId);
        $this->audit->log('auth.password_reset', $userId);
        return [];
    }

    /** @return string[] Fehler; leer = Erfolg */
    public function changePassword(int $userId, string $current, string $new, ?string $keepSessionHash = null): array
    {
        $user = $this->users->findById($userId);
        if (!$user || !$this->passwords->verify($current, $user['password_hash'])) {
            $this->audit->log('auth.password_change_failed', $userId);
            return ['Das aktuelle Passwort ist falsch.'];
        }
        $errors = $this->passwords->validate($new, [$user['email'], $user['display_name']]);
        if ($errors) {
            return $errors;
        }
        $this->users->setPasswordHash($userId, $this->passwords->hash($new));
        $this->sessions->revokeAll($userId, $keepSessionHash);
        $this->audit->log('auth.password_changed', $userId);
        return [];
    }
}
