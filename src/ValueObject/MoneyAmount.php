<?php

declare(strict_types=1);

namespace App\ValueObject;

final class MoneyAmount
{
    private function __construct() {}

    public static function normalize(string|int $amount): string
    {
        $value = trim((string) $amount);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Сумма должна быть от 0 до 9 999 999 999,99');
        }

        [$rubles, $kopecks] = array_pad(explode('.', $value, 2), 2, '');

        $rubles = ltrim($rubles, '0') ?: '0';

        return $rubles.'.'.str_pad($kopecks, 2, '0');
    }

    public static function toMinor(string|int $amount): int
    {
        $normalized = self::normalize($amount);
        [$rubles, $kopecks] = explode('.', $normalized);

        return ((int) $rubles * 100) + (int) $kopecks;
    }

    public static function fromMinor(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return sprintf('%s%d.%02d', $sign, intdiv($amount, 100), $amount % 100);
    }
}
