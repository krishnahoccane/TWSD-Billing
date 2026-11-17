<?php

namespace App\Modules\Installer\Services;

/**
 * Server requirements for step 1 of the installer.
 *
 * Each check is ['group', 'label', 'status' => ok|warn|fail, 'detail'].
 * "fail" blocks installation; "warn" is shown but does not.
 */
class RequirementChecker
{
    public const MIN_PHP = '8.2.0';

    public const RECOMMENDED_PHP = '8.3.0';

    /** Extensions the application cannot run without. */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => 'MySQL / MariaDB database driver',
        'mbstring' => 'Multibyte strings',
        'openssl' => 'Encryption',
        'bcmath' => 'Exact money arithmetic',
        'ctype' => 'Character checks',
        'curl' => 'Google and Zoho API calls',
        'dom' => 'XML / HTML handling',
        'fileinfo' => 'File type detection',
        'json' => 'JSON',
        'tokenizer' => 'PHP tokenizer',
        'xml' => 'XML',
        'filter' => 'Input filtering',
        'session' => 'Sessions',
    ];

    /** Useful but not essential. */
    public const RECOMMENDED_EXTENSIONS = [
        'intl' => 'Locale-aware formatting',
        'redis' => 'Redis cache, sessions and queues (needed only if you choose Redis)',
        'zip' => 'Exports',
        'sodium' => 'Modern cryptography',
    ];

    /** @return list<array{group: string, label: string, status: string, detail: string}> */
    public function checks(): array
    {
        $checks = [];

        $checks[] = $this->check('PHP', 'PHP version', match (true) {
            version_compare(PHP_VERSION, self::MIN_PHP, '<') => 'fail',
            version_compare(PHP_VERSION, self::RECOMMENDED_PHP, '<') => 'warn',
            default => 'ok',
        }, PHP_VERSION.' (minimum '.self::MIN_PHP.', recommended '.self::RECOMMENDED_PHP.')');

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $checks[] = $this->check('Required extensions', $extension, extension_loaded($extension) ? 'ok' : 'fail', $purpose);
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension => $purpose) {
            $checks[] = $this->check('Recommended extensions', $extension, extension_loaded($extension) ? 'ok' : 'warn', $purpose);
        }

        foreach ([
            'storage' => storage_path(),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $label => $path) {
            $checks[] = $this->check('Folders', $label.' is writable', is_dir($path) && is_writable($path) ? 'ok' : 'fail', $path);
        }

        $env = InstallerService::envPath();
        $envWritable = is_file($env) ? is_writable($env) : is_writable(dirname($env));
        $checks[] = $this->check('Folders', '.env can be written', $envWritable ? 'ok' : 'fail', $env);

        $checks[] = $this->check('Application', 'Front-end assets built', is_file(public_path('build/manifest.json')) ? 'ok' : 'fail',
            'public/build/manifest.json — run npm ci && npm run build');
        $checks[] = $this->check('Application', 'Dependencies installed', is_file(base_path('vendor/autoload.php')) ? 'ok' : 'fail',
            'composer install --no-dev --optimize-autoloader');
        $checks[] = $this->check('Application', 'Configuration not cached', is_file(app()->getCachedConfigPath()) ? 'warn' : 'ok',
            'A cached configuration is cleared automatically at the end of installation');
        $checks[] = $this->check('Application', 'HTTPS', request()->isSecure() ? 'ok' : 'warn',
            request()->isSecure() ? 'Secure connection' : 'Not on HTTPS — production must be served over HTTPS (secure cookies are switched on only for https:// URLs)');

        return $checks;
    }

    public function passes(?array $checks = null): bool
    {
        return collect($checks ?? $this->checks())->doesntContain('status', 'fail');
    }

    private function check(string $group, string $label, string $status, string $detail): array
    {
        return compact('group', 'label', 'status', 'detail');
    }
}
