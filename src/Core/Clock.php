<?php
declare(strict_types=1);

namespace Vylaris\Core;

/** Zeitquelle (UTC, DB-Format). Im Test durch feste Zeit ersetzbar. */
final class Clock
{
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    public static function fix(?int $ts): void
    {
        self::$fixed = $ts;
    }

    public static function advance(int $seconds): void
    {
        self::$fixed = self::now() + $seconds;
    }

    public static function db(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? self::now());
    }

    public static function fromDb(string $s): int
    {
        return (int) strtotime($s . ' UTC');
    }
}
