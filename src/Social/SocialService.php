<?php
declare(strict_types=1);

namespace Vylaris\Social;

use Vylaris\Core\{Clock, Config, Database, Session};
use Vylaris\Oidc\Jwt;
use Vylaris\Repository\UserRepository;
use Vylaris\Security\{Redirect, SecretBox};
use Vylaris\Service\{AuditLogger, PasswordService};

/**
 * Social Login: VylarisAuth tritt hier als OAuth2-/OIDC-Client gegenüber externen Anbietern auf
 * (Authorization Code Flow + PKCE S256 + state; bei OIDC zusätzlich nonce und ID-Token-Prüfung).
 *
 * Konto-Regeln (gegen Account-Takeover):
 *  - Anmeldung nur über eine bereits verknüpfte Identität (Anbieter + unveränderliches "sub").
 *  - E-Mail-Adressen führen NIE automatisch zu einer Verknüpfung mit einem bestehenden Konto.
 *  - Neue Konten nur, wenn der Anbieter die E-Mail-Adresse als verifiziert meldet.
 *  - Verknüpfen/Trennen nur im angemeldeten Zustand (CSRF-geschützt, mit Passwortbestätigung).
 */
final class SocialService
{
    public const TYPES = [
        'google' => ['label' => 'Google', 'icon' => 'bxl-google', 'kind' => 'oidc', 'setup' => 'https://console.cloud.google.com/apis/credentials'],
        'microsoft' => ['label' => 'Microsoft', 'icon' => 'bxl-microsoft', 'kind' => 'oidc', 'setup' => 'https://entra.microsoft.com'],
        'github' => ['label' => 'GitHub', 'icon' => 'bxl-github', 'kind' => 'oauth2', 'setup' => 'https://github.com/settings/developers'],
        'gitlab' => ['label' => 'GitLab', 'icon' => 'bxl-gitlab', 'kind' => 'oidc', 'setup' => 'https://gitlab.com/-/user_settings/applications'],
        'discord' => ['label' => 'Discord', 'icon' => 'bxl-discord-alt', 'kind' => 'oauth2', 'setup' => 'https://discord.com/developers/applications'],
        'oidc' => ['label' => 'OpenID Connect', 'icon' => 'bx-id-card', 'kind' => 'oidc', 'setup' => ''],
    ];

    private const FLOW_TTL = 600;
    private const CACHE_TTL = 3600;
    private const MS_PERSONAL_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private SecretBox $box;

    public function __construct(
        private Database $db,
        private Config $config,
        private HttpClient $http,
        private UserRepository $users,
        private PasswordService $passwords,
        private AuditLogger $audit,
    ) {
        $this->box = new SecretBox($config->secretKey(), 'vylaris/social-client-secret');
    }

    // ------------------------------------------------------------------ Anbieter-Verwaltung

    public function redirectUri(string $key): string
    {
        return $this->config->baseUrl() . '/social/' . $key . '/callback';
    }

    /** @return array<int,array> Anbieter ohne Client-Secret */
    public function providers(bool $onlyEnabled = false): array
    {
        $rows = $this->db->all('SELECT * FROM social_providers ' . ($onlyEnabled ? 'WHERE enabled = 1 ' : '') . 'ORDER BY label, provider_key');
        return array_map(static function (array $r): array {
            unset($r['client_secret_enc']);
            return $r + ['icon' => self::TYPES[$r['type']]['icon'] ?? 'bx-log-in-circle'];
        }, $rows);
    }

    public function provider(string $key): ?array
    {
        return $this->db->one('SELECT * FROM social_providers WHERE provider_key = ?', [$key]);
    }

    private function enabled(string $key): ?array
    {
        $p = $this->provider($key);
        return $p && (int) $p['enabled'] === 1 ? $p : null;
    }

