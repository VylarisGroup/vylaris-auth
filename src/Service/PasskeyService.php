<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Config, Database, Session};
use Vylaris\Repository\UserRepository;
use Vylaris\Service\WebAuthn\{Verifier, WebAuthnException};

/** Passkeys (WebAuthn): Optionen erzeugen, Registrierung/Anmeldung prüfen, Credentials verwalten. */
final class PasskeyService
{
    private const CHALLENGE_TTL = 300;

    public function __construct(
        private Database $db,
        private Config $config,
        private UserRepository $users,
        private Verifier $verifier,
        private MfaService $mfa,
        private AuditLogger $audit,
    ) {
    }

    public function origin(): string
    {
        $p = parse_url($this->config->baseUrl());
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (isset($p['port'])) {
            $origin .= ':' . $p['port'];
        }
        return $origin;
    }

    public function rpId(): string
    {
        return (string) $this->config->get('webauthn.rp_id', parse_url($this->config->baseUrl(), PHP_URL_HOST));
    }

    /** Stabiler, nicht rückrechenbarer User-Handle (64 Byte max., hier 32). */
    public function userHandle(int $userId): string
    {
        return Verifier::b64uEncode(hash_hmac('sha256', 'webauthn-user:' . $userId, $this->config->secretKey(), true));
    }

