<?php

namespace App\Modules\Licensing\Support;

/**
 * Activation domains: lowercase, no scheme, path, port or leading "www.".
 * Staging (free, not counted): explicitly marked, or staging.*, dev.*,
 * *.test, *.local, localhost.
 */
final class DomainName
{
    public static function normalise(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);
        $domain = (string) preg_replace('#:\d+$#', '', $domain);
        $domain = rtrim($domain, '.');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        if ($domain === 'localhost') {
            return $domain;
        }

        $valid = strlen($domain) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/', $domain) === 1;

        return $valid ? $domain : null;
    }

    public static function isStaging(string $domain): bool
    {
        return $domain === 'localhost'
            || str_starts_with($domain, 'staging.') || str_starts_with($domain, 'dev.')
            || str_ends_with($domain, '.test') || str_ends_with($domain, '.local');
    }
}
