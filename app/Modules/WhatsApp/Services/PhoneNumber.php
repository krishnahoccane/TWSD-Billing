<?php

namespace App\Modules\WhatsApp\Services;

/**
 * WhatsApp wants international format without "+": 919876543210.
 * A 10-digit number gets the default country code (91).
 */
final class PhoneNumber
{
    public static function normalise(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10 && ! str_starts_with($phone, '+')) {
            $digits = preg_replace('/\D+/', '', (string) config('wcc.whatsapp.default_country_code', '91')).$digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }
}
