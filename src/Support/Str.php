<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function randomHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashSha256(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function randomId(string $prefix): string
    {
        return $prefix . '_' . self::randomHex(10);
    }

    public static function randomNumericCode(int $length = 6): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
