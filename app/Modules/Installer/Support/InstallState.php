<?php

namespace App\Modules\Installer\Support;

/**
 * Whether this copy of the application has been installed.
 *
 * Installed means: the installer finished (lock file present) OR an APP_KEY is
 * configured (an existing deployment with a hand-written .env). Only when
 * neither is true does the application run in install mode, where every page
 * redirects to the web installer.
 */
class InstallState
{
    private static ?bool $installed = null;

    private static ?string $lockPath = null;

    public static function detect(bool $hasAppKey): bool
    {
        return self::$installed ??= $hasAppKey || is_file(self::lockPath());
    }

    public static function installed(): bool
    {
        return self::$installed ?? true;
    }

    public static function markInstalled(): void
    {
        self::$installed = true;
    }

    public static function lockPath(): string
    {
        return self::$lockPath ?? storage_path('app/installed.lock');
    }

    /** Tests only: force the state and redirect the lock file. */
    public static function fake(bool $installed, ?string $lockPath = null): void
    {
        self::$installed = $installed;
        self::$lockPath = $lockPath;
    }

    public static function reset(): void
    {
        self::$installed = null;
        self::$lockPath = null;
    }
}
