<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

/** Minimaler, abhängigkeitsfreier Test-Basistyp (läuft auch ohne PHPUnit/Composer). */
abstract class TestCase
{
    public int $assertions = 0;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function runTearDown(): void
    {
        $this->tearDown();
    }

    public function runSetUp(): void
    {
        $this->setUp();
    }

    protected function skip(string $reason): never
    {
        throw new SkippedTest($reason);
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== true) {
            $this->fail($msg ?: 'Erwartet true, erhalten ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== false) {
            $this->fail($msg ?: 'Erwartet false, erhalten ' . var_export($v, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($msg ? $msg . ': ' : '') . 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    protected function assertNull(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== null) {
            $this->fail($msg ?: 'Erwartet null, erhalten ' . var_export($v, true));
        }
    }

    protected function assertNotNull(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v === null) {
            $this->fail($msg ?: 'Erwartet nicht null');
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail($msg ?: "'$needle' nicht gefunden in '" . substr($haystack, 0, 200) . "'");
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $this->fail($msg ?: "'$needle' unerwartet gefunden");
        }
    }

    protected function assertThrows(string $class, callable $fn, string $msg = ''): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            $this->fail(($msg ?: 'Falsche Exception') . ': erwartet ' . $class . ', erhalten ' . $e::class . ' (' . $e->getMessage() . ')');
        }
        $this->fail($msg ?: "Erwartete Exception $class wurde nicht geworfen");
    }
}
