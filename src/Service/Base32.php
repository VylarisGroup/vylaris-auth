<?php
declare(strict_types=1);

namespace Vylaris\Service;

/** RFC 4648 Base32 (ohne Padding) – für TOTP-Secrets. */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decode(string $b32): string
    {
        $b32 = strtoupper(rtrim(str_replace(' ', '', $b32), '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Ungültiges Base32-Zeichen.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
