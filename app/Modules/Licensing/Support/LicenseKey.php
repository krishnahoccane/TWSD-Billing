<?php

namespace App\Modules\Licensing\Support;

use SensitiveParameter;

/** License keys: PREFIX-XXXX-XXXX-XXXX-XXXX from an unambiguous alphabet (no 0/O, 1/I/L). */
final class LicenseKey
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(string $prefix): string
    {
        $groups = [];

        for ($g = 0; $g < 4; $g++) {
            $group = '';

            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $group;
        }

        return strtoupper($prefix).'-'.implode('-', $groups);
    }

    public static function normalise(#[SensitiveParameter] string $key): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', trim($key)));
    }

    public static function hash(#[SensitiveParameter] string $key): string
    {
        return hash('sha256', self::normalise($key));
    }
}
