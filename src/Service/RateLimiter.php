<?php
declare(strict_types=1);

namespace Vylaris\Service;

use Vylaris\Core\{Clock, Database};

/** Festes Zeitfenster-Rate-Limiting in der DB (kein Redis nötig). */
final class RateLimiter
{
    public function __construct(private Database $db, private string $salt)
    {
    }

    private function bucket(string $key): string
    {
        return hash_hmac('sha256', $key, $this->salt);
    }

    private function windowStart(int $window): int
    {
        return intdiv(Clock::now(), $window) * $window;
    }

    /** Zählt einen Versuch und gibt true zurück, wenn das Limit noch nicht überschritten ist. */
    public function hit(string $key, int $max, int $window): bool
    {
        $b = $this->bucket($key);
        $w = $this->windowStart($window);
        $exists = $this->db->scalar('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$b, $w]);
        if ($exists === null) {
            try {
                $this->db->insert('rate_limits', ['bucket' => $b, 'window_start' => $w, 'hits' => 1]);
                return 1 <= $max;
            } catch (\PDOException) {
                // paralleler Insert – unten per UPDATE zählen
            }
        }
        $this->db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND window_start = ?', [$b, $w]);
        $hits = (int) $this->db->scalar('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$b, $w]);
        return $hits <= $max;
    }

    /** Prüft ohne zu zählen. */
    public function tooMany(string $key, int $max, int $window): bool
    {
        $hits = $this->db->scalar(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?',
            [$this->bucket($key), $this->windowStart($window)]
        );
        return $hits !== null && (int) $hits >= $max;
    }

    public function clear(string $key, int $window): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket = ?', [$this->bucket($key)]);
    }

    public function purge(int $olderThanSeconds = 86400): int
    {
        return $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [Clock::now() - $olderThanSeconds])->rowCount();
    }
}
