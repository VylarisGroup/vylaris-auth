<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Config, Database};

/** TOTP-Verwaltung und einmalige Recovery-Codes. */
final class MfaService
{
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // ohne l,i,o,0,1
    private const RECOVERY_COUNT = 10;

    private string $encKey;
    private string $hmacKey;

    public function __construct(private Database $db, private Config $config, private TotpService $totp, private RateLimiter $limiter, private AuditLogger $audit)
    {
        $master = $config->secretKey();
        $this->encKey = sodium_crypto_generichash('vylaris/totp-secret-encryption', $master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->hmacKey = sodium_crypto_generichash('vylaris/recovery-code-hmac', $master, 32);
    }

    // ------------------------------------------------------------------ TOTP

    public function hasTotp(int $userId): bool
    {
        return $this->db->scalar('SELECT 1 FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId]) !== null;
    }

    /** Startet die Einrichtung: erzeugt (oder ersetzt unbestätigtes) Secret. @return array{secret:string,uri:string} */
    public function beginTotpSetup(int $userId, string $accountLabel): array
    {
        if ($this->hasTotp($userId)) {
            throw new \LogicException('TOTP ist bereits aktiv.');
        }
        $secret = $this->totp->generateSecret();
        $this->db->run('DELETE FROM mfa_totp WHERE user_id = ?', [$userId]);
        $this->db->insert('mfa_totp', [
            'user_id' => $userId,
            'secret_enc' => $this->encrypt($secret),
            'created_at' => Clock::db(),
        ]);
        return ['secret' => $secret, 'uri' => $this->totp->otpauthUri((string) $this->config->get('webauthn.rp_name', 'Auth'), $accountLabel, $secret)];
    }

    /** Aktueller (unbestätigter) Setup-Secret, um QR/Secret erneut anzuzeigen. */
    public function pendingSetup(int $userId, string $accountLabel): ?array
    {
        $row = $this->db->one('SELECT secret_enc FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NULL', [$userId]);
        if (!$row) {
            return null;
        }
        $secret = $this->decrypt($row['secret_enc']);
        return ['secret' => $secret, 'uri' => $this->totp->otpauthUri((string) $this->config->get('webauthn.rp_name', 'Auth'), $accountLabel, $secret)];
    }

    /** Bestätigt die Einrichtung mit einem gültigen Code. @return string[]|null neue Recovery-Codes (nur falls noch keine existierten) */
    public function confirmTotp(int $userId, string $code): ?array
    {
        if (!$this->limiter->hit('mfa-setup:' . $userId, 10, 900)) {
            return null;
        }
        $row = $this->db->one('SELECT secret_enc FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NULL', [$userId]);
        if (!$row) {
            return null;
        }
        $step = $this->totp->verify($this->decrypt($row['secret_enc']), $code);
        if ($step === null) {
            return null;
        }
        $this->db->run('UPDATE mfa_totp SET confirmed_at = ?, last_used_step = ? WHERE user_id = ?', [Clock::db(), $step, $userId]);
        $this->audit->log('mfa.totp_enabled', $userId);
        $codes = [];
        if ($this->recoveryRemaining($userId) === 0) {
            $codes = $this->regenerateRecoveryCodes($userId);
        }
        return $codes;
    }

    public function disableTotp(int $userId): void
    {
        $this->db->run('DELETE FROM mfa_totp WHERE user_id = ?', [$userId]);
        $this->audit->log('mfa.totp_disabled', $userId);
        $this->cleanupRecoveryIfNoFactor($userId);
    }

    /** Prüft einen TOTP-Code beim Login (mit Rate Limit und Replay-Schutz). */
    public function verifyTotp(int $userId, string $code): bool
    {
        if (!$this->limiter->hit('mfa:' . $userId, 5, 900)) {
            $this->audit->log('mfa.throttled', $userId);
            return false;
        }
        $row = $this->db->one('SELECT secret_enc, last_used_step FROM mfa_totp WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId]);
        if (!$row) {
            return false;
        }
        $step = $this->totp->verify($this->decrypt($row['secret_enc']), $code, (int) $row['last_used_step']);
        if ($step === null) {
            $this->audit->log('mfa.totp_failed', $userId);
            return false;
        }
        // Atomar: nur wenn der Schritt noch nicht verbraucht wurde.
        $n = $this->db->run('UPDATE mfa_totp SET last_used_step = ? WHERE user_id = ? AND last_used_step < ?', [$step, $userId, $step])->rowCount();
        if ($n !== 1) {
            return false;
        }
        $this->limiter->clear('mfa:' . $userId, 900);
        $this->audit->log('mfa.totp_ok', $userId);
        return true;
    }

    // ------------------------------------------------------------------ Recovery-Codes

    /** Erzeugt 10 neue Codes (alte werden ungültig). Klartext wird nur hier zurückgegeben. @return string[] */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $this->db->insert('mfa_recovery_codes', [
                'user_id' => $userId,
                'code_hash' => $this->hashCode($raw),
                'created_at' => Clock::db(),
            ]);
        }
        $this->audit->log('mfa.recovery_regenerated', $userId);
        return $codes;
    }

    public function recoveryRemaining(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /** Verbraucht einen Recovery-Code (einmalig, rate-limitiert). */
    public function consumeRecoveryCode(int $userId, string $code): bool
    {
        if (!$this->limiter->hit('mfa:' . $userId, 5, 900)) {
            $this->audit->log('mfa.throttled', $userId);
            return false;
        }
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
        if (strlen($norm) !== 10) {
            $this->audit->log('mfa.recovery_failed', $userId);
            return false;
        }
        $n = $this->db->run(
            'UPDATE mfa_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [Clock::db(), $userId, $this->hashCode($norm)]
        )->rowCount();
        $this->audit->log($n === 1 ? 'mfa.recovery_used' : 'mfa.recovery_failed', $userId);
        if ($n === 1) {
            $this->limiter->clear('mfa:' . $userId, 900);
        }
        return $n === 1;
    }

    public function cleanupRecoveryIfNoFactor(int $userId): void
    {
        $hasPasskey = $this->db->scalar('SELECT 1 FROM webauthn_credentials WHERE user_id = ?', [$userId]) !== null;
        if (!$this->hasTotp($userId) && !$hasPasskey) {
            $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        }
    }

    // ------------------------------------------------------------------ Krypto-Helfer (libsodium)

    private function hashCode(string $normalized): string
    {
        return hash_hmac('sha256', strtolower($normalized), $this->hmacKey);
    }

    private function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->encKey));
    }

    private function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Beschädigtes TOTP-Secret.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->encKey);
        if ($plain === false) {
            throw new \RuntimeException('TOTP-Secret konnte nicht entschlüsselt werden (app.secret geändert?).');
        }
        return $plain;
    }
}
