<?php
declare(strict_types=1);

namespace Vylaris\License;

use Vylaris\Core\{Clock, Config, Database};
use Vylaris\Service\{AuditLogger, RateLimiter};
use Vylaris\Social\HttpClient;

/**
 * Lizenzierung: Jeder Nutzer wird mit einem Lizenzschlüssel registriert und belegt dort einen "Geräteplatz"
 * (hwid = pseudonyme Kennung des Nutzers). Das Gerätelimit des Schlüssels begrenzt damit die Nutzerzahl.
 * Die Funktionen ("Scopes") ergeben sich aus dem Feld features der Lizenz.
 *
 * Der Status wird pro Nutzer zwischengespeichert (user_licenses) und per Cron/Anmeldung aufgefrischt – Seitenaufrufe
 * lösen keine Netzwerkanfragen aus. Ist der Lizenzserver länger als license.grace_days nicht erreichbar, entfallen
 * die lizenzabhängigen Funktionen; Anmeldung und SSO bleiben davon unberührt.
 */
final class LicenseService
{
    /** Scope => Beschreibung. Diese Namen tragen Sie im Tarif (features) beim Lizenzserver ein. */
    public const FEATURES = [
        'social_login' => 'Social Login: Anbieter mit dem eigenen Konto verknüpfen; Anbieter verwalten (Admin)',
        'passkeys' => 'Passkeys (WebAuthn) einrichten',
        'oidc_apps' => 'OIDC-Anwendungen anlegen und ändern (Admin)',
        'whitelabel' => 'Branding / Whitelabel ändern (Admin)',
        'audit_log' => 'Audit-Log einsehen (Admin)',
    ];

    /** Öffentlicher Ed25519-Schlüssel des Lizenzservers (licence.vylaris.ch); gilt, wenn license.public_key nicht gesetzt ist. */
    public const DEFAULT_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAhV7L176abx5a05XciRbOOeFj6wkCnNiDrwEXw7KceiU=\n-----END PUBLIC KEY-----\n";

    private LicenseClient $client;

    public function __construct(private Database $db, private Config $config, HttpClient $http, private RateLimiter $limiter, private AuditLogger $audit)
    {
        $this->client = new LicenseClient(
            $http,
            (string) $config->get('license.api_url', 'https://licence.vylaris.ch'),
            trim((string) $config->get('license.public_key', '')) ?: self::DEFAULT_PUBLIC_KEY,
            (string) $config->get('license.product', 'vylarisauth'),
        );
    }

    public function client(): LicenseClient
    {
        return $this->client;
    }

    /** Ist ein Lizenzschlüssel für neue Konten Pflicht? (Standard: ja) */
    public function required(): bool
    {
        return (bool) $this->config->get('license.required', true);
    }

    public static function normalizeKey(string $key): string
    {
        return trim($key);
    }

    public static function validKeyFormat(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{5,63}$/', $key);
    }

    /** Pseudonyme, stabile Geräte-ID des Nutzers (keine E-Mail, kein Name beim Lizenzserver). */
    public function hwid(int $userId): string
    {
        return 'vu-' . substr(hash_hmac('sha256', 'license-hwid:' . $userId, $this->config->secretKey()), 0, 32);
    }

    public static function mask(string $key): string
    {
        return strlen($key) <= 6 ? '****' : substr($key, 0, 4) . '…' . substr($key, -2);
    }

    // ------------------------------------------------------------------ Registrierung / Bindung

    /**
     * Vorabprüfung vor dem Anlegen des Kontos: Schlüssel gültig, Produkt passt, freier Platz vorhanden.
     * @throws LicenseException
     */
    public function precheck(string $key): LicenseReply
    {
        $key = self::normalizeKey($key);
        if (!self::validKeyFormat($key)) {
            throw new LicenseException('Bitte geben Sie einen gültigen Lizenzschlüssel ein.', 'BAD_FORMAT');
        }
        $r = $this->client->validate($key);
        if (!$r->valid) {
            throw LicenseException::forCode($r->code);
        }
        if ($r->maxDevices !== null && $r->devices !== null && $r->devices >= $r->maxDevices) {
            throw LicenseException::forCode('DEVICE_LIMIT');
        }
        return $r;
    }

