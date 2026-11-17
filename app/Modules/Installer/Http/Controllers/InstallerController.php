<?php

namespace App\Modules\Installer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Installer\Exceptions\InstallerException;
use App\Modules\Installer\Services\InstallerService;
use App\Modules\Installer\Services\RequirementChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;

/**
 * Web installer, reachable only while the application is not installed
 * (InstallationGate). Four steps; each one's answers are kept in the
 * (encrypted, file-based) session until the last step installs everything.
 */
class InstallerController extends Controller
{
    private const KEY = 'installer';

    public const STEPS = [
        1 => ['requirements', 'Requirements'],
        2 => ['database', 'Database'],
        3 => ['administrator', 'Super Admin'],
        4 => ['mail', 'E-mail'],
    ];

    public function __construct(
        private readonly InstallerService $installer,
        private readonly RequirementChecker $requirements,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('install.'.self::STEPS[$this->firstIncompleteStep($request)][0]);
    }

    // Step 1 ---------------------------------------------------------------

    public function requirements(): View
    {
        $checks = $this->requirements->checks();

        return view('installer.requirements', [
            'step' => 1,
            'groups' => collect($checks)->groupBy('group'),
            'passes' => $this->requirements->passes($checks),
        ]);
    }

    public function confirmRequirements(Request $request): RedirectResponse
    {
        if (! $this->requirements->passes()) {
            return back()->with('error', 'Fix the failed requirements, then check again.');
        }

        $request->session()->put(self::KEY.'.requirements', true);

        return redirect()->route('install.database');
    }

    // Step 2 ---------------------------------------------------------------

