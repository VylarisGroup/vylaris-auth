<?php
declare(strict_types=1);

namespace Vylaris\Core;

use Vylaris\Branding\BrandingService;
use Vylaris\Update\{GithubSource, UpdateException, UpdateSource, Updater};
use Vylaris\Controller\{AccountController, AuthController};
use Vylaris\Oidc\{ClaimsService, ClientService, KeyService, OAuthServer};
use Vylaris\Repository\UserRepository;
use Vylaris\Security\{Csrf, Policy};
use Vylaris\Service\{AdminService, AuditLogger, AuthService, Mailer, MailTransport, MfaService, PasskeyService, PasswordService, PrivacyService, RateLimiter, SessionService, TokenService, TotpService};
use Vylaris\Service\WebAuthn\Verifier;
use Vylaris\Social\{CurlHttpClient, HttpClient, SocialService};

/** Verdrahtung: Services, Routen, Middleware, Sicherheits-Header. */
final class App
{
    public readonly UserRepository $users;
    public readonly PasswordService $passwords;
    public readonly TokenService $tokens;
    public readonly RateLimiter $limiter;
    public readonly Mailer $mailer;
    public readonly AuditLogger $audit;
    public readonly SessionService $sessions;
    public readonly AuthService $auth;
    public readonly Policy $policy;
    public readonly TotpService $totp;
    public readonly MfaService $mfa;
    public readonly PasskeyService $passkeys;
    public readonly AdminService $admin;
    public readonly ClientService $clients;
    public readonly KeyService $keys;
    public readonly ClaimsService $claims;
    public readonly OAuthServer $oauth;
    public readonly SocialService $social;
    public readonly BrandingService $brand;
    public readonly PrivacyService $privacy;
    public readonly Updater $updater;
    public readonly \Vylaris\Update\Changelog $changelog;
    public readonly View $view;
    public readonly Router $router;

    /** Pfade, die kein Cookie-Session-CSRF nutzen (eigene Authentifizierung per Client-Secret/Bearer-Token). */
    private const CSRF_EXEMPT_PREFIXES = ['/oidc/token', '/oidc/revoke', '/oidc/userinfo', '/api/'];

    private ?Session $session = null;
    private ?Request $request = null;