    /**
     * Belegt für den Nutzer einen Platz beim Lizenzserver und speichert den Status.
     * @throws LicenseException
     */
    public function bind(int $userId, string $key): LicenseReply
    {
        $key = self::normalizeKey($key);
        if (!self::validKeyFormat($key)) {
            throw new LicenseException('Bitte geben Sie einen gültigen Lizenzschlüssel ein.', 'BAD_FORMAT');
        }
        $hwid = $this->hwid($userId);
        $r = $this->client->activate($key, $hwid, 'Nutzer ' . $userId);
        if (!$r->valid) {
            throw LicenseException::forCode($r->code);
        }
        $now = Clock::db();
        try {
            $this->db->run('DELETE FROM user_licenses WHERE user_id = ?', [$userId]);
            $this->db->insert('user_licenses', [
                'user_id' => $userId, 'license_key' => $key, 'hwid' => $hwid, 'valid' => 1, 'code' => 'OK',
                'plan' => $r->plan ?: null, 'features' => json_encode($r->features), 'expires_at' => $this->expiry($r),
                'max_devices' => $r->maxDevices, 'activated_at' => $now, 'checked_at' => $now, 'ok_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $this->releaseDevice($key, $hwid); // Platz nicht verfallen lassen, wenn das lokale Speichern scheitert
            throw $e;
        }
        $this->audit->log('license.bound', $userId, ['key' => self::mask($key), 'plan' => $r->plan]);
        return $r;
    }

    /** Gibt einen Platz beim Lizenzserver frei; bei Fehlern wird die Freigabe vorgemerkt (Cron wiederholt sie). */
    public function releaseDevice(string $key, string $hwid): void
    {
        try {
            $r = $this->client->deactivate($key, $hwid);
            if ($r->valid || in_array($r->code, ['NOT_ACTIVATED', 'NOT_FOUND', 'EXPIRED', 'BLOCKED'], true)) {
                return; // erledigt (bzw. nichts mehr freizugeben)
            }
        } catch (LicenseException) {
            // Server nicht erreichbar -> vormerken
        }
        $this->db->insert('license_releases', ['license_key' => $key, 'hwid' => $hwid, 'attempts' => 1, 'created_at' => Clock::db()]);
    }

    /** Konto entfernt: Platz freigeben und lokalen Eintrag löschen. */
    public function release(int $userId): void
    {
        $row = $this->row($userId);
        if ($row === null) {
            return;
        }
        $this->db->run('DELETE FROM user_licenses WHERE user_id = ?', [$userId]);
        $this->releaseDevice($row['license_key'], $row['hwid']);
    }

    // ------------------------------------------------------------------ Status & Scopes

    private function row(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM user_licenses WHERE user_id = ?', [$userId]);
    }

    /**
     * Lizenzstand eines Nutzers (nur aus der Datenbank, ohne Netzwerk).
     * @return array{keyless:bool,valid:bool,code:string,plan:string,features:string[],expires_at:?string,key:string,checked_at:?string}
     */
    public function state(int $userId): array
    {
        $row = $this->row($userId);
        if ($row === null) {
            $f = $this->config->get('license.keyless_features', 'all');
            $features = $f === 'all' ? array_keys(self::FEATURES) : array_values(array_intersect((array) $f, array_keys(self::FEATURES)));
            return ['keyless' => true, 'valid' => true, 'code' => 'KEYLESS', 'plan' => '', 'features' => $features, 'expires_at' => null, 'key' => '', 'checked_at' => null];
        }
        $code = (string) $row['code'];
        $valid = (int) $row['valid'] === 1;
        $exp = $row['expires_at'] !== null ? Clock::fromDb((string) $row['expires_at']) : null;
        if ($valid && $exp !== null && $exp <= Clock::now()) {
            $valid = false;
            $code = 'EXPIRED';
        }
        $graceSeconds = max(1, (int) $this->config->get('license.grace_days', 7)) * 86400;
        $okAt = $row['ok_at'] !== null ? Clock::fromDb((string) $row['ok_at']) : 0;
        if ($valid && Clock::now() - $okAt > $graceSeconds) {
            $valid = false;
            $code = 'UNVERIFIED';
        }
        $features = [];
        if ($valid) {
            $stored = json_decode((string) $row['features'], true);
            $features = array_values(array_intersect(is_array($stored) ? $stored : [], array_keys(self::FEATURES)));
        }
        return ['keyless' => false, 'valid' => $valid, 'code' => $code, 'plan' => (string) $row['plan'], 'features' => $features, 'expires_at' => $row['expires_at'], 'key' => (string) $row['license_key'], 'checked_at' => $row['checked_at']];
    }

    public function allows(int $userId, string $feature): bool
    {
        if (!$this->required()) {
            return true;
        }
        return in_array($feature, $this->state($userId)['features'], true);
    }

    // ------------------------------------------------------------------ Auffrischen

    /** Fragt den Lizenzserver (heartbeat) und aktualisiert den Status. @return bool Server erreicht */
    public function refresh(int $userId): bool
    {
        $row = $this->row($userId);
        if ($row === null) {
            return true;
        }
        try {
            $r = $this->client->heartbeat($row['license_key'], $row['hwid']);
        } catch (LicenseException $e) {
            if (in_array($e->licenseCode, ['NOT_CONFIGURED'], true)) {
                return false;
            }
            return false; // nicht erreichbar: Zwischenspeicher bleibt, Karenzzeit läuft
        }
        $now = Clock::db();
        $upd = ['valid' => $r->valid ? 1 : 0, 'code' => mb_substr($r->code, 0, 32), 'checked_at' => $now];
        if ($r->valid) {
            $upd += ['ok_at' => $now, 'plan' => $r->plan ?: null, 'features' => json_encode($r->features), 'expires_at' => $this->expiry($r), 'max_devices' => $r->maxDevices];
        } elseif ($r->code === 'NOT_ACTIVATED' && $r->maxDevices !== null) {
            // Platz wurde im Lizenzpanel zurückgesetzt: nicht stillschweigend neu belegen – Status zeigen.
        }
        $this->db->update('user_licenses', $upd, 'user_id', $userId);
        if (!$r->valid && (int) $row['valid'] === 1) {
            $this->audit->log('license.invalidated', $userId, ['reason' => $r->code, 'key' => self::mask($row['license_key'])]);
        }
        return true;
    }

    /** Cron: veraltete Einträge auffrischen (höchstens $limit, bricht bei Serverausfall ab). @return int aufgefrischte Einträge */
    public function refreshStale(int $limit = 100, bool $force = false): int
    {
        if (!$this->client->configured()) {
            return 0;
        }
        $ttl = max(60, (int) $this->config->get('license.refresh_seconds', 3600));
        $ids = $this->db->all('SELECT user_id FROM user_licenses WHERE checked_at < ? ORDER BY checked_at LIMIT ' . (int) $limit, [Clock::db($force ? Clock::now() + 1 : Clock::now() - $ttl)]);
        $n = 0;
        foreach ($ids as $r) {
            if (!$this->refresh((int) $r['user_id'])) {
                break;
            }
            $n++;
        }
        return $n;
    }

    /** Cron: vorgemerkte Platzfreigaben wiederholen. @return int erledigt */
    public function retryReleases(int $limit = 50): int
    {
        $done = 0;
        foreach ($this->db->all('SELECT * FROM license_releases ORDER BY id LIMIT ' . (int) $limit) as $r) {
            try {
                $res = $this->client->deactivate($r['license_key'], $r['hwid']);
            } catch (LicenseException) {
                $this->db->run('UPDATE license_releases SET attempts = attempts + 1 WHERE id = ?', [$r['id']]);
                break;
            }
            if ($res->valid || in_array($res->code, ['NOT_ACTIVATED', 'NOT_FOUND', 'EXPIRED', 'BLOCKED'], true)
                || (int) $r['attempts'] > 50) {
                $this->db->run('DELETE FROM license_releases WHERE id = ?', [$r['id']]);
                $done++;
            }
        }
        return $done;
    }

    /** Admin-Übersicht: belegte Plätze je Schlüssel. @return array<int,array<string,mixed>> */
    public function seatsByKey(): array
    {
        return $this->db->all('SELECT license_key, MAX(plan) AS plan, MAX(max_devices) AS max_devices, COUNT(*) AS seats, MIN(valid) AS valid, MAX(checked_at) AS checked_at FROM user_licenses GROUP BY license_key ORDER BY seats DESC, license_key');
    }

    public function keylessUsers(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE id NOT IN (SELECT user_id FROM user_licenses)');
    }

    private function expiry(LicenseReply $r): ?string
    {
        $t = $r->expiresAt !== null ? strtotime($r->expiresAt) : false;
        return $t === false ? null : Clock::db($t);
    }
}
