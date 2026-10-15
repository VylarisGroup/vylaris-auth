<?php
declare(strict_types=1);

/**
 * Wartungsjob: Mailversand + Bereinigung. Empfohlen jede Minute bis alle 5 Minuten.
 * Plesk: Geplante Aufgaben → "PHP-Skript ausführen" → bin/cron.php (siehe docs/PLESK.md).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use Vylaris\Core\Bootstrap;
use Vylaris\Service\Maintenance;

$app = Bootstrap::app($root);
// Während eines Updates nichts tun.
if ($app->updater->locked()) {
    exit(0);
}
// Parallele Läufe vermeiden (Shared Hosting kennt keinen Prozess-Supervisor).
$lock = fopen($root . '/var/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$m = new Maintenance($app->db, $app->mailer, $app->sessions, $app->tokens, $app->limiter, $app->audit, $app->keys, (int) $app->config->get('security.audit_retention_days', 365), $app->privacy, (int) $app->config->get('privacy.ip_retention_days', 30), $app->license, $app->updater);
$res = $m->run();
if (in_array('-v', $argv, true)) {
    foreach ($res as $k => $v) {
        echo "$k: $v\n";
    }
}
