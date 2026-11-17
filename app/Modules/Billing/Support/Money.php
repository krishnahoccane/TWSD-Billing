<?php

namespace App\Modules\Billing\Support;

use InvalidArgumentException;

/**
 * Integer minor-unit arithmetic (paise). No floats anywhere.
 */
final class Money
{
    /**
     * "1,250.5" -> 125050. Rejects anything that is not a plain,
     * non-negative amount with at most two decimals.
     */
    public static function parse(string $amount): int
    {
        $clean = str_replace([',', ' '], '', trim($amount));

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $clean)) {
            throw new InvalidArgumentException('Enter an amount such as 250 or 250.50.');
        }

        [$whole, $fraction] = array_pad(explode('.', $clean), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, ?string $currency = null): string
    {
        $currency ??= (string) config('wcc.billing.currency', 'INR');
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        $number = number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $currency === 'INR' ? '₹' : $currency.' ';

        return $sign.$symbol.$number;
    }

    /**
     * round_half_up(numerator / denominator) for integers, symmetric for
     * negatives. Used for proration and tax.
     */
    public static function divideRound(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $sign = $numerator < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + $denominator, 2 * $denominator);
    }

    /** "18" or "18.00" percent -> 1800 basis points. */
    public static function rateToBasisPoints(string $percent): int
    {
        return self::parse($percent);
    }
}
