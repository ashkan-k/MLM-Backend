<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function normalize(string|int|float $value, int $scale = 3): string
    {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float) $value, $scale, '.', '');
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }

        return bcadd((string) $value, '0', $scale);
    }

    public static function percentOf(string $amount, string $percent, int $scale = 3): string
    {
        $product = bcmul(self::normalize($amount, 6), self::normalize($percent, 6), 6);

        return bcdiv($product, '100', $scale);
    }

    public static function shareOf(string $value, string $sharePercent, int $scale = 3): string
    {
        return self::percentOf($value, $sharePercent, $scale);
    }

    public static function add(string $a, string $b, int $scale = 3): string
    {
        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function sub(string $a, string $b, int $scale = 3): string
    {
        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function cmp(string $a, string $b, int $scale = 3): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }
}
