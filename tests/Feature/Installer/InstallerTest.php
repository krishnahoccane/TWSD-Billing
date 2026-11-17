<?php

namespace Tests\Feature\Installer;

use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\Installer\Support\EnvWriter;
use App\Modules\Installer\Support\InstallState;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private string $dir;

    private const PASSWORD = 'Install-Admin-2026!x';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wcc-installer-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        touch($this->dir.'/target.sqlite');

        $this->app->useEnvironmentPath($this->dir);
        InstallState::fake(false, $this->dir.'/installed.lock');
    }

    protected function tearDown(): void
    {
        InstallState::reset();
        config(['database.default' => 'sqlite']);
        DB::purge('installer');

        parent::tearDown();

        File::deleteDirectory($this->dir);
    }

    private function database(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'Workspace Control Center',
            'app_url' => 'https://billing.example.test',
            'driver' => 'sqlite',
            'database' => $this->dir.'/target.sqlite',
        ], $overrides);
    }

    private function env(): array
    {
        return Dotenv::parse(file_get_contents($this->dir.'/.env'));
    }

    public function test_before_installation_every_page_leads_to_the_installer(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
        $this->getJson('/api/v1/profile')->assertStatus(503);
        $this->get('/up')->assertOk();

        $this->get('/install')->assertRedirect(route('install.requirements'));
        $this->get('/install/requirements')->assertOk()->assertSee('pdo_mysql')->assertSee('bcmath')->assertSee('PHP version');

        // Steps cannot be skipped.
        $this->get('/install/mail')->assertRedirect(route('install.requirements'));
        $this->post('/install/administrator', ['name' => 'X'])->assertRedirect(route('install.requirements'));
    }

    public function test_once_installed_the_installer_is_gone(): void
    {
        InstallState::fake(true);

        $this->get('/install')->assertNotFound();
        $this->get('/install/requirements')->assertNotFound();
        $this->post('/install/database', $this->database())->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_full_installation_writes_a_production_env_and_creates_the_super_admin(): void
    {
        $this->post('/install/requirements')->assertRedirect(route('install.database'));

        // A bad connection is reported and nothing is saved.
        $this->post('/install/database', $this->database(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'username' => 'nobody', 'password' => 'db-secret-value']))
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'Could not connect') && ! str_contains($m, 'db-secret-value'));
        $this->get('/install/administrator')->assertRedirect(route('install.database'));

        $this->post('/install/database', $this->database())->assertRedirect(route('install.administrator'));

        $this->get('/install/administrator')->assertOk()->assertSee('Display name');
        $this->post('/install/administrator', ['name' => 'Platform Owner', 'email' => 'owner@example.test',
            'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/install/administrator', ['name' => 'Platform Owner', 'email' => 'Owner@Example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect(route('install.mail'));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session('installer')), 'Only the hash is kept between steps.');

        $this->get('/install/mail')->assertOk()->assertSee('SMTP host');
        $this->post('/install/mail', ['action' => 'install', 'host' => '', 'port' => 587])->assertSessionHasErrors(['host', 'from_address']);

        $this->post('/install/mail', [
            'action' => 'install', 'host' => 'smtp.example.test', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'mailer', 'password' => 'mail p@ss #1 $x', 'from_address' => 'billing@example.test', 'from_name' => 'TWSD Billing',
        ])->assertOk()->assertSee('Installation complete')->assertSee('owner@example.test');

        // Database prepared, administrator created with the Super Admin role.
        $db = DB::connection('installer');
        $user = $db->table('users')->where('email', 'owner@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(password_verify(self::PASSWORD, $user->password));
        $this->assertTrue($db->table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)->where('roles.name', RoleName::SuperAdmin->value)->exists());
        $this->assertTrue($db->table('audit_logs')->where('action', 'platform.installed')->exists());

        // A production .env.
        $env = $this->env();
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $env['APP_KEY']);
        $this->assertSame('https://billing.example.test', $env['APP_URL']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertSame('database', $env['QUEUE_CONNECTION']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('true', $env['SESSION_ENCRYPT']);
        $this->assertSame('true', $env['WCC_ENFORCE_2FA']);
        $this->assertSame('smtp', $env['MAIL_MAILER']);
        $this->assertSame('smtps', $env['MAIL_SCHEME']);
        $this->assertSame('mail p@ss #1 $x', $env['MAIL_PASSWORD']);
        $this->assertSame('TWSD Billing', $env['MAIL_FROM_NAME']);
        $this->assertArrayNotHasKey('WCC_DEV_PASSWORD', $env);
        $this->assertStringNotContainsString(self::PASSWORD, file_get_contents($this->dir.'/.env'));

        // Locked: the installer is switched off.
        $this->assertFileExists($this->dir.'/installed.lock');
        $this->get('/install')->assertNotFound();
    }

    public function test_an_existing_database_needs_one_of_its_super_admins_and_is_not_wiped(): void
    {
        // First installation.
        $this->post('/install/requirements');
        $this->post('/install/database', $this->database());
        $this->post('/install/administrator', ['name' => 'Owner', 'email' => 'owner@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $this->post('/install/mail', ['action' => 'install', 'skip_mail' => '1'])->assertOk()->assertSee('E-mail is not set up');
        $this->assertSame('log', $this->env()['MAIL_MAILER']);

        DB::connection('installer')->table('users')->insert(['uuid' => (string) Str::uuid(), 'name' => 'Staff', 'email' => 'staff@example.test',
            'password' => bcrypt(self::PASSWORD), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // .env lost and the lock removed on the server: the installer runs again.
        unlink($this->dir.'/.env');
        unlink($this->dir.'/installed.lock');
        InstallState::fake(false, $this->dir.'/installed.lock');
        $this->flushSession();

        $this->post('/install/requirements');
        $this->post('/install/database', $this->database())->assertRedirect(route('install.administrator'));
        $this->get('/install/administrator')->assertOk()->assertSee('Existing installation found')->assertDontSee('Display name');

        // Not a Super Admin / wrong password → refused. A new administrator cannot be slipped in.
        $this->post('/install/administrator', ['email' => 'staff@example.test', 'password' => self::PASSWORD])->assertSessionHas('error');
        $this->post('/install/administrator', ['email' => 'owner@example.test', 'password' => 'Wrong-Password-1!'])->assertSessionHas('error');
        $this->post('/install/administrator', ['name' => 'Intruder', 'email' => 'intruder@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHas('error');
        $this->get('/install/mail')->assertRedirect(route('install.administrator'));

        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->post('/install/administrator', ['email' => 'owner@example.test', 'password' => self::PASSWORD, 'app_key' => $key])
            ->assertRedirect(route('install.mail'));
        $this->post('/install/mail', ['action' => 'install', 'skip_mail' => '1'])->assertOk();

        $this->assertSame(2, DB::connection('installer')->table('users')->count(), 'Existing data is kept; no new user.');
        $this->assertSame($key, $this->env()['APP_KEY'], 'The previous APP_KEY is reused when given.');
    }

    public function test_env_values_with_special_characters_survive_a_round_trip(): void
    {
        $writer = new EnvWriter;
        $values = ['A' => 'plain', 'B' => 'with space', 'C' => "it's \$HOME", 'D' => 'a"b\'c$d\\e', 'E' => 'x#y', 'F' => null, 'G' => true, 'H' => ''];

        $content = $writer->render("# comment\nA=old\nB=\n", $values);

        $this->assertStringStartsWith("# comment\nA=plain\n", $content);
        $parsed = Dotenv::parse($content);
        $this->assertSame(['plain', 'with space', "it's \$HOME", 'a"b\'c$d\\e', 'x#y', 'null', 'true', ''],
            [$parsed['A'], $parsed['B'], $parsed['C'], $parsed['D'], $parsed['E'], $parsed['F'], $parsed['G'], $parsed['H']]);
    }
}
