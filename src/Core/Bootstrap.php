<?php
declare(strict_types=1);

namespace Vylaris\Core;

use Vylaris\Service\{MailTransport, PhpMailTransport, SmtpMailTransport};

/** Gemeinsamer Start für Front Controller, Cron und CLI-Tools. */
final class Bootstrap
{
    public static function configPath(string $root): string
    {
        return getenv('VYLARIS_CONFIG') ?: $root . '/config/config.php';
    }

    public static function app(string $root, ?MailTransport $transport = null, ?\Vylaris\Social\HttpClient $http = null): App
    {
        date_default_timezone_set('UTC'); // intern immer UTC, Anzeige regelt das UI
        $config = Config::load(self::configPath($root));
        $dev = $config->get('app.env') === 'development';
        ini_set('display_errors', $dev ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $db = Database::fromConfig($config);
        $app = new App($config, $db, $transport ?? (trim((string) $config->get('mail.host', '')) === '' ? new PhpMailTransport($config) : new SmtpMailTransport($config)), $root, $http);
        self::registerModules($app);
        return $app;
    }

    /** Zusatzmodule werden hier eingehängt (siehe jeweilige Klassen). */
    public static function registerModules(App $app): void
    {
        foreach (['Vylaris\\Controller\\MfaController' => 'mfa', 'Vylaris\\Controller\\OidcController' => 'oidc',
                  'Vylaris\\Controller\\AdminController' => 'admin', 'Vylaris\\Controller\\ApiController' => 'api',
                  'Vylaris\\Controller\\SocialController' => 'social', 'Vylaris\\Controller\\BrandController' => 'brand',
                  'Vylaris\\Controller\\PrivacyController' => 'privacy', 'Vylaris\\Controller\\UpdateController' => 'update'] as $class => $name) {
            if (class_exists($class)) {
                $app->registerModule($name, new $class($app));
            }
        }
    }
}
