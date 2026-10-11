<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored and calculated as integer minor units (pesewas). These
 * helpers are the only place decimal strings are converted, using bcmath so a
 * value like "0.29" never becomes 28 pesewas through float error.
 */
final class Money
{
    public static function toMinor(int|float|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $value = is_string($amount) ? trim($amount) : (string) $amount;

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid amount [{$value}].");
        }

        return (int) self::roundHalfUp(bcmul($value, '100', 6));
    }

    public static function toMajor(int $minor): string
    {
        return bcdiv((string) $minor, '100', 2);
    }

    /** $minor * $percent / 100, rounded half up to the nearest minor unit. */
    public static function percentOf(int $minor, string|float $percent): int
    {
        return (int) self::roundHalfUp(bcdiv(bcmul((string) $minor, (string) $percent, 10), '100', 10));
    }

    public static function format(int $minor, string $currency = 'GHS'): string
    {
        $sign = $minor < 0 ? '-' : '';

        return $sign.$currency.' '.number_format(abs($minor) / 100, 2);
    }

    public static function roundHalfUp(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $rounded = bcadd(ltrim($value, '-'), '0.5', 0);

        return $negative && $rounded !== '0' ? '-'.$rounded : $rounded;
    }
}
