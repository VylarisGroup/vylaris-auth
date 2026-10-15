<?php
declare(strict_types=1);

namespace Vylaris\Core;

/**
 * Dünner Wrapper um $_SESSION. In Tests wird ein eigenes Array übergeben,
 * im Betrieb startet bootstrap() die native PHP-Session mit DB-Handler.
 */
final class Session
{
    private array $data;
    private ?DbSessionHandler $handler = null;
    private bool $native = false;
    private string $idValue;

    public function __construct(array $data = [], string $id = '')
    {
        $this->data = $data;
        $this->idValue = $id !== '' ? $id : bin2hex(random_bytes(32));
    }

    public static function bootstrap(Config $config, Database $db, Request $request): self
    {
        $handler = new DbSessionHandler(
            $db,
            (int) $config->get('session.idle_timeout', 1800),
            (int) $config->get('session.absolute_ttl', 86400),
            $request->ip(),
            $request->userAgent()
        );
        session_set_save_handler($handler, true);
        $secure = $request->isSecure();
        session_name((string) $config->get('session.cookie_name', '__Host-vsid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        session_start();

        $s = new self([], session_id());
        $s->data = &$_SESSION;
        $s->handler = $handler;
        $s->native = true;
        return $s;
    }

    public function id(): string
    {
        return $this->native ? session_id() : $this->idValue;
    }

    public function idHash(): string
    {
        return hash('sha256', $this->id());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Einmalig lesbare Nachricht (Flash). */
    public function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $this->data['_flash'][$key] = $value;
            return null;
        }
        $v = $this->data['_flash'][$key] ?? null;
        unset($this->data['_flash'][$key]);
        return $v;
    }

    /** Neue Session-ID (gegen Session Fixation); alte Sitzung wird gelöscht. */
    public function regenerate(): void
    {
        if ($this->native) {
            session_regenerate_id(true);
        } else {
            $this->idValue = bin2hex(random_bytes(32));
        }
    }

    /** Bindet die Sitzung an einen Benutzer (wird beim Schreiben in die DB übernommen). */
    public function bindUser(?int $userId): void
    {
        $this->handler?->setUserId($userId);
        $this->data['uid'] = $userId;
    }

    public function userId(): ?int
    {
        $v = $this->data['uid'] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function destroy(): void
    {
        $this->data = [];
        if ($this->native) {
            $this->handler?->setUserId(null);
            session_destroy();
            if (!headers_sent()) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
        }
    }

    public function all(): array
    {
        return $this->data;
    }
}
