<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\Clock;

/** TOTP nach RFC 6238 (HMAC-SHA1, 6 Stellen, 30 s) – kompatibel mit gängigen Authenticator-Apps. */
final class TotpService
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    public function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public function codeAt(string $secretB32, int $step): string
    {
        $key = Base32::decode($secretB32);
        $counter = pack('J', $step); // 8 Byte Big-Endian
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function currentStep(?int $time = null): int
    {
        return intdiv($time ?? Clock::now(), self::PERIOD);
    }

    /**
     * Prüft einen Code mit ±$window Zeitschritten. Gibt den passenden Zeitschritt zurück (für Replay-Schutz)
     * oder null. Schritte <= $lastUsedStep werden abgelehnt.
     */
    public function verify(string $secretB32, string $code, int $lastUsedStep = 0, int $window = 1, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = $this->currentStep($time);
        $match = null;
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;
            if (hash_equals($this->codeAt($secretB32, $step), $code) && $step > $lastUsedStep) {
                $match = $step;
            }
        }
        return $match;
    }

    public function otpauthUri(string $issuer, string $account, string $secretB32): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secretB32,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
