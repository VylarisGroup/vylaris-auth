<?php
declare(strict_types=1);

/**
 * Autoloader: eigener PSR-4-Loader (Vylaris\ => src/, Vylaris\Tests\ => tests/).
 * Das Projekt hat bewusst keine Composer-Abhängigkeiten – es läuft nach dem Hochladen ohne Build-Schritt.
 */
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__);
    if (str_starts_with($class, 'Vylaris\\Tests\\')) {
        $file = $root . '/tests/' . str_replace('\\', '/', substr($class, 14)) . '.php';
    } elseif (str_starts_with($class, 'Vylaris\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

