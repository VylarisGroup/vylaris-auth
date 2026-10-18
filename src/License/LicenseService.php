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

    /** Fester Lizenzserver. Bewusst nicht konfigurierbar. */
    public const API_URL = 'https://licence.vylaris.ch';

    /** Produkt-Kennung beim Lizenzserver. Bewusst nicht konfigurierbar. */
    public const PRODUCT = 'vylarisauth';

    /** Fester öffentlicher Ed25519-Schlüssel des Lizenzservers. Bewusst nicht konfigurierbar. */
    public const DEFAULT_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAhV7L176abx5a05XciRbOOeFj6wkCnNiDrwEXw7KceiU=\n-----END PUBLIC KEY-----\n";

    /** Codes, bei denen ein Nutzer auf einen anderen Pool-Schlüssel umgestuft wird. */
    private const REASSIGN_CODES = ['EXPIRED', 'BLOCKED', 'NOT_FOUND', 'WRONG_PRODUCT', 'NOT_ACTIVATED', 'DEVICE_LIMIT'];

    private LicenseClient $client;

    /** $client nur für Tests: In Betrieb gilt immer API_URL + DEFAULT_PUBLIC_KEY, nichts davon kommt aus der Config. */
    public function __construct(private Database $db, private Config $config, HttpClient $http, private RateLimiter $limiter, private AuditLogger $audit, ?LicenseClient $client = null)
    {
        $this->client = $client ?? new LicenseClient($http, self::API_URL, self::DEFAULT_PUBLIC_KEY, self::PRODUCT);
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
     * Mit $fallback wird bei vollem/ungültigem Schlüssel (z. B. wenn der Platz zwischen Vorabprüfung und Belegung
     * vergeben wurde) automatisch der nächste Pool-Schlüssel versucht. Die Antwort nennt den tatsächlich genutzten Schlüssel.
     * @throws LicenseException
     */
    public function bind(int $userId, string $key, bool $fallback = false): LicenseReply
    {
        $key = self::normalizeKey($key);
        if (!self::validKeyFormat($key)) {
            throw new LicenseException('Bitte geben Sie einen gültigen Lizenzschlüssel ein.', 'BAD_FORMAT');
        }
        $hwid = $this->hwid($userId);
        $candidates = [$key];
        if ($fallback) {
            foreach ($this->pool() as $p) {
                if ($p['license_key'] !== $key) {
                    $candidates[] = (string) $p['license_key'];
                }
            }
        }
        $r = null;
        $first = null;
        foreach ($candidates as $cand) {
            $r = $this->client->activate($cand, $hwid, 'Nutzer ' . $userId);
            if ($r->valid) {
                break;
            }
            $first ??= $r;
            if (!in_array($r->code, self::REASSIGN_CODES, true)) {
                throw LicenseException::forCode($r->code);
            }
        }
        if ($r === null || !$r->valid) {
            throw LicenseException::forCode(($first ?? $r)?->code ?? 'DEVICE_LIMIT');
        }
        $key = $r->key;
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
        $this->touchPool($r);
        $this->audit->log('license.bound', $userId, ['key' => self::mask($key), 'plan' => $r->plan]);
        return $r;
    }

    // ------------------------------------------------------------------ Schlüssel-Pool (einmal eingeben, automatisch verwenden)

    /** @return array<int,array{id:int|string,license_key:string,added_by:?int,created_at:string}> */
    public function pool(): array
    {
        try {
            return $this->db->all('SELECT * FROM license_pool ORDER BY id');
        } catch (\Throwable) {
            return []; // Migration 006 noch nicht eingespielt
        }
    }

    public function hasPool(): bool
    {
        return $this->pool() !== [];
    }

    /**
     * Schlüssel hinterlegen. Mit $validate wird er vorher beim Lizenzserver geprüft (ein gerade voller Schlüssel ist erlaubt).
     * @throws LicenseException
     */
    public function addToPool(string $key, ?int $actorId, bool $validate = true): void
    {
        $key = self::normalizeKey($key);
        if (!self::validKeyFormat($key)) {
            throw new LicenseException('Bitte geben Sie einen gültigen Lizenzschlüssel ein.', 'BAD_FORMAT');
        }
        if ($validate) {
            try {
                $this->precheck($key);
            } catch (LicenseException $e) {
                if ($e->licenseCode !== 'DEVICE_LIMIT') {
                    throw $e;
                }
            }
        }
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM license_pool WHERE license_key = ?', [$key]) > 0) {
            return;
        }
        $this->db->insert('license_pool', ['license_key' => $key, 'added_by' => $actorId, 'created_at' => Clock::db()]);
        $this->audit->log('license.pool_added', $actorId, ['key' => self::mask($key)], $actorId);
        if ($validate) {
            $this->refreshPool(true);
            try {
                $this->reassignInvalid(); // Nutzer ohne gültige Lizenz sofort auf den neuen Schlüssel setzen
            } catch (LicenseException) {
                // Server nicht erreichbar: der Cron holt es nach
            }
        }
    }

    /** Pool-Schlüssel anhand der ID (für die Auswahl bei der Nutzererstellung). */
    public function poolKey(int $id): ?string
    {
        $k = $this->db->scalar('SELECT license_key FROM license_pool WHERE id = ?', [$id]);
        return $k === null ? null : (string) $k;
    }

    /**
     * Auswahlliste für die Nutzererstellung (nur lokale Daten).
     * @return array<int,array{id:int,key:string,plan:string,free:?int,max:?int,usable:bool,code:string}>
     */
    public function poolOptions(): array
    {
        $out = [];
        foreach ($this->pool() as $p) {
            $used = (int) $this->db->scalar('SELECT COUNT(*) FROM user_licenses WHERE license_key = ?', [$p['license_key']]);
            $max = isset($p['max_devices']) && $p['max_devices'] !== null ? (int) $p['max_devices'] : null;
            $devices = isset($p['devices']) && $p['devices'] !== null ? max((int) $p['devices'], $used) : $used;
            $free = $max !== null ? max(0, $max - $devices) : null;
            $code = (string) ($p['code'] ?? '');
            $usable = (!isset($p['valid']) || $p['valid'] === null || (int) $p['valid'] === 1) && ($free === null || $free > 0);
            $out[] = ['id' => (int) $p['id'], 'key' => self::mask((string) $p['license_key']), 'plan' => (string) ($p['plan'] ?? ''), 'free' => $free, 'max' => $max, 'usable' => $usable, 'code' => $code];
        }
        return $out;
    }

    /** Merkt sich Plan/Plätze eines Pool-Schlüssels aus einer frischen Serverantwort. */
    private function touchPool(LicenseReply $r): void
    {
        try {
            $this->db->update('license_pool', [
                'valid' => ($r->valid || $r->code === 'DEVICE_LIMIT') ? 1 : 0, 'code' => mb_substr($r->code, 0, 32), 'plan' => $r->plan ?: null,
                'max_devices' => $r->maxDevices, 'devices' => $r->devices, 'expires_at' => $this->expiry($r), 'checked_at' => Clock::db(),
            ], 'license_key', $r->key);
        } catch (\Throwable) {
            // Migration 007 fehlt noch – nicht kritisch
        }
    }

    /**
     * Prüft die hinterlegten Schlüssel beim Lizenzserver (validate) und speichert Status, Plan, Plätze, Ablauf.
     * @return int geprüfte Schlüssel
     */
    public function refreshPool(bool $force = false): int
    {
        if (!$this->client->configured()) {
            return 0;
        }
        $ttl = max(60, (int) $this->config->get('license.refresh_seconds', 3600));
        $n = 0;
        foreach ($this->pool() as $p) {
            $checked = $p['checked_at'] ?? null;
            if (!$force && $checked !== null && Clock::fromDb((string) $checked) > Clock::now() - $ttl) {
                continue;
            }
            try {
                $r = $this->client->validate((string) $p['license_key']);
            } catch (LicenseException) {
                break; // Server nicht erreichbar: Zwischenspeicher bleibt
            }
            $this->touchPool($r);
            $n++;
            if (!$r->valid && (int) ($p['valid'] ?? 1) === 1) {
                $this->audit->log('license.pool_invalid', null, ['key' => self::mask((string) $p['license_key']), 'reason' => $r->code]);
            }
        }
        return $n;
    }

    public function removeFromPool(int $id, int $actorId): void
    {
        $row = $this->db->one('SELECT license_key FROM license_pool WHERE id = ?', [$id]);
        if ($row === null) {
            return;
        }
        $this->db->run('DELETE FROM license_pool WHERE id = ?', [$id]);
        $this->audit->log('license.pool_removed', $actorId, ['key' => self::mask((string) $row['license_key'])], $actorId);
    }

    /**
     * Schlüssel für ein neues Konto bestimmen und vorab prüfen. Ist keiner angegeben, wird der Reihe nach
     * jeder hinterlegte Schlüssel versucht, bis einer einen freien Platz hat.
     * @throws LicenseException
     */
    public function resolveKey(string $key): string
    {
        $key = self::normalizeKey($key);
        if ($key !== '') {
            $this->precheck($key);
            return $key;
        }
        $pool = $this->pool();
        if ($pool === []) {
            throw new LicenseException('Es ist keine Lizenz hinterlegt. Bitte wenden Sie sich an den Administrator.', 'NO_KEY');
        }
        $last = null;
        foreach ($pool as $row) {
            try {
                $this->precheck((string) $row['license_key']);
                return (string) $row['license_key'];
            } catch (LicenseException $e) {
                if (!in_array($e->licenseCode, ['DEVICE_LIMIT', 'EXPIRED', 'BLOCKED', 'NOT_FOUND', 'WRONG_PRODUCT'], true)) {
                    throw $e; // Server nicht erreichbar o. Ä.: nicht mit dem nächsten Schlüssel weitermachen
                }
                $last = $e;
            }
        }
        throw count($pool) > 1 ? new LicenseException('Alle hinterlegten Lizenzschlüssel sind voll, abgelaufen oder gesperrt. Bitte wenden Sie sich an den Administrator.', 'DEVICE_LIMIT') : $last;
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

    /** Fragt den Lizenzserver (heartbeat) und aktualisiert den Status. Ungültige Lizenzen werden auf den nächsten Pool-Schlüssel umgestuft. @return bool Server erreicht */
    public function refresh(int $userId): bool
    {
        $row = $this->row($userId);
        if ($row === null) {
            return true;
        }
        try {
            $r = $this->client->heartbeat($row['license_key'], $row['hwid']);
        } catch (LicenseException) {
            return false; // nicht erreichbar: Zwischenspeicher bleibt, Karenzzeit läuft
        }
        $now = Clock::db();
        $upd = ['valid' => $r->valid ? 1 : 0, 'code' => mb_substr($r->code, 0, 32), 'checked_at' => $now];
        if ($r->valid) {
            $upd += ['ok_at' => $now, 'plan' => $r->plan ?: null, 'features' => json_encode($r->features), 'expires_at' => $this->expiry($r), 'max_devices' => $r->maxDevices];
        }
        $this->db->update('user_licenses', $upd, 'user_id', $userId);
        if (!$r->valid && (int) $row['valid'] === 1) {
            $this->audit->log('license.invalidated', $userId, ['reason' => $r->code, 'key' => self::mask($row['license_key'])]);
        }
        if (!$r->valid && in_array($r->code, self::REASSIGN_CODES, true)) {
            try {
                $this->reassign($userId);
            } catch (LicenseException) {
                return false;
            }
        }
        return true;
    }

    /**
     * Stuft einen Nutzer mit ungültiger Lizenz auf den nächsten verfügbaren Pool-Schlüssel um: neuen Platz belegen,
     * den alten freigeben. Wurde nur der Platz im Lizenzpanel zurückgesetzt, wird zuerst derselbe Schlüssel erneut belegt.
     * @return string neuer Schlüssel, '' wenn keiner verfügbar ist
     * @throws LicenseException Lizenzserver nicht erreichbar
     */
    public function reassign(int $userId): string
    {
        $row = $this->row($userId);
        if ($row === null) {
            return '';
        }
        $old = (string) $row['license_key'];
        $hwid = (string) $row['hwid'];
        $candidates = [];
        if ((string) $row['code'] === 'NOT_ACTIVATED') {
            $candidates[] = $old;
        }
        foreach ($this->usablePoolKeys() as $k) {
            if ($k !== $old) {
                $candidates[] = $k;
            }
        }
        foreach (array_unique($candidates) as $key) {
            $r = $this->client->activate($key, $hwid, 'Nutzer ' . $userId); // wirft nur bei Serverausfall
            $this->touchPool($r);
            if (!$r->valid) {
                continue;
            }
            $now = Clock::db();
            $this->db->update('user_licenses', [
                'license_key' => $key, 'valid' => 1, 'code' => 'OK', 'plan' => $r->plan ?: null, 'features' => json_encode($r->features),
                'expires_at' => $this->expiry($r), 'max_devices' => $r->maxDevices, 'activated_at' => $now, 'checked_at' => $now, 'ok_at' => $now,
            ], 'user_id', $userId);
            if ($key !== $old) {
                $this->releaseDevice($old, $hwid);
            }
            $this->audit->log('license.reassigned', $userId, ['from' => self::mask($old), 'to' => self::mask($key), 'reason' => (string) $row['code']]);
            return $key;
        }
        return '';
    }

    /** Pool-Schlüssel, die laut Zwischenspeicher gültig sind und noch Platz haben (unbekannter Stand zählt als möglich). @return string[] */
    private function usablePoolKeys(): array
    {
        $keys = [];
        foreach ($this->pool() as $p) {
            if (isset($p['valid']) && $p['valid'] !== null && (int) $p['valid'] === 0) {
                continue;
            }
            $max = $p['max_devices'] ?? null;
            $dev = $p['devices'] ?? null;
            if ($max !== null && $dev !== null && (int) $dev >= (int) $max) {
                continue;
            }
            $keys[] = (string) $p['license_key'];
        }
        return $keys;
    }

    /**
     * Stuft alle Nutzer mit ungültiger Lizenz um, sofern ein Pool-Schlüssel mit freiem Platz existiert.
     * @return int umgestufte Nutzer
     * @throws LicenseException Lizenzserver nicht erreichbar
     */
    public function reassignInvalid(int $limit = 100): int
    {
        if (!$this->client->configured() || $this->usablePoolKeys() === []) {
            return 0;
        }
        $codes = "'" . implode("','", self::REASSIGN_CODES) . "'";
        $ids = $this->db->all('SELECT user_id FROM user_licenses WHERE valid = 0 AND code IN (' . $codes . ') ORDER BY checked_at LIMIT ' . (int) $limit);
        $n = 0;
        foreach ($ids as $r) {
            if ($this->reassign((int) $r['user_id']) !== '') {
                $n++;
            }
        }
        return $n;
    }

    /** Bei der Anmeldung: veralteten Status des Nutzers auffrischen (kurze Sperre nach Serverausfall, damit Logins nicht warten). */
    public function refreshIfStale(int $userId): void
    {
        $row = $this->row($userId);
        if ($row === null || !$this->client->configured() || $this->limiter->tooMany('license-outage', 1, 300)) {
            return;
        }
        $ttl = max(60, (int) $this->config->get('license.refresh_seconds', 3600));
        $retry = (int) $row['valid'] === 0 ? 300 : $ttl;
        if (Clock::fromDb((string) $row['checked_at']) > Clock::now() - $retry) {
            return;
        }
        if (!$this->refresh($userId)) {
            $this->limiter->hit('license-outage', 1, 300);
        }
    }

    /** Cron: Pool und veraltete Einträge auffrischen (höchstens $limit, bricht bei Serverausfall ab). @return int aufgefrischte Einträge */
    public function refreshStale(int $limit = 100, bool $force = false): int
    {
        if (!$this->client->configured()) {
            return 0;
        }
        $this->refreshPool($force);
        $ttl = max(60, (int) $this->config->get('license.refresh_seconds', 3600));
        $ids = $this->db->all('SELECT user_id FROM user_licenses WHERE checked_at < ? ORDER BY checked_at LIMIT ' . (int) $limit, [Clock::db($force ? Clock::now() + 1 : Clock::now() - $ttl)]);
        $n = 0;
        foreach ($ids as $r) {
            if (!$this->refresh((int) $r['user_id'])) {
                return $n;
            }
            $n++;
        }
        try {
            $this->reassignInvalid($limit);
        } catch (LicenseException) {
            // Server nicht erreichbar – nächster Lauf
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

    /** Nutzer, deren Lizenz laut letzter Prüfung ungültig ist (und noch auf keinen anderen Schlüssel umgestuft werden konnte). */
    public function invalidUsers(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM user_licenses WHERE valid = 0');
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
