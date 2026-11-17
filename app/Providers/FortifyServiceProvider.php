<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Modules\Authentication\Listeners\RecordAuthenticationActivity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // Deactivated users fail exactly like a wrong password, so the
        // login form never reveals whether an account exists or is disabled.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', Str::lower((string) $request->input('email')))
                ->first();

            $password = (string) $request->input('password');

            if ($user === null) {
                // Keep response timing comparable for unknown addresses.
                Hash::check($password, self::dummyHash());

                return null;
            }

            return Hash::check($password, $user->password) && $user->is_active ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->getKey() ?: $request->ip());
        });

        Event::subscribe(RecordAuthenticationActivity::class);
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(32));
    }
}