    public function database(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 2)) {
            return $redirect;
        }

        return view('installer.database', [
            'step' => 2,
            'app' => $this->state($request, 'app') ?? ['name' => config('app.name'), 'url' => $request->root()],
            'db' => $this->state($request, 'database') ?? ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306],
            'redis' => $this->state($request, 'redis'),
            'drivers' => InstallerService::drivers(),
            'redisAvailable' => extension_loaded('redis'),
        ]);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 2)) {
            return $redirect;
        }

        $sqlite = $request->input('driver') === 'sqlite';
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:100'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'driver' => ['required', Rule::in(array_keys(InstallerService::drivers()))],
            'host' => [Rule::requiredIf(! $sqlite), 'nullable', 'string', 'max:255'],
            'port' => [Rule::requiredIf(! $sqlite), 'nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => [Rule::requiredIf(! $sqlite), 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'use_redis' => ['boolean'],
            'redis_host' => ['required_if:use_redis,1', 'nullable', 'string', 'max:255'],
            'redis_port' => ['required_if:use_redis,1', 'nullable', 'integer', 'between:1,65535'],
            'redis_password' => ['nullable', 'string', 'max:255'],
        ]);

        $db = [
            'driver' => $data['driver'],
            'host' => $data['host'] ?? null,
            'port' => isset($data['port']) ? (int) $data['port'] : null,
            'database' => $data['database'],
            'username' => $data['username'] ?? null,
            'password' => $this->secret($request, 'password', $this->state($request, 'database'), ['driver', 'host', 'database', 'username'], $data),
        ];

        $redis = null;
        if ($request->boolean('use_redis')) {
            $redis = ['host' => $data['redis_host'], 'port' => (int) $data['redis_port']];
            $redis['password'] = $this->secret($request, 'redis_password', $this->state($request, 'redis'), ['host'], $redis, 'password');
        }

        try {
            $info = $this->installer->inspectDatabase($db);
            if ($redis) {
                $this->installer->testRedis($redis);
            }
        } catch (InstallerException $e) {
            return back()->withInput($request->except('password', 'redis_password'))->with('error', $e->getMessage());
        }

        $request->session()->put(self::KEY.'.app', ['name' => $data['app_name'], 'url' => rtrim($data['app_url'], '/')]);
        $request->session()->put(self::KEY.'.database', $db);
        $request->session()->put(self::KEY.'.database_info', $info);
        $request->session()->put(self::KEY.'.redis', $redis);
        $request->session()->forget(self::KEY.'.administrator');

        return redirect()->route('install.administrator')->with('success', "Connected to {$info['version']}.");
    }

    // Step 3 ---------------------------------------------------------------

    public function administrator(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 3)) {
            return $redirect;
        }

        return view('installer.administrator', [
            'step' => 3,
            'existing' => ($this->state($request, 'database_info')['users'] ?? 0) > 0,
            'info' => $this->state($request, 'database_info'),
            'admin' => $this->state($request, 'administrator'),
        ]);
    }

    public function saveAdministrator(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 3)) {
            return $redirect;
        }

        $existing = ($this->state($request, 'database_info')['users'] ?? 0) > 0;

        if ($existing) {
            // The database already has users: prove you are one of its Super Admins.
            $data = $request->validate([
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string'],
                'app_key' => ['nullable', 'string', 'regex:/^base64:[A-Za-z0-9+\/]{43}=$/'],
            ], ['app_key.regex' => 'An APP_KEY looks like base64: followed by 44 characters.']);

            $user = $this->installer->verifyExistingSuperAdmin($this->state($request, 'database'), $data['email'], $data['password']);

            if (! $user) {
                return back()->withInput($request->only('email'))->with('error', 'Those are not the details of an active Super Admin in this database.');
            }

            $request->session()->put(self::KEY.'.administrator', [
                'mode' => 'existing', 'user_id' => $user->getKey(), 'email' => $user->email, 'name' => $user->name,
                'app_key' => $data['app_key'] ?? null,
            ]);
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ]);

            $request->session()->put(self::KEY.'.administrator', [
                'mode' => 'new',
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                // Only the hash is kept between steps, never the password.
                'password_hash' => Hash::make($data['password']),
            ]);
        }

        return redirect()->route('install.mail');
    }

    // Step 4 ---------------------------------------------------------------

    public function mail(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 4)) {
            return $redirect;
        }

        $app = $this->state($request, 'app');

        return view('installer.mail', [
            'step' => 4,
            'mail' => $this->state($request, 'mail') ?? [
                'port' => 587, 'encryption' => 'tls', 'from_name' => $app['name'],
                'from_address' => 'no-reply@'.(parse_url($app['url'], PHP_URL_HOST) ?: 'example.com'),
            ],
            'adminEmail' => $this->state($request, 'administrator')['email'],
        ]);
    }

    public function saveMail(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 4)) {
            return $redirect;
        }

        $skip = $request->boolean('skip_mail') && $request->input('action') !== 'test';
        $mail = null;

        if (! $skip) {
            $data = $request->validate([
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:255'],
                'from_address' => ['required', 'email', 'max:255'],
                'from_name' => ['required', 'string', 'max:100'],
            ]);
            $data['password'] = $this->secret($request, 'password', $this->state($request, 'mail'), ['host', 'username'], $data);
            $mail = $data;
            $request->session()->put(self::KEY.'.mail', $mail);
        }

        if ($request->input('action') === 'test') {
            $to = $this->state($request, 'administrator')['email'];

            try {
                $this->installer->sendTestMail($mail, $to);
            } catch (InstallerException $e) {
                return back()->withInput($request->except('password'))->with('error', $e->getMessage());
            }

            return back()->withInput($request->except('password'))->with('success', "Test e-mail sent to {$to}. Check the inbox (and spam folder).");
        }

        if (! $this->requirements->passes()) {
            return redirect()->route('install.requirements')->with('error', 'A requirement is no longer met.');
        }

        $state = [
            'app' => $this->state($request, 'app'),
            'database' => $this->state($request, 'database'),
            'redis' => $this->state($request, 'redis'),
            'administrator' => $this->state($request, 'administrator'),
            'mail' => $mail,
        ];

        try {
            $result = $this->installer->install($state);
        } catch (InstallerException|RuntimeException $e) {
            report($e);

            return back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }

        $request->session()->forget(self::KEY);

        return view('installer.complete', [
            'step' => 5,
            'adminEmail' => $result['admin_email'],
            'loginUrl' => $state['app']['url'].'/login',
            'mailConfigured' => $mail !== null,
            'redis' => $state['redis'] !== null,
        ]);
    }

    // ---------------------------------------------------------------------

    private function state(Request $request, string $key): mixed
    {
        return $request->session()->get(self::KEY.'.'.$key);
    }

    private function firstIncompleteStep(Request $request): int
    {
        return match (true) {
            ! $this->state($request, 'requirements') => 1,
            ! $this->state($request, 'database') => 2,
            ! $this->state($request, 'administrator') => 3,
            default => 4,
        };
    }

    private function guard(Request $request, int $step): ?RedirectResponse
    {
        return $this->firstIncompleteStep($request) < $step ? $this->index($request) : null;
    }

    /**
     * Password fields are never shown again; left blank on a revisit, the saved
     * value is kept if the connection it belongs to has not changed.
     */
    private function secret(Request $request, string $field, ?array $saved, array $identity, array $current, string $savedKey = 'password'): ?string
    {
        $typed = $request->input($field);

        if (filled($typed)) {
            return $typed;
        }

        if ($saved && collect($identity)->every(fn ($key) => ($saved[$key] ?? null) == ($current[$key] ?? null))) {
            return $saved[$savedKey] ?? null;
        }

        return null;
    }
}
