<?php
declare(strict_types=1);

/** Aufruf: php bin/migrate.php  – wendet ausstehende Migrationen an. */
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use Vylaris\Core\{Config, Database, Migrator};

$config = Config::load(\Vylaris\Core\Bootstrap::configPath($root));
$applied = (new Migrator(Database::fromConfig($config), $root . '/migrations'))->migrate();
echo $applied ? "Angewendet:\n - " . implode("\n - ", $applied) . "\n" : "Schema ist aktuell.\n";
