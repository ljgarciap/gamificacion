<?php

namespace App\Domain\Admin;

/** Base32 de RFC 4648 (alfabeto A-Z2-7), sin relleno. Es el formato en que las apps de autenticación guardan el secreto TOTP. */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** @throws \InvalidArgumentException con un carácter fuera del alfabeto */
    public static function decode(string $text): string
    {
        $text = strtoupper(str_replace([' ', '-', '='], '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Base32 inválido');
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
