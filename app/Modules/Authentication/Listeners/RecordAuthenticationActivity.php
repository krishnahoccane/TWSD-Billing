<?php

namespace App\Modules\Authentication\Listeners;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Audits authentication events. Credentials are never written — only the
 * e-mail address that was attempted.
 */
class RecordAuthenticationActivity
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
        ];
    }

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        $this->audit->record('auth.login', $event->user, actor: $event->user);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, actor: $event->user);
        }
    }

    public function onFailed(Failed $event): void
    {
        $this->audit->record(
            action: 'auth.login_failed',
            resource: $event->user instanceof User ? $event->user : null,
            new: ['email' => Str::limit((string) ($event->credentials['email'] ?? ''), 191)],
            status: AuditLogger::STATUS_FAILURE,
        );
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit->record(
            action: 'auth.lockout',
            new: ['email' => Str::limit((string) $event->request->input('email'), 191)],
            status: AuditLogger::STATUS_DENIED,
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.password_reset', $event->user, actor: $event->user);
        }
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->audit->record('auth.two_factor.enabled', $event->user, actor: $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->record('auth.two_factor.disabled', $event->user, actor: $event->user);
    }
}
