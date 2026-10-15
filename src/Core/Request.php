<?php
declare(strict_types=1);

namespace Vylaris\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        private array $trustedProxies = [],
        /** @var array<string,array{name:string,size:int,bytes:string}> hochgeladene Dateien (Inhalt bereits gelesen, max. 1 MiB) */
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(array $trustedProxies = []): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = mb_scrub(rawurldecode((string) parse_url($uri, PHP_URL_PATH)));
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            self::scrub($_GET),
            self::scrub($_POST),
            self::scrub($_SERVER),
            $_COOKIE,
            (string) file_get_contents('php://input'),
            $trustedProxies,
            self::uploads($_FILES)
        );
    }

    /** Liest ausschließlich echte HTTP-Uploads (is_uploaded_file), begrenzt auf 1 MiB. */
    private static function uploads(array $files): array
    {
        $out = [];
        foreach ($files as $field => $f) {
            if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                continue;
            }
            $size = (int) ($f['size'] ?? 0);
            if ($size <= 0 || $size > 1048576) {
                $out[$field] = ['name' => '', 'size' => $size, 'bytes' => ''];
                continue;
            }
            $out[$field] = ['name' => mb_substr(basename((string) ($f['name'] ?? '')), 0, 120), 'size' => $size, 'bytes' => (string) file_get_contents($f['tmp_name'])];
        }
        return $out;
    }

    /** Ersetzt ungültige UTF-8-Folgen (MySQL im Strict-Mode würde sie beim Speichern ablehnen → 500). */
    private static function scrub(mixed $v): mixed
    {
        if (is_string($v)) {
            return mb_scrub($v);
        }
        return is_array($v) ? array_map([self::class, 'scrub'], $v) : $v;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_string($v) ? $v : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization') ?? ($this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (is_string($h) && preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public function isSecure(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->trustedProxies && in_array($remote, $this->trustedProxies, true)) {
            $fwd = $this->header('X-Forwarded-For');
            if ($fwd) {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function json(): ?array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : null;
    }
}
