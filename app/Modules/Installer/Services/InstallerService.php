<?php

namespace App\Modules\Installer\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Installer\Exceptions\InstallerException;
use App\Modules\Installer\Support\EnvWriter;
use App\Modules\Installer\Support\InstallState;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Redis;
use Throwable;

/**
 * Does the work behind the installer pages. Nothing is written anywhere until
 * install(): earlier steps only test connections.
 *
 * Migrations are run with `migrate --force` (never fresh/wipe), so pointing the
 * installer at an existing database keeps its data.
 */
class InstallerService
{
    public const CONNECTION = 'installer';

    public function __construct(private readonly EnvWriter $env) {}

    /** Always .env (never an environment-specific file such as .env.testing). */
    public static function envPath(): string
    {
        return app()->environmentPath().DIRECTORY_SEPARATOR.'.env';
    }

    /** Drivers offered on the database page. SQLite exists for automated tests only. */
    public static function drivers(): array
    {
        $drivers = ['mysql' => 'MySQL 8', 'mariadb' => 'MariaDB 10.6+'];

        if (app()->runningUnitTests()) {
            $drivers['sqlite'] = 'SQLite (tests only)';
        }

        return $drivers;
    }

    /**
     * Registers the 'installer' connection and makes it the default for this request.
     *
     * @param  array{driver: string, host?: string, port?: int|string, database: string, username?: string, password?: string}  $db
     */
    public function useDatabase(array $db): void
    {
        $base = config('database.connections.'.($db['driver'] === 'sqlite' ? 'sqlite' : $db['driver']), []);

        $connection = $db['driver'] === 'sqlite'
            ? array_merge($base, ['driver' => 'sqlite', 'database' => $db['database'], 'url' => null, 'foreign_key_constraints' => true])
            : array_merge($base, [
                'driver' => $db['driver'],
                'url' => null,
                'host' => $db['host'],
                'port' => (string) $db['port'],
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => $db['password'] ?? '',
                'options' => [\PDO::ATTR_TIMEOUT => 5],
            ]);

        config(['database.connections.'.self::CONNECTION => $connection, 'database.default' => self::CONNECTION]);
        DB::purge(self::CONNECTION);
    }

    /**
     * Connects and reports what is there.
     *
     * @return array{version: string, tables: int, users: int}
     */
    public function inspectDatabase(array $db): array
    {
        try {
            $this->useDatabase($db);
            $connection = DB::connection(self::CONNECTION);
            $connection->getPdo();

            $version = $db['driver'] === 'sqlite'
                ? 'SQLite '.$connection->selectOne('select sqlite_version() as v')->v
                : $connection->selectOne('select version() as v')->v;

            $schema = $connection->getSchemaBuilder();
            $users = $schema->hasTable('users') ? $connection->table('users')->count() : 0;

            return ['version' => $version, 'tables' => count($schema->getTableListing()), 'users' => $users];
        } catch (Throwable $e) {
            throw new InstallerException('Could not connect to the database: '.$this->clean($e->getMessage(), [$db['password'] ?? null]));
        }
    }

    public function testRedis(array $redis): void
    {
        if (! extension_loaded('redis')) {
            throw new InstallerException('The PHP redis extension is not installed on this server.');
        }

        try {
            $client = new Redis;
            $client->connect($redis['host'], (int) $redis['port'], 3.0);

            if (filled($redis['password'] ?? null)) {
                $client->auth($redis['password']);
            }

            $client->ping();
            $client->close();
        } catch (Throwable $e) {
            throw new InstallerException('Could not connect to Redis: '.$this->clean($e->getMessage(), [$redis['password'] ?? null]));
        }
    }

    /** Finds an existing Super Admin in the target database (used when it already has users). */
    public function verifyExistingSuperAdmin(array $db, string $email, string $password): ?User
    {
        $this->useDatabase($db);

        $user = User::query()->where('email', Str::lower($email))->first();

        if (! $user || ! $user->is_active || ! password_verify($password, (string) $user->password)) {
            return null;
        }

        return $user->platformRoles()->where('name', RoleName::SuperAdmin->value)->exists() ? $user : null;
    }

    public function sendTestMail(array $mail, string $to): void
    {
        try {
            Mail::build($this->mailerConfig($mail))->raw(
                'This is a test message from the '.config('app.name').' installer. Your e-mail settings work.',
                fn ($message) => $message->to($to)->from($mail['from_address'], $mail['from_name'])->subject('Test e-mail from the installer'),
            );
        } catch (Throwable $e) {
            throw new InstallerException('The test e-mail could not be sent: '.$this->clean($e->getMessage(), [$mail['password'] ?? null]));
        }
    }

