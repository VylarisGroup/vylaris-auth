<?php
declare(strict_types=1);

namespace Vylaris\Core;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:array}> */
    private array $routes = [];

    /** @param callable[] $middleware je Middleware: fn(Request, callable $next): Response */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler, $middleware];
    }

    public function get(string $p, callable $h, array $mw = []): void
    {
        $this->add('GET', $p, $h, $mw);
    }

    public function post(string $p, callable $h, array $mw = []): void
    {
        $this->add('POST', $p, $h, $mw);
    }

    public function dispatch(Request $request): Response
    {
        $methodAllowed = false;
        foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method && !($method === 'GET' && $request->method === 'HEAD')) {
                $methodAllowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $core = static fn (Request $r): Response => $handler($r, $params);
            $chain = array_reduce(
                array_reverse($middleware),
                static fn (callable $next, callable $mw) => static fn (Request $r): Response => $mw($r, $next),
                $core
            );
            return $chain($request);
        }
        return $methodAllowed ? new Response(405, 'Methode nicht erlaubt') : new Response(404, 'Nicht gefunden');
    }
}