    /** @var array<string,object> */
    private array $extra = [];

    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
        public readonly MailTransport $transport,
        private string $root,
        ?HttpClient $http = null,
        ?UpdateSource $updateSource = null,
    ) {
        $this->brand = new BrandingService($db, $config, $root);
        // Laufzeit-Overrides aus dem Branding: Absendername und Passkey-Anzeigename folgen dem Markennamen.
        if ($this->brand->isCustomized('name') || $this->brand->isCustomized('mail_from_name')) {
            $config->override('mail.from_name', $this->brand->get('mail_from_name') ?: $this->brand->get('name'));
        }
        if ($this->brand->isCustomized('name') || $this->brand->isCustomized('org')) {
            $config->override('webauthn.rp_name', $this->brand->get('name'));
        }
        $this->users = new UserRepository($db);
        $this->passwords = new PasswordService((int) $config->get('security.password_min_length', 12));
        $this->tokens = new TokenService($db);
        $this->limiter = new RateLimiter($db, $config->secretKey());
        $this->mailer = new Mailer($db, $transport, $this->brand);
        $this->audit = new AuditLogger($db);
        $http ??= new CurlHttpClient();
        $this->sessions = new SessionService($db);
        $this->auth = new AuthService($db, $config, $this->users, $this->passwords, $this->tokens, $this->limiter, $this->mailer, $this->audit, $this->sessions);
        $this->policy = new Policy($this->users);
        $this->totp = new TotpService();
        $this->mfa = new MfaService($db, $config, $this->totp, $this->limiter, $this->audit);
        $this->passkeys = new PasskeyService($db, $config, $this->users, new Verifier(), $this->mfa, $this->audit);
        $this->admin = new AdminService($db, $config, $this->users, $this->policy, $this->sessions, $this->mfa, $this->passwords, $this->tokens, $this->mailer, $this->audit);
        $this->clients = new ClientService($db, $this->audit, $this->sessions);
        $this->keys = new KeyService($db, $config);
        $this->claims = new ClaimsService($this->clients, $config->secretKey());
        $this->oauth = new OAuthServer($db, $config, $this->clients, $this->keys, $this->claims, $this->sessions, $this->audit);
        $this->social = new SocialService($db, $config, $http, $this->users, $this->passwords, $this->audit);
        $this->privacy = new PrivacyService($db, $config, $this->users, $this->policy, $this->mailer, $this->audit, $this->brand);
        $repo = trim((string) $config->get('update.repo', GithubSource::DEFAULT_REPO));
        if ($updateSource === null && $repo !== '' && GithubSource::validRepo($repo)) {
            $updateSource = new GithubSource($repo, (string) $config->get('update.token', ''));
        }
        $this->changelog = new \Vylaris\Update\Changelog($root, $db);
        $this->updater = new Updater($root, $updateSource, (int) $config->get('update.check_hours', 12), static fn (): array => (new \Vylaris\Core\Migrator($db, $root . '/migrations'))->migrate());
        $this->view = new View($root . '/templates', ['appName' => $this->brand->get('name')]);
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Optionale Zusatzmodule (MFA, OIDC, Admin, API) registrieren sich selbst. */
    public function registerModule(string $name, object $module): void
    {
        $this->extra[$name] = $module;
        if (method_exists($module, 'routes')) {
            $module->routes($this->router);
        }
    }

    public function module(string $name): object
    {
        return $this->extra[$name] ?? throw new \RuntimeException("Modul $name nicht registriert.");
    }

    public function hasModule(string $name): bool
    {
        return isset($this->extra[$name]);
    }

    public function session(): Session
    {
        return $this->session ?? throw new \LogicException('Keine aktive Sitzung.');
    }

    public function request(): Request
    {
        return $this->request ?? throw new \LogicException('Kein aktiver Request.');
    }

    public function currentUser(): ?array
    {
        return $this->auth->currentUser($this->session());
    }

    private function registerRoutes(): void
    {
        (new AuthController($this))->routes($this->router);
        (new AccountController($this))->routes($this->router);
    }

    public function handle(Request $request, ?Session $session = null): Response
    {
        $this->request = $request;
        // Wartungsmodus während eines Updates (nur der Abschluss/Rückstellung durch Administratoren läuft durch).
        if ($this->updater->locked() && $request->path !== '/admin/update' && !str_starts_with($request->path, '/admin/update/')) {
            return $this->withSecurityHeaders(new Response(503, '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wartung</title><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem"><h1>Wartungsarbeiten</h1><p>Das System wird gerade aktualisiert. Bitte versuchen Sie es in wenigen Minuten erneut.</p></body></html>', ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '120', 'Cache-Control' => 'no-store']), $request);
        }
        // Marken-Dateien (Theme-CSS, Logo, Favicon) sind öffentlich und brauchen weder Sitzung noch Cookie.
        if ($request->method === 'GET' && str_starts_with($request->path, '/brand/')) {
            return $this->withSecurityHeaders($this->brand->serve($request), $request);
        }
        // Öffentliche Metadaten (Discovery, JWKS): ohne Sitzung, ohne Cookie, ohne Datenbankzugriff auf Sitzungen –
        // Clients und Caches erwarten reines JSON; ein Sitzungs-/DB-Fehler darf sie nicht stören.
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && in_array($request->path, ['/.well-known/openid-configuration', '/oidc/discovery', '/oidc/jwks'], true)) {
            try {
                $response = $this->router->dispatch($request);
            } catch (\Throwable $e) {
                $errorId = $this->logError($e);
                $response = Response::json(['error' => 'server_error', 'error_description' => 'Referenz ' . $errorId], 500);
            }
            return $this->withSecurityHeaders($response, $request);
        }
        $this->session = $session ?? Session::bootstrap($this->config, $this->db, $request);
        $this->audit->setContext($request->ip(), $request->userAgent());
        $this->view->share('csrf', Csrf::token($this->session));
        $this->view->share('navPath', $request->path);
        $this->view->share('assetVer', $this->assetVersion());
        $this->view->share('iconsCdn', $this->iconsCdn());
        $this->view->share('magicLinkEnabled', $this->auth->magicLinkEnabled());
        $user = $this->auth->currentUser($this->session);
        $this->view->share('currentUser', $user);
        $this->view->share('isAdmin', $user !== null && $this->policy->isAdmin((int) $user['id']));
        $this->view->share('passkeyEnabled', true);
        $this->view->share('brand', $this->brand->view());
        $this->view->share('appName', $this->brand->get('name'));
        $this->view->share('theme', $this->resolveTheme($request));

        try {
            if ($request->method === 'POST' && !$this->csrfExempt($request->path) && !Csrf::verify($request, $this->session)) {
                $response = $this->page('error', ['title' => 'Sicherheitsprüfung fehlgeschlagen', 'message' => 'Das Formular ist abgelaufen oder ungültig. Bitte laden Sie die Seite neu und versuchen Sie es erneut.'], 403);
            } else {
                $response = $this->router->dispatch($request);
                if ($response->status === 404 && $response->body === 'Nicht gefunden') {
                    $response = $this->page('error', ['title' => 'Seite nicht gefunden', 'message' => 'Die angeforderte Seite existiert nicht.'], 404);
                }
            }
        } catch (\Throwable $e) {
            $errorId = $this->logError($e);
            // Maschinenlesbare Endpunkte (Token, UserInfo, API) antworten auch im Fehlerfall mit JSON statt HTML.
            $response = (str_starts_with($request->path, '/oidc/') || str_starts_with($request->path, '/api/'))
                ? Response::json(['error' => 'server_error', 'error_description' => 'Interner Fehler. Referenz ' . $errorId . ' (siehe var/log/error-*.log auf dem Server).'], 500)
                : $this->page('error', ['title' => 'Interner Fehler', 'message' => 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.'], 500);
        }

        return $this->withSecurityHeaders($response, $request);
    }

    /** dark | light | system – Cookie nur, wenn der Umschalter erlaubt ist. */
    private function resolveTheme(Request $request): string
    {
        $default = $this->brand->get('default_theme');
        if ($this->brand->get('allow_toggle') === '1') {
            $c = (string) ($request->cookies['vtheme'] ?? '');
            if (in_array($c, ['dark', 'light', 'system'], true)) {
                return $c;
            }
        }
        return $default;
    }

    private function csrfExempt(string $path): bool
    {
        foreach (self::CSRF_EXEMPT_PREFIXES as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }
        return false;
    }

    public function page(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    /** Boxicons-CDN (jsDelivr, mit SRI) statt lokaler Kopie; abschaltbar mit ui.icons_cdn = false. */
    public const ICONS_CDN_HOST = 'https://cdn.jsdelivr.net';

    private function iconsCdn(): bool
    {
        return (bool) $this->config->get('ui.icons_cdn', true);
    }

    private function withSecurityHeaders(Response $r, Request $request): Response
    {
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), publickey-credentials-get=(self), publickey-credentials-create=(self)',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'" . ($cdn = $this->iconsCdn() ? ' ' . self::ICONS_CDN_HOST : '') . "; img-src 'self' data:; font-src 'self'" . $cdn . "; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isSecure() || str_starts_with($this->config->baseUrl(), 'https://')) {
            $r->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if (str_starts_with($r->headers['Content-Type'] ?? 'text/html', 'text/html')) {
            $r->headers['Cache-Control'] = 'no-store';
        }
        return $r;
    }

    /** Schreibt den Fehler ins Log und gibt eine kurze Referenz-ID zurück (taucht auch in der Antwort auf). */
    private function logError(\Throwable $e): string
    {
        $id = bin2hex(random_bytes(3));
        $dir = (string) $this->config->get('paths.log_dir', $this->root . '/var/log');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Nur Typ, Meldung, Ort – keine Request-Daten (Passwörter/Tokens).
        $line = sprintf("[%s] #%s %s: %s in %s:%d\n", gmdate('c'), $id, $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
        if (@file_put_contents($dir . '/error-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
            // var/log nicht beschreibbar -> wenigstens ins PHP-/Hoster-Fehlerlog (Plesk: Logs → error_log)
            @error_log('VylarisAuth ' . trim($line));
        }
        return $id;
    }

    /** Versions-Suffix für statische Dateien, damit Browser nach einem Update nie veraltete CSS/JS/Icons laden. */
    private function assetVersion(): string
    {
        $v = @file_get_contents($this->root . '/VERSION');
        $v = $v === false ? '' : preg_replace('/[^0-9A-Za-z.\-]/', '', trim($v));
        $m = @filemtime($this->root . '/public/assets/app.css');
        return rawurlencode(($v !== '' ? $v : '0') . ($m ? '-' . $m : ''));
    }
}
