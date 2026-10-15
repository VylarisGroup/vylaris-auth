<?php
declare(strict_types=1);

namespace Vylaris\Service;

/**
 * Minimaler QR-Code-Generator (ISO/IEC 18004): Byte-Modus, Fehlerkorrektur M, Versionen 1–10 (bis 213 Byte),
 * automatische Maskenwahl. Reicht für otpauth://-URIs; Ausgabe als SVG.
 */
final class QrCode
{
    /** Pro Version (Index = Version): [Datenwörter gesamt, EC-Wörter je Block, [[Blockanzahl, Datenwörter je Block], ...]] für Stufe M. */
    private const VERSIONS = [
        1 => [16, 10, [[1, 16]]],
        2 => [28, 16, [[1, 28]]],
        3 => [44, 26, [[1, 44]]],
        4 => [64, 18, [[2, 32]]],
        5 => [86, 24, [[2, 43]]],
        6 => [108, 16, [[4, 27]]],
        7 => [124, 18, [[4, 31]]],
        8 => [154, 22, [[2, 38], [2, 39]]],
        9 => [182, 22, [[3, 36], [2, 37]]],
        10 => [216, 26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    /** @var int[] */
    private static array $exp = [];
    /** @var int[] */
    private static array $log = [];

    /** @return bool[][] Modulmatrix [y][x] (true = dunkel) ohne Ruhezone */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = 0;
        foreach (self::VERSIONS as $v => [$dataWords]) {
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * count($bytes) <= $dataWords * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('Text zu lang für den QR-Code.');
        }
        $data = self::encodeData($bytes, $version);
        $codewords = self::interleave($data, $version);
        return self::build($codewords, $version);
    }

    public static function svg(string $text, int $pixels = 220): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $q = 4;
        $path = '';
        foreach ($m as $y => $row) {
            $x = 0;
            while ($x < $n) {
                if ($row[$x]) {
                    $start = $x;
                    while ($x < $n && $row[$x]) {
                        $x++;
                    }
                    $path .= 'M' . ($start + $q) . ' ' . ($y + $q) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $size = $n + 2 * $q;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $pixels . '" height="' . $pixels . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    public static function dataUri(string $text): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text));
    }

    // ------------------------------------------------------------------ Daten und Fehlerkorrektur

    private static function encodeData(array $bytes, int $version): array
    {
        $bits = '0100' . str_pad(decbin(count($bytes)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $capacity = self::VERSIONS[$version][0] * 8;
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $words = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($words) < self::VERSIONS[$version][0]; $pad ^= 0xEC ^ 0x11) {
            $words[] = $pad;
        }
        return $words;
    }

    private static function initGf(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** Reed-Solomon-Rest (EC-Wörter) für einen Datenblock. */
    private static function ecWords(array $data, int $ecLen): array
    {
        self::initGf();
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= self::gfMul($c, self::$exp[$i]);
            }
            $gen = $next;
        }
        $rem = array_fill(0, $ecLen, 0);
        foreach ($data as $d) {
            $factor = $d ^ array_shift($rem);
            $rem[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $ecLen; $i++) {
                    $rem[$i] ^= self::gfMul($gen[$i + 1], $factor);
                }
            }
        }
        return $rem;
    }

    private static function interleave(array $data, int $version): array
    {
        [, $ecLen, $groups] = self::VERSIONS[$version];
        $blocks = [];
        $pos = 0;
        foreach ($groups as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $blocks[] = array_slice($data, $pos, $len);
                $pos += $len;
            }
        }
        $ec = array_map(static fn (array $b) => self::ecWords($b, $ecLen), $blocks);
        $out = [];
        $max = max(array_map('count', $blocks));
        for ($i = 0; $i < $max; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ec as $e) {
                $out[] = $e[$i];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Matrix

    private static function build(array $codewords, int $version): array
    {
        $size = 17 + 4 * $version;
        $mod = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, bool $dark) use (&$mod, &$fn, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $mod[$y][$x] = $dark;
                $fn[$y][$x] = true;
            }
        };
        // Timing
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        // Finder + Separatoren
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        // Alignment
        $al = self::ALIGN[$version];
        $last = count($al) - 1;
        foreach ($al as $i => $ax) {
            foreach ($al as $j => $ay) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Formatbereiche reservieren (Maske 0), Versionsinfo setzen
        self::drawFormat($set, $size, 0);
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }
        // Daten im Zickzack
        $bitsTotal = count($codewords) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $bitsTotal) {
                        $mod[$y][$x] = ((($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1);
                        $i++;
                    }
                }
            }
        }
        // Beste Maske wählen
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = $mod;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if (!$fn[$y][$x] && self::maskBit($mask, $x, $y)) {
                        $cand[$y][$x] = !$cand[$y][$x];
                    }
                }
            }
            $setC = static function (int $x, int $y, bool $dark) use (&$cand, $size): void {
                if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                    $cand[$y][$x] = $dark;
                }
            };
            self::drawFormat($setC, $size, $mask);
            $score = self::penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }
        return $best;
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /** Formatinformation für Stufe M (Formatbits 0) und gegebene Maske. */
    private static function drawFormat(callable $set, int $size, int $mask): void
    {
        $data = (0 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    }

    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        $dark = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                $hist = '';
                for ($b = 0; $b < $n; $b++) {
                    $cur = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $hist .= $cur ? '1' : '0';
                    if ($pass === 0 && $cur) {
                        $dark++;
                    }
                    if ($b > 0) {
                        $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                        if ($cur === $prev) {
                            $run++;
                            if ($run === 5) {
                                $score += 3;
                            } elseif ($run > 5) {
                                $score++;
                            }
                        } else {
                            $run = 1;
                        }
                    }
                }
                $score += 40 * (substr_count($hist, '10111010000') + substr_count($hist, '00001011101'));
            }
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $pct = intdiv($dark * 100, $n * $n);
        return $score + 10 * intdiv(abs($pct - 50), 5);
    }
}