    /**
     * Legt einen Anbieter an oder aktualisiert ihn. Leeres client_secret beim Bearbeiten behält das bisherige.
     * @throws \InvalidArgumentException bei Validierungsfehlern (Meldung ist anzeigbar)
     */
    public function saveProvider(int $actorId, array $in, ?string $existingKey = null): string
    {
        $existing = $existingKey !== null ? $this->provider($existingKey) : null;
        if ($existingKey !== null && $existing === null) {
            throw new \InvalidArgumentException('Anbieter nicht gefunden.');
        }
        $type = $existing['type'] ?? (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unbekannter Anbietertyp.');
        }
        $key = $existing['provider_key'] ?? strtolower(trim((string) ($in['provider_key'] ?? '')));
        if ($key === '') {
            $key = $type;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $key)) {
            throw new \InvalidArgumentException('Der Schlüssel darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (2–40 Zeichen).');
        }
        if ($existing === null && $this->provider($key) !== null) {
            throw new \InvalidArgumentException('Dieser Schlüssel ist bereits vergeben.');
        }
        $label = trim(preg_replace('/\s+/u', ' ', (string) ($in['label'] ?? '')) ?? '');
        if ($label === '') {
            $label = self::TYPES[$type]['label'];
        }
        if (mb_strlen($label) > 80) {
            throw new \InvalidArgumentException('Die Bezeichnung ist zu lang (max. 80 Zeichen).');
        }
        $clientId = trim((string) ($in['client_id'] ?? ''));
        if ($clientId === '' || strlen($clientId) > 255 || preg_match('/[\x00-\x20\x7F]/', $clientId)) {
            throw new \InvalidArgumentException('Client-ID fehlt oder ist ungültig.');
        }
        $secret = (string) ($in['client_secret'] ?? '');
        if ($secret === '' && $existing === null) {
            throw new \InvalidArgumentException('Das Client-Secret fehlt.');
        }
        if (strlen($secret) > 4096) {
            throw new \InvalidArgumentException('Das Client-Secret ist zu lang.');
        }

        $issuer = trim((string) ($in['issuer'] ?? ''));
        if ($type === 'microsoft') {
            $issuer = $issuer === '' ? 'common' : $issuer;
            if (!preg_match('/^(common|organizations|consumers|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $issuer)) {
                throw new \InvalidArgumentException('Mandant: common, organizations, consumers oder eine Verzeichnis-ID (GUID).');
            }
            $issuer = strtolower($issuer);
        } elseif ($type === 'gitlab' || $type === 'oidc') {
            $issuer = rtrim($issuer === '' && $type === 'gitlab' ? 'https://gitlab.com' : $issuer, '/');
            $this->assertPublicHttpsUrl($issuer);
        } else {
            $issuer = '';
        }

        $now = Clock::db();
        $data = [
            'type' => $type, 'label' => $label, 'client_id' => $clientId, 'issuer' => $issuer === '' ? null : $issuer,
            'enabled' => !empty($in['enabled']) ? 1 : 0, 'allow_signup' => !empty($in['allow_signup']) ? 1 : 0, 'updated_at' => $now,
        ];
        if ($secret !== '') {
            $data['client_secret_enc'] = $this->box->encrypt($secret);
        }
        if ($existing === null) {
            $this->db->insert('social_providers', $data + ['provider_key' => $key, 'created_at' => $now]);
        } else {
            $this->db->update('social_providers', $data, 'provider_key', $key);
            $this->cacheDrop($key);
        }
        $this->audit->log($existing === null ? 'social.provider_created' : 'social.provider_updated', null,
            ['provider' => $key, 'enabled' => $data['enabled'], 'credentials_rotated' => $secret !== ''], $actorId);
        return $key;
    }

    public function setEnabled(int $actorId, string $key, bool $on): void
    {
        if ($this->provider($key) === null) {
            throw new \InvalidArgumentException('Anbieter nicht gefunden.');
        }
        $this->db->update('social_providers', ['enabled' => $on ? 1 : 0, 'updated_at' => Clock::db()], 'provider_key', $key);
        $this->audit->log('social.provider_' . ($on ? 'enabled' : 'disabled'), null, ['provider' => $key], $actorId);
    }