    public function credentials(int $userId): array
    {
        return $this->db->all('SELECT id, credential_id, name, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public function count(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$userId]);
    }

    // ------------------------------------------------------------------ Challenge-Verwaltung (einmalig, in der Session)

    private function newChallenge(Session $session, string $purpose, ?int $userId): string
    {
        $challenge = Verifier::b64uEncode(random_bytes(32));
        $session->set('webauthn', ['c' => $challenge, 'p' => $purpose, 'u' => $userId, 'e' => Clock::now() + self::CHALLENGE_TTL]);
        return $challenge;
    }

    /** Verbraucht die Challenge (auch bei Fehlschlag), prüft Zweck und Ablauf. */
    private function takeChallenge(Session $session, string $purpose): array
    {
        $c = $session->get('webauthn');
        $session->forget('webauthn');
        if (!is_array($c) || ($c['p'] ?? null) !== $purpose || ($c['e'] ?? 0) < Clock::now()) {
            throw new WebAuthnException('Die Anfrage ist abgelaufen. Bitte erneut versuchen.');
        }
        return $c;
    }

    // ------------------------------------------------------------------ Registrierung

    public function registrationOptions(Session $session, int $userId): array
    {
        $user = $this->users->findById($userId) ?? throw new WebAuthnException('Unbekannter Benutzer.');
        $challenge = $this->newChallenge($session, 'register', $userId);
        return [
            'challenge' => $challenge,
            'rp' => ['name' => (string) $this->config->get('webauthn.rp_name', 'Auth'), 'id' => $this->rpId()],
            'user' => ['id' => $this->userHandle($userId), 'name' => $user['email'], 'displayName' => $user['display_name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => array_map(
                static fn (array $c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                $this->credentials($userId)
            ),
        ];
    }

    public function finishRegistration(Session $session, int $userId, array $credential, string $name): void
    {
        $c = $this->takeChallenge($session, 'register');
        if ((int) $c['u'] !== $userId) {
            throw new WebAuthnException('Challenge gehört zu einem anderen Benutzer.');
        }
        $result = $this->verifier->verifyRegistration($credential, $c['c'], $this->origin(), $this->rpId());
        if ($this->db->scalar('SELECT 1 FROM webauthn_credentials WHERE credential_id = ?', [$result['credentialId']]) !== null) {
            throw new WebAuthnException('Dieser Passkey ist bereits registriert.');
        }
        $name = trim(mb_substr(preg_replace('/\s+/u', ' ', $name) ?? '', 0, 120));
        $this->db->insert('webauthn_credentials', [
            'user_id' => $userId,
            'credential_id' => $result['credentialId'],
            'credential_source' => json_encode(['pem' => $result['publicKeyPem'], 'alg' => $result['alg'], 'aaguid' => $result['aaguid'], 'uv' => $result['uv']]),
            'sign_count' => $result['signCount'],
            'name' => $name !== '' ? $name : 'Passkey',
            'created_at' => Clock::db(),
        ]);
        $this->audit->log('mfa.passkey_registered', $userId);
        if ($this->mfa->recoveryRemaining($userId) === 0 && !$this->mfa->hasTotp($userId) && $this->count($userId) === 1) {
            $session->flash('new_recovery_codes', $this->mfa->regenerateRecoveryCodes($userId));
        }
    }

    public function delete(int $userId, int $credentialRowId): bool
    {
        $n = $this->db->run('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?', [$credentialRowId, $userId])->rowCount();
        if ($n > 0) {
            $this->audit->log('mfa.passkey_removed', $userId);
            $this->mfa->cleanupRecoveryIfNoFactor($userId);
        }
        return $n > 0;
    }

    // ------------------------------------------------------------------ Authentifizierung

    /** purpose: 'mfa' (zweiter Faktor, Benutzer bekannt) oder 'login' (passwortlos, discoverable). */
    public function authenticationOptions(Session $session, string $purpose, ?int $userId = null): array
    {
        if (!in_array($purpose, ['mfa', 'login'], true) || ($purpose === 'mfa' && $userId === null)) {
            throw new WebAuthnException('Ungültiger Zweck.');
        }
        $challenge = $this->newChallenge($session, 'auth-' . $purpose, $userId);
        $opts = [
            'challenge' => $challenge,
            'rpId' => $this->rpId(),
            'timeout' => 60000,
            'userVerification' => $purpose === 'login' ? 'required' : 'preferred',
        ];
        if ($purpose === 'mfa') {
            $opts['allowCredentials'] = array_map(
                static fn (array $c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                $this->credentials($userId)
            );
        }
        return $opts;
    }

    /** @return int Benutzer-ID bei Erfolg; wirft WebAuthnException sonst */
    public function finishAuthentication(Session $session, string $purpose, array $credential): int
    {
        $c = $this->takeChallenge($session, 'auth-' . $purpose);
        $credId = $credential['rawId'] ?? null;
        if (!is_string($credId) || !preg_match('/^[A-Za-z0-9_-]{16,1400}$/', $credId)) {
            throw new WebAuthnException('Ungültige Credential-ID.');
        }
        $row = $this->db->one('SELECT * FROM webauthn_credentials WHERE credential_id = ?', [$credId]);
        if (!$row) {
            throw new WebAuthnException('Unbekannter Passkey.');
        }
        $uid = (int) $row['user_id'];
        if ($purpose === 'mfa' && (int) $c['u'] !== $uid) {
            throw new WebAuthnException('Passkey gehört zu einem anderen Konto.');
        }
        $source = json_decode((string) $row['credential_source'], true);
        if (!is_array($source) || !isset($source['pem'], $source['alg'])) {
            throw new WebAuthnException('Beschädigter Passkey-Eintrag.');
        }

        $result = $this->verifier->verifyAssertion(
            $credential, $c['c'], $this->origin(), $this->rpId(),
            (string) $source['pem'], (int) $source['alg'], (int) $row['sign_count'],
            $purpose === 'login'
        );
        if ($purpose === 'login') {
            // Discoverable: der userHandle muss zum Konto des Credentials passen.
            if ($result['userHandle'] === null || !hash_equals($this->userHandle($uid), $result['userHandle'])) {
                throw new WebAuthnException('User-Handle stimmt nicht überein.');
            }
            $user = $this->users->findById($uid);
            if (!$user || $this->users->isLocked($user)) {
                throw new WebAuthnException('Konto nicht verfügbar.');
            }
        }
        $this->db->run('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = ? WHERE id = ?', [$result['signCount'], Clock::db(), $row['id']]);
        $this->audit->log('mfa.passkey_ok', $uid, ['purpose' => $purpose]);
        return $uid;
    }
}
