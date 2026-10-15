<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Core\{App, DbSessionHandler, Request, Response, Session};

/** Simuliert einen Browser mit Cookie-Session gegen die App (inkl. DB-Sessionpersistenz). */
final class TestBrowser
{
    private Session $session;
    private DbSessionHandler $handler;
    private bool $written = false;
    public ?Response $last = null;
    /** Cookies, die jeder Request mitsendet (z. B. vtheme). */
    public array $cookies = [];
    /** Dateien für den nächsten Request (Feld => ['name','size','bytes']). */
    public array $files = [];

    public function __construct(private App $app, private string $ip = '203.0.113.7', private string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0')
    {
        $this->fresh();
    }

    private function fresh(): void
    {
        $this->session = new Session([], bin2hex(random_bytes(24)));
        $this->handler = new DbSessionHandler($this->app->db, 1800, 86400, $this->ip, $this->ua);
        $this->written = false;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function csrf(): string
    {
        return \Vylaris\Security\Csrf::token($this->session);
    }

    public function get(string $path, array $query = [], array $server = []): Response
    {
        return $this->send('GET', $path, $query, [], $server);
    }

    public function post(string $path, array $data = [], bool $withCsrf = true, array $server = []): Response
    {
        if ($withCsrf) {
            $data['_csrf'] = $this->csrf();
        }
        return $this->send('POST', $path, [], $data, $server);
    }

    /** JSON-POST mit CSRF-Header (wie passkey.js). Gibt dekodierte Antwort + Statuscode zurück. */
    public function postJson(string $path, array $data = [], bool $withCsrf = true): Response
    {
        $server = $withCsrf ? ['HTTP_X_CSRF_TOKEN' => $this->csrf()] : [];
        return $this->send('POST', $path, [], [], $server, json_encode($data));
    }

    public function json(): array
    {
        return json_decode($this->last->body, true) ?? [];
    }

    private function send(string $method, string $path, array $query, array $post, array $server, string $body = ''): Response
    {
        // Serverseitig widerrufene/abgelaufene Sitzung: PHP würde eine neue, leere Sitzung ausgeben.
        if ($this->written && $this->handler->read($this->session->id()) === '') {
            $this->fresh();
            if (isset($post['_csrf'])) {
                $post['_csrf'] = $this->csrf();
            }
        }
        $before = $this->session->id();
        $req = new Request($method, $path, $query, $post, $server + ['REMOTE_ADDR' => $this->ip, 'HTTPS' => 'on', 'HTTP_USER_AGENT' => $this->ua], $this->cookies, $body, [], $this->files);
        $this->files = [];
        $res = $this->app->handle($req, $this->session);

        if ($this->session->id() !== $before) {
            $this->handler->destroy($before);
        }
        // Schreiben wie am Request-Ende (nur wenn nicht zerstört)
        if ($this->session->all() !== []) {
            $this->handler->setUserId($this->session->userId());
            $this->handler->write($this->session->id(), serialize($this->session->all()));
            $this->written = true;
        } else {
            $this->handler->destroy($this->session->id());
        }
        return $this->last = $res;
    }

    public function followRedirect(): Response
    {
        $loc = $this->last?->headers['Location'] ?? throw new \RuntimeException('Kein Redirect');
        $p = parse_url($loc);
        parse_str($p['query'] ?? '', $q);
        return $this->get($p['path'] ?? '/', $q);
    }
}
