<?php

namespace App\Modules\Installer;

use App\Modules\Installer\Support\InstallState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * Before installation there is no .env, so there is no APP_KEY, database or
 * cache. This provider switches the application into a self-contained install
 * mode: a temporary key kept in storage, file sessions (encrypted) and file
 * cache, synchronous queue. Nothing touches a database until the installer
 * has been given one.
 */
class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (InstallState::detect(filled(config('app.key')))) {
            return;
        }

        config([
            'app.key' => $this->temporaryKey(),
            'app.debug' => false,
            'session.driver' => 'file',
            'session.encrypt' => true,
            'session.secure' => false,
            'cache.default' => 'file',
            'queue.default' => 'sync',
            'mail.default' => 'log',
            'logging.default' => 'single',
        ]);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }

    /** A random key that only protects the installer's own session. Deleted after installation. */
    private function temporaryKey(): string
    {
        $path = storage_path('framework/installer.key');

        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, 'base64:'.base64_encode(random_bytes(32)), LOCK_EX);
        }

        return trim((string) file_get_contents($path));
    }
}