    /** Löscht den Anbieter samt Verknüpfungen. Konten ohne anderes Anmeldeverfahren bleiben per Passwort-Reset erreichbar. */
    public function deleteProvider(int $actorId, string $key): void
    {
        if ($this->provider($key) === null) {
            throw new \InvalidArgumentException('Anbieter nicht gefunden.');
        }
        $n = (int) $this->db->scalar('SELECT COUNT(*) FROM social_identities WHERE provider_key = ?', [$key]);
        $this->db->run('DELETE FROM social_identities WHERE provider_key = ?', [$key]);
        $this->db->run('DELETE FROM social_providers WHERE provider_key = ?', [$key]);
        $this->cacheDrop($key);
        $this->audit->log('social.provider_deleted', null, ['provider' => $key, 'identities_removed' => $n], $actorId);
    }

    public function identityCount(string $key): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM social_identities WHERE provider_key = ?', [$key]);
    }

    // ------------------------------------------------------------------ Verknüpfungen eines Kontos

    /** @return array<int,array> */
    public function identitiesFor(int $userId): array
    {
        return $this->db->all(
            'SELECT i.provider_key, i.email, i.display_name, i.created_at, i.last_login_at, p.label, p.type
               FROM social_identities i LEFT JOIN social_providers p ON p.provider_key = i.provider_key
              WHERE i.user_id = ? ORDER BY i.created_at',
            [$userId]
        );
    }

    /** Anzahl unabhängiger Anmeldeverfahren (Passwort, Passkeys, verknüpfte Anbieter). */
    public function loginMethods(int $userId): int
    {
        $user = $this->users->findById($userId);
        $n = ($user && (int) $user['password_set'] === 1) ? 1 : 0;
        $n += (int) $this->db->scalar('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$userId]);
        $n += (int) $this->db->scalar('SELECT COUNT(*) FROM social_identities WHERE user_id = ?', [$userId]);
        return $n;
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public function unlink(int $userId, string $key): ?string
    {
        $has = $this->db->scalar('SELECT 1 FROM social_identities WHERE user_id = ? AND provider_key = ?', [$userId, $key]);
        if ($has === null) {
            return 'Diese Verknüpfung besteht nicht.';
        }
        if ($this->loginMethods($userId) <= 1) {
            return 'Das ist Ihre einzige Anmeldemöglichkeit. Legen Sie zuerst ein Passwort fest (über „Passwort vergessen“) oder richten Sie einen Passkey ein.';
        }
        $this->db->run('DELETE FROM social_identities WHERE user_id = ? AND provider_key = ?', [$userId, $key]);
        $this->audit->log('social.unlinked', $userId, ['provider' => $key]);
        return null;
    }

    // ------------------------------------------------------------------ Ablauf: Start

    /**
     * Startet den Flow und liefert die Autorisierungs-URL des Anbieters.
     * @param 'login'|'link' $mode
     * @throws SocialException
     */
    public function begin(Session $session, string $key, string $mode, ?int $linkUserId, string $next): string
    {
        $p = $this->enabled($key) ?? throw new SocialException('provider_unavailable');
        $kind = self::TYPES[$p['type']]['kind'];
        $ep = $this->endpoints($p);

        $state = Jwt::b64u(random_bytes(32));
        $nonce = Jwt::b64u(random_bytes(32));
        $verifier = Jwt::b64u(random_bytes(48));
        $session->set('social_flow', [
            'key' => $key, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'mode' => $mode === 'link' ? 'link' : 'login', 'uid' => $linkUserId,
            'next' => Redirect::safeLocal($next, ''), 'at' => Clock::now(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $p['client_id'],
            'redirect_uri' => $this->redirectUri($key),
            'scope' => $ep['scope'],
            'state' => $state,
            'code_challenge' => Jwt::b64u(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if ($kind === 'oidc') {
            $params['nonce'] = $nonce;
        }
        if ($p['type'] === 'google') {
            $params['prompt'] = 'select_account';
        }
        return $ep['auth'] . (str_contains($ep['auth'], '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    // ------------------------------------------------------------------ Ablauf: Callback

    /** Verarbeitet die Rückkehr vom Anbieter. Der Flow-Zustand ist einmalig verwendbar. */
    public function complete(Session $session, string $key, array $query, ?int $currentUserId): SocialResult
    {
        $flow = $session->get('social_flow');
        $session->forget('social_flow');
        $mode = is_array($flow) && ($flow['mode'] ?? '') === 'link' ? 'link' : 'login';

        try {
            if (!is_array($flow) || ($flow['key'] ?? null) !== $key || Clock::now() - (int) ($flow['at'] ?? 0) > self::FLOW_TTL) {
                throw new SocialException('flow_missing_or_expired');
            }
            $state = $query['state'] ?? null;
            if (!is_string($state) || !hash_equals((string) $flow['state'], $state)) {
                throw new SocialException('state_mismatch');
            }
            if (isset($query['error'])) {
                $code = is_string($query['error']) && preg_match('/^[a-z_]{1,40}$/', $query['error']) ? $query['error'] : 'unknown';
                throw new SocialException('provider_error:' . $code, 1);
            }
            $code = $query['code'] ?? null;
            if (!is_string($code) || $code === '' || strlen($code) > 2048) {
                throw new SocialException('code_missing');
            }
            $p = $this->enabled($key) ?? throw new SocialException('provider_unavailable');
            $identity = $this->fetchIdentity($p, $code, $flow);
            return $mode === 'link'
                ? $this->finishLink($p, $identity, (int) ($flow['uid'] ?? 0), $currentUserId)
                : $this->finishLogin($p, $identity, (string) ($flow['next'] ?? ''));
        } catch (SocialException $e) {
            $cancelled = $e->getCode() === 1;
            $this->audit->log('social.failed', $currentUserId, ['provider' => $key, 'reason' => $e->getMessage(), 'mode' => $mode]);
            return SocialResult::error($cancelled
                ? 'Die Anmeldung wurde abgebrochen oder vom Anbieter abgelehnt.'
                : 'Die Anmeldung über den externen Anbieter ist fehlgeschlagen. Bitte versuchen Sie es erneut.', $mode);
        }
    }

    private function finishLogin(array $p, SocialIdentity $id, string $next): SocialResult
    {
        $key = $p['provider_key'];
        $row = $this->db->one('SELECT * FROM social_identities WHERE provider_key = ? AND subject = ?', [$key, $id->subject]);

        if ($row !== null) {
            $user = $this->users->findById((int) $row['user_id']);
            if ($user === null || $this->users->isLocked($user)) {
                $this->audit->log('social.login_blocked', $user ? (int) $user['id'] : null, ['provider' => $key]);
                return SocialResult::error('Dieses Konto ist gesperrt. Bitte wenden Sie sich an den Support.');
            }
            $uid = (int) $user['id'];
            if ($user['email_verified_at'] === null) {
                // Nur freigeben, wenn der Anbieter genau diese Adresse als verifiziert bestätigt.
                if ($id->emailVerified && $id->email !== null && UserRepository::normalizeEmail($id->email) === $user['email']) {
                    $this->users->markVerified($uid);
                } elseif ($this->config->get('security.require_email_verification', true)) {
                    return SocialResult::error('Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse. Den Link finden Sie in Ihrem Postfach.');
                }
            }
            $this->db->update('social_identities', ['last_login_at' => Clock::db(), 'email' => $this->cut($id->email, 255)], 'id', (int) $row['id']);
            $this->users->recordLoginSuccess($uid);
            return new SocialResult('login', $uid, $next);
        }

        // Unbekannte Identität -> evtl. Registrierung. Mit Lizenzpflicht entstehen Konten nur über die Registrierung
        // mit Lizenzschlüssel (bzw. durch den Admin); der Anbieter lässt sich danach im Konto verknüpfen.
        if ($this->config->get('license.required', true)) {
            $this->audit->log('social.signup_refused', null, ['provider' => $key, 'reason' => 'license_required']);
            return SocialResult::error('Neue Konten können nur mit einem Lizenzschlüssel über die Registrierung angelegt werden. Registrieren Sie sich zuerst mit E-Mail und Passwort und verknüpfen Sie den Anbieter danach unter „Konto → Verknüpfte Anmeldungen“.');
        }
        if (!$this->config->get('security.allow_registration', true) || (int) $p['allow_signup'] !== 1) {
            return SocialResult::error('Mit diesem Konto des Anbieters ist noch kein Zugang verknüpft. Melden Sie sich normal an und verknüpfen Sie den Anbieter unter „Konto → Verknüpfte Anmeldungen“.');
        }
        $email = $id->email !== null ? UserRepository::normalizeEmail($id->email) : '';
        if (!$id->emailVerified || $email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->audit->log('social.signup_refused', null, ['provider' => $key, 'reason' => 'email_not_verified']);
            return SocialResult::error('Der Anbieter hat keine bestätigte E-Mail-Adresse geliefert. Registrieren Sie sich bitte mit E-Mail und Passwort und verknüpfen Sie den Anbieter danach im Konto.');
        }
        if ($this->users->findByEmail($email) !== null) {
            // Bewusst KEINE automatische Verknüpfung (Schutz vor Account-Übernahme).
            $this->audit->log('social.signup_refused', null, ['provider' => $key, 'reason' => 'email_exists']);
            return SocialResult::error('Zu dieser E-Mail-Adresse existiert bereits ein Konto. Melden Sie sich mit Ihrem Passwort an und verknüpfen Sie den Anbieter unter „Konto → Verknüpfte Anmeldungen“.');
        }

        try {
            $uid = $this->db->transaction(function () use ($email, $id, $key): int {
                $uid = $this->users->create($email, $this->passwords->hash(bin2hex(random_bytes(32))), $this->displayName($id->name, $email), true);
                $this->db->update('users', ['password_set' => 0], 'id', $uid);
                $this->insertIdentity($uid, $key, $id);
                return $uid;
            });
        } catch (\PDOException) {
            throw new SocialException('signup_conflict');
        }
        $this->audit->log('social.signup', $uid, ['provider' => $key]);
        return new SocialResult('login', $uid, $next);
    }

    private function finishLink(array $p, SocialIdentity $id, int $flowUid, ?int $currentUid): SocialResult
    {
        if ($currentUid === null || $flowUid !== $currentUid) {
            throw new SocialException('link_session_mismatch');
        }
        $key = $p['provider_key'];
        $row = $this->db->one('SELECT user_id FROM social_identities WHERE provider_key = ? AND subject = ?', [$key, $id->subject]);
        if ($row !== null) {
            if ((int) $row['user_id'] === $currentUid) {
                return new SocialResult('linked', $currentUid, '', 'Dieser Anbieter ist bereits mit Ihrem Konto verknüpft.', 'link');
            }
            $this->audit->log('social.link_refused', $currentUid, ['provider' => $key, 'reason' => 'bound_to_other_account']);
            return SocialResult::error('Diese Identität ist bereits mit einem anderen Konto verknüpft.', 'link');
        }
        // Pro Anbieter nur eine Identität je Konto – verhindert unübersichtliche Mehrfachverknüpfung.
        if ($this->db->scalar('SELECT 1 FROM social_identities WHERE user_id = ? AND provider_key = ?', [$currentUid, $key]) !== null) {
            return SocialResult::error('Mit diesem Anbieter ist bereits eine andere Identität verknüpft. Trennen Sie diese zuerst.', 'link');
        }
        try {
            $this->insertIdentity($currentUid, $key, $id);
        } catch (\PDOException) {
            throw new SocialException('link_conflict');
        }
        $this->audit->log('social.linked', $currentUid, ['provider' => $key]);
        return new SocialResult('linked', $currentUid, '', '', 'link');
    }

    private function insertIdentity(int $uid, string $key, SocialIdentity $id): void
    {
        $this->db->insert('social_identities', [
            'user_id' => $uid, 'provider_key' => $key, 'subject' => $id->subject,
            'email' => $this->cut($id->email, 255), 'display_name' => $this->cut($id->name, 120),
            'created_at' => Clock::db(), 'last_login_at' => Clock::db(),
        ]);
    }

    private function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    private function displayName(?string $name, string $email): string
    {
        $n = trim(preg_replace('/[\s\p{C}]+/u', ' ', (string) $name) ?? '');
        if (mb_strlen($n) < 2) {
            $n = (string) strstr($email, '@', true);
        }
        if (mb_strlen($n) < 2) {
            $n = 'Benutzer';
        }
        return mb_substr($n, 0, 120);
    }

    // ------------------------------------------------------------------ Identität vom Anbieter holen

    private function fetchIdentity(array $p, string $code, array $flow): SocialIdentity
    {
        $ep = $this->endpoints($p);
        $secret = $this->box->decrypt($p['client_secret_enc']);
        $tokens = $this->exchangeCode($p, $ep, $secret, $code, (string) $flow['verifier']);

        return match ($p['type']) {
            'github' => $this->githubIdentity((string) ($tokens['access_token'] ?? '')),
            'discord' => $this->discordIdentity((string) ($tokens['access_token'] ?? '')),
            default => $this->oidcIdentity($p, $ep, (string) ($tokens['id_token'] ?? ''), (string) $flow['nonce']),
        };
    }

    private function exchangeCode(array $p, array $ep, string $secret, string $code, string $verifier): array
    {
        $fields = [
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $this->redirectUri($p['provider_key']), 'code_verifier' => $verifier,
        ];
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        if (($ep['token_auth'] ?? 'post') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($p['client_id']) . ':' . rawurlencode($secret));
        } else {
            $fields['client_id'] = $p['client_id'];
            $fields['client_secret'] = $secret;
        }
        $res = $this->call('POST', $ep['token'], $headers, http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
        $data = $res->json();
        if (!$res->ok() || $data === null || isset($data['error'])) {
            // Fehlercode des Anbieters (kurz, ohne Secrets) ins Audit-Log.
            $err = is_string($data['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $data['error']) ? $data['error'] : 'http_' . $res->status;
            throw new SocialException('token_exchange_failed:' . $err);
        }
        return $data;
    }

    private function oidcIdentity(array $p, array $ep, string $idToken, string $nonce): SocialIdentity
    {
        if ($idToken === '') {
            throw new SocialException('id_token_missing');
        }
        $issuerOk = $this->issuerCheck($p, $ep);
        try {
            $claims = IdTokenVerifier::verify($idToken, $this->jwks($p, $ep, false), $issuerOk, $p['client_id'], $nonce);
        } catch (SocialException $e) {
            if ($e->getMessage() !== 'id_token_signature') {
                throw $e;
            }
            // Möglicherweise Schlüsselrotation beim Anbieter: einmal frisch laden.
            $claims = IdTokenVerifier::verify($idToken, $this->jwks($p, $ep, true), $issuerOk, $p['client_id'], $nonce);
        }

        $email = is_string($claims['email'] ?? null) ? $claims['email'] : null;
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        if ($p['type'] === 'microsoft') {
            // Microsofts "email"-Claim ist veränderbar/nicht verifiziert (nOAuth) -> nie vertrauen.
            $verified = false;
            $email ??= is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : null;
        }
        $name = is_string($claims['name'] ?? null) ? $claims['name'] : null;
        // Mehrmandanten-Anbieter: "sub" ist nur je Mandant eindeutig -> Mandant ins Subject aufnehmen.
        $subject = $p['type'] === 'microsoft' ? (string) ($claims['tid'] ?? '') . ':' . $claims['sub'] : $claims['sub'];
        return new SocialIdentity($subject, $email, $verified, $name);
    }

    private function issuerCheck(array $p, array $ep): callable
    {
        if ($p['type'] === 'microsoft') {
            $tenant = (string) $p['issuer'];
            return static function (array $c) use ($tenant): bool {
                $tid = $c['tid'] ?? null;
                if (!is_string($tid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tid)) {
                    return false;
                }
                if (preg_match('/^[0-9a-f]{8}-/i', $tenant) && strtolower($tid) !== $tenant) {
                    return false;
                }
                if ($tenant === 'consumers' && strtolower($tid) !== self::MS_PERSONAL_TENANT) {
                    return false;
                }
                return ($c['iss'] ?? null) === 'https://login.microsoftonline.com/' . $tid . '/v2.0';
            };
        }
        $expected = $ep['issuer'];
        $alt = $p['type'] === 'google' ? ['accounts.google.com'] : [];
        return static fn (array $c): bool => ($c['iss'] ?? null) === $expected || in_array($c['iss'] ?? null, $alt, true);
    }

    private function githubIdentity(string $token): SocialIdentity
    {
        if ($token === '') {
            throw new SocialException('access_token_missing');
        }
        $h = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        $u = $this->call('GET', 'https://api.github.com/user', $h)->json();
        if (!is_array($u) || !isset($u['id']) || !is_int($u['id'])) {
            throw new SocialException('github_user_invalid');
        }
        $email = null;
        $verified = false;
        $res = $this->call('GET', 'https://api.github.com/user/emails', $h);
        foreach ($res->ok() ? ($res->json() ?? []) : [] as $e) {
            if (is_array($e) && ($e['primary'] ?? false) === true && ($e['verified'] ?? false) === true && is_string($e['email'] ?? null)) {
                $email = $e['email'];
                $verified = true;
                break;
            }
        }
        $name = is_string($u['name'] ?? null) && $u['name'] !== '' ? $u['name'] : (is_string($u['login'] ?? null) ? $u['login'] : null);
        return new SocialIdentity((string) $u['id'], $email, $verified, $name);
    }

    private function discordIdentity(string $token): SocialIdentity
    {
        if ($token === '') {
            throw new SocialException('access_token_missing');
        }
        $u = $this->call('GET', 'https://discord.com/api/users/@me', ['Authorization: Bearer ' . $token])->json();
        if (!is_array($u) || !is_string($u['id'] ?? null) || !preg_match('/^\d{1,30}$/', $u['id'])) {
            throw new SocialException('discord_user_invalid');
        }
        $name = is_string($u['global_name'] ?? null) && $u['global_name'] !== '' ? $u['global_name'] : (is_string($u['username'] ?? null) ? $u['username'] : null);
        return new SocialIdentity($u['id'], is_string($u['email'] ?? null) ? $u['email'] : null, ($u['verified'] ?? false) === true, $name);
    }

    private function call(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        try {
            return $this->http->request($method, $url, $headers, $body);
        } catch (\RuntimeException) {
            throw new SocialException('http_failed');
        }
    }

    // ------------------------------------------------------------------ Endpunkte, Discovery, JWKS

    /** @return array{auth:string,token:string,jwks?:string,issuer?:string,scope:string,token_auth?:string} */
    private function endpoints(array $p): array
    {
        switch ($p['type']) {
            case 'google':
                return ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
                    'jwks' => 'https://www.googleapis.com/oauth2/v3/certs', 'issuer' => 'https://accounts.google.com', 'scope' => 'openid email profile'];
            case 'microsoft':
                $base = 'https://login.microsoftonline.com/' . rawurlencode((string) ($p['issuer'] ?: 'common'));
                return ['auth' => $base . '/oauth2/v2.0/authorize', 'token' => $base . '/oauth2/v2.0/token',
                    'jwks' => $base . '/discovery/v2.0/keys', 'issuer' => '', 'scope' => 'openid email profile'];
            case 'github':
                return ['auth' => 'https://github.com/login/oauth/authorize', 'token' => 'https://github.com/login/oauth/access_token', 'scope' => 'read:user user:email'];
            case 'discord':
                return ['auth' => 'https://discord.com/oauth2/authorize', 'token' => 'https://discord.com/api/oauth2/token', 'scope' => 'identify email'];
            default:
                return $this->discover($p);
        }
    }

    private function discover(array $p): array
    {
        $issuer = rtrim((string) $p['issuer'], '/');
        $cached = $this->cacheGet('disc', $p['provider_key']);
        if ($cached === null) {
            $this->assertPublicHttpsUrl($issuer, true);
            $res = $this->call('GET', $issuer . '/.well-known/openid-configuration', ['Accept: application/json']);
            $doc = $res->ok() ? $res->json() : null;
            if (!is_array($doc) || rtrim((string) ($doc['issuer'] ?? ''), '/') !== $issuer) {
                throw new SocialException('discovery_invalid');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $f) {
                if (!is_string($doc[$f] ?? null)) {
                    throw new SocialException('discovery_incomplete');
                }
                $this->assertPublicHttpsUrl($doc[$f], true);
            }
            $methods = (array) ($doc['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
            $cached = [
                'auth' => $doc['authorization_endpoint'], 'token' => $doc['token_endpoint'], 'jwks' => $doc['jwks_uri'],
                'issuer' => (string) $doc['issuer'], 'scope' => 'openid email profile',
                'token_auth' => in_array('client_secret_post', $methods, true) ? 'post' : 'basic',
            ];
            $this->cachePut('disc', $p['provider_key'], $cached);
        }
        return $cached;
    }

    /** @return array<int,array> */
    private function jwks(array $p, array $ep, bool $force): array
    {
        $cached = $force ? null : $this->cacheGet('jwks', $p['provider_key']);
        if ($cached === null) {
            $res = $this->call('GET', $ep['jwks'], ['Accept: application/json']);
            $doc = $res->ok() ? $res->json() : null;
            if (!is_array($doc) || !is_array($doc['keys'] ?? null)) {
                throw new SocialException('jwks_invalid');
            }
            $cached = array_values(array_filter($doc['keys'], 'is_array'));
            $this->cachePut('jwks', $p['provider_key'], $cached);
        }
        return $cached;
    }

    private function cacheGet(string $kind, string $key): ?array
    {
        $v = $this->db->scalar('SELECT v FROM settings WHERE k = ?', ['social.' . $kind . '.' . $key]);
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) && Clock::now() - (int) ($d['t'] ?? 0) < self::CACHE_TTL && is_array($d['d'] ?? null) ? $d['d'] : null;
    }

    private function cachePut(string $kind, string $key, array $data): void
    {
        $k = 'social.' . $kind . '.' . $key;
        $this->db->run('DELETE FROM settings WHERE k = ?', [$k]);
        $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, json_encode(['t' => Clock::now(), 'd' => $data])]);
    }

    private function cacheDrop(string $key): void
    {
        $this->db->run('DELETE FROM settings WHERE k IN (?, ?)', ['social.disc.' . $key, 'social.jwks.' . $key]);
    }

    /**
     * Nur öffentliche HTTPS-Ziele ohne Zugangsdaten/Sonderports (SSRF-Schutz für admin-konfigurierte URLs).
     * Die DNS-Auflösung wird zusätzlich im CurlHttpClient geprüft.
     * @throws \InvalidArgumentException|SocialException
     */
    private function assertPublicHttpsUrl(string $url, bool $runtime = false): void
    {
        $fail = static fn (string $m) => $runtime ? throw new SocialException('url_rejected') : throw new \InvalidArgumentException($m);
        $u = parse_url($url);
        if ($u === false || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])
            || (isset($u['port']) && $u['port'] !== 443) || strlen($url) > 255) {
            $fail('Die URL muss mit https:// beginnen, einen Host enthalten und darf weder Zugangsdaten noch einen Sonderport enthalten.');
        }
        $host = strtolower(trim($u['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            $fail('Interne oder private Adressen sind nicht erlaubt.');
        }
    }
}