    /**
     * Runs the installation. Order matters: the database is prepared and the
     * administrator created first, then .env is written, then the lock file.
     * If .env cannot be written the installer can simply be run again —
     * migrations and seeding are idempotent and the administrator is not duplicated.
     *
     * @param  array{app: array, database: array, redis: ?array, administrator: array, mail: ?array}  $state
     * @return array{admin_email: string, env_path: string}
     */
    public function install(array $state): array
    {
        $db = $state['database'];
        $this->useDatabase($db);

        try {
            Artisan::call('migrate', ['--force' => true, '--database' => self::CONNECTION]);
            Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true, '--database' => self::CONNECTION]);
        } catch (Throwable $e) {
            throw new InstallerException('Preparing the database failed: '.$this->clean($e->getMessage(), [$db['password'] ?? null]));
        }

        $admin = $this->administrator($state['administrator']);

        $appKey = filled($state['administrator']['app_key'] ?? null)
            ? $state['administrator']['app_key']
            : 'base64:'.base64_encode(random_bytes(32));

        $path = self::envPath();
        $content = $this->env->render($this->template(), $this->environment($state, $appKey));
        $this->env->write($path, $content);

        app(AuditLogger::class)->record('platform.installed', new: [
            'database' => $db['driver'],
            'redis' => $state['redis'] !== null,
            'mail' => $state['mail'] !== null ? 'smtp' : 'log',
            'administrator' => $admin->email,
        ], actor: $admin);

        File::ensureDirectoryExists(dirname(InstallState::lockPath()));
        file_put_contents(InstallState::lockPath(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => app()->version(),
        ], JSON_PRETTY_PRINT), LOCK_EX);
        InstallState::markInstalled();

        @unlink(storage_path('framework/installer.key'));

        if (is_file(app()->getCachedConfigPath())) {
            @unlink(app()->getCachedConfigPath());
        }

        return ['admin_email' => $admin->email, 'env_path' => $path];
    }

    private function administrator(array $input): User
    {
        if (($input['mode'] ?? 'new') === 'existing') {
            return User::query()->whereKey($input['user_id'])->firstOrFail();
        }

        $existing = User::query()->where('email', $input['email'])->first();

        // A re-run after a failed .env write: the administrator already exists.
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($input) {
            $user = new User(['name' => $input['name'], 'email' => $input['email']]);
            $user->password = $input['password_hash'];
            $user->forceFill(['email_verified_at' => now()])->save();

            app(RoleAssignmentService::class)->assignPlatformRole($user, RoleName::SuperAdmin);

            return $user;
        });
    }

    /** @return array<string, string|int|bool|null> */
    public function environment(array $state, string $appKey): array
    {
        $app = $state['app'];
        $db = $state['database'];
        $redis = $state['redis'];
        $mail = $state['mail'];
        $https = str_starts_with($app['url'], 'https://');
        $store = $redis ? 'redis' : 'database';

        return [
            'APP_NAME' => $app['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($app['url'], '/'),
            'LOG_STACK' => 'daily',
            'LOG_LEVEL' => 'info',

            'DB_CONNECTION' => $db['driver'],
            'DB_HOST' => $db['host'] ?? '127.0.0.1',
            'DB_PORT' => (string) ($db['port'] ?? 3306),
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'] ?? '',
            'DB_PASSWORD' => $db['password'] ?? '',

            'SESSION_DRIVER' => $store,
            'SESSION_ENCRYPT' => true,
            'SESSION_SECURE_COOKIE' => $https,
            'CACHE_STORE' => $store,
            'QUEUE_CONNECTION' => $store,

            'REDIS_HOST' => $redis['host'] ?? '127.0.0.1',
            'REDIS_PASSWORD' => filled($redis['password'] ?? null) ? $redis['password'] : null,
            'REDIS_PORT' => (string) ($redis['port'] ?? 6379),

            'MAIL_MAILER' => $mail ? 'smtp' : 'log',
            'MAIL_SCHEME' => $mail ? ($mail['encryption'] === 'ssl' ? 'smtps' : 'smtp') : null,
            'MAIL_HOST' => $mail['host'] ?? '127.0.0.1',
            'MAIL_PORT' => (string) ($mail['port'] ?? 2525),
            'MAIL_USERNAME' => filled($mail['username'] ?? null) ? $mail['username'] : null,
            'MAIL_PASSWORD' => filled($mail['password'] ?? null) ? $mail['password'] : null,
            'MAIL_FROM_ADDRESS' => $mail['from_address'] ?? 'no-reply@'.(parse_url($app['url'], PHP_URL_HOST) ?: 'example.com'),
            'MAIL_FROM_NAME' => $mail['from_name'] ?? $app['name'],

            'WCC_ENFORCE_2FA' => true,
            'VITE_APP_NAME' => $app['name'],
        ];
    }

    private function mailerConfig(array $mail): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $mail['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $mail['host'],
            'port' => (int) $mail['port'],
            'username' => $mail['username'] ?: null,
            'password' => $mail['password'] ?: null,
            'timeout' => 15,
        ];
    }

    private function template(): string
    {
        $example = base_path('.env.example');

        return is_file($example) ? (string) file_get_contents($example) : '';
    }

    /** Removes secrets from driver error messages before they are shown. */
    private function clean(string $message, array $secrets): string
    {
        foreach (array_filter($secrets) as $secret) {
            $message = str_replace($secret, '********', $message);
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message)), 300);
    }
}
