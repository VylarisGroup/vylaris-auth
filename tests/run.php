<?php
declare(strict_types=1);

/** Aufruf: php tests/run.php [Filter]   – führt alle tests/*Test.php aus. */
require dirname(__DIR__) . '/src/autoload.php';

use Vylaris\Tests\Support\TestCase;

$filter = $argv[1] ?? null;
$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$skips = [];

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = 'Vylaris\\Tests\\' . basename($file, '.php');
    if (!class_exists($class) || !is_subclass_of($class, TestCase::class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        if ($filter !== null && !str_contains($class . '::' . $method, $filter)) {
            continue;
        }
        $t = new $class();
        try {
            $t->runSetUp();
            try {
                $t->$method();
            } finally {
                $t->runTearDown();
            }
            $passed++;
            echo '.';
        } catch (\Vylaris\Tests\Support\SkippedTest $e) {
            $skipped++;
            echo 'S';
            $skips[$e->getMessage()] = ($skips[$e->getMessage()] ?? 0) + 1;
        } catch (\Throwable $e) {
            $failed++;
            echo 'F';
            $failures[] = $class . '::' . $method . "\n    " . $e::class . ': ' . $e->getMessage()
                . "\n    " . basename($e->getFile()) . ':' . $e->getLine();
        }
        $assertions += $t->assertions;
    }
}

echo "\n\n";
foreach ($failures as $f) {
    echo "FEHLER: $f\n";
}
foreach ($skips as $reason => $n) {
    echo "ÜBERSPRUNGEN ($n): $reason\n";
}
printf("%d Tests, %d Assertions, %d bestanden, %d fehlgeschlagen, %d übersprungen\n", $passed + $failed + $skipped, $assertions, $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
