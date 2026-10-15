<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use Vylaris\Core\{Bootstrap, Request};

if (!is_file(Bootstrap::configPath($root))) {
    header('Location: /setup.php', true, 302);
    exit;
}

try {
    $app = Bootstrap::app($root);
    $request = Request::fromGlobals($app->config->get('security.trusted_proxies', []));
    $response = $app->handle($request);
    $response->send();

    // Mailversand nach dem Response (falls kein Cron läuft): schnell, begrenzt.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if ($response->status < 500 && random_int(1, 5) === 1) {
        $app->mailer->flush(5);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('VylarisAuth Startfehler: ' . get_class($e) . ': ' . $e->getMessage());
    echo 'Der Dienst ist vorübergehend nicht verfügbar.';
}
