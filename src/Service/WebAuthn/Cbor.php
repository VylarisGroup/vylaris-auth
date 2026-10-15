<?php
declare(strict_types=1);

namespace Vylaris\Service\WebAuthn;

/** Minimaler, strikter CBOR-Decoder (RFC 8949) für WebAuthn: ints, bstr, tstr, array, map, bool, null. */
final class Cbor
{
    private const MAX_DEPTH = 16;

    /** @return array{0:mixed,1:int} Wert und gelesene Bytes */
    public static function decodePartial(string $data, int $offset = 0): array
    {
        $pos = $offset;
        $value = self::read($data, $pos, 0);
        return [$value, $pos - $offset];
    }

    public static function decode(string $data): mixed
    {
        [$value, $len] = self::decodePartial($data);
        if ($len !== strlen($data)) {
            throw new \InvalidArgumentException('CBOR: Überhängende Daten.');
        }
        return $value;
    }

    private static function read(string $d, int &$p, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('CBOR: zu tief verschachtelt.');
        }
        if ($p >= strlen($d)) {
            throw new \InvalidArgumentException('CBOR: unerwartetes Ende.');
        }
        $initial = ord($d[$p++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new \InvalidArgumentException('CBOR: nicht unterstützter Simple-Typ.'),
            };
        }

        $arg = self::readArg($d, $p, $info);

        switch ($major) {
            case 0:
                return $arg;
            case 1:
                return -1 - $arg;
            case 2:
            case 3:
                if ($arg < 0 || $p + $arg > strlen($d)) {
                    throw new \InvalidArgumentException('CBOR: ungültige Länge.');
                }
                $s = substr($d, $p, $arg);
                $p += $arg;
                return $s;
            case 4:
                if ($arg > 65535) {
                    throw new \InvalidArgumentException('CBOR: Array zu groß.');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $out[] = self::read($d, $p, $depth + 1);
                }
                return $out;
            case 5:
                if ($arg > 65535) {
                    throw new \InvalidArgumentException('CBOR: Map zu groß.');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $k = self::read($d, $p, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \InvalidArgumentException('CBOR: ungültiger Map-Schlüssel.');
                    }
                    if (array_key_exists($k, $out)) {
                        throw new \InvalidArgumentException('CBOR: doppelter Map-Schlüssel.');
                    }
                    $out[$k] = self::read($d, $p, $depth + 1);
                }
                return $out;
            default:
                throw new \InvalidArgumentException('CBOR: nicht unterstützter Typ.');
        }
    }

    private static function readArg(string $d, int &$p, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $len = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new \InvalidArgumentException('CBOR: unbestimmte Länge nicht unterstützt.'),
        };
        if ($p + $len > strlen($d)) {
            throw new \InvalidArgumentException('CBOR: unerwartetes Ende.');
        }
        $bytes = substr($d, $p, $len);
        $p += $len;
        $v = 0;
        foreach (str_split($bytes) as $b) {
            $v = ($v << 8) | ord($b);
        }
        if ($len === 8 && $v < 0) {
            throw new \InvalidArgumentException('CBOR: Zahl zu groß.');
        }
        return $v;
    }
}
