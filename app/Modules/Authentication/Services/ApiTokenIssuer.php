<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\NewAccessToken;

/**
 * Password (+ TOTP) login for API clients. Applies the same rules as the
 * web login: deactivated users are indistinguishable from bad credentials,
 * and privileged users cannot obtain a token without two-factor.
 */
class ApiTokenIssuer
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{0: User, 1: NewAccessToken}
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function issue(string $email, string $password, ?string $code, ?string $deviceName): array
    {
        $user = User::query()->where('email', Str::lower($email))->first();

        if ($user === null || ! Hash::check($password, $user->password) || ! $user->is_active) {
            $this->audit->record(
                action: 'auth.api_login_failed',
                resource: $user,
                new: ['email' => Str::limit($email, 191)],
                status: AuditLogger::STATUS_FAILURE,
            );

            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

            if ($code === null || ! $this->twoFactor->verify($secret, $code)) {
                $this->audit->record(
                    action: 'auth.api_login_failed',
                    resource: $user,
                    new: ['email' => $user->email, 'reason' => 'two_factor'],
                    status: AuditLogger::STATUS_FAILURE,
                    actor: $user,
                );

                throw ValidationException::withMessages([
                    'code' => ['A valid two-factor authentication code is required.'],
                ]);
            }
        } elseif (config('wcc.security.enforce_two_factor') && $user->isPrivileged()) {
            throw new AuthorizationException(
                'Two-factor authentication must be enabled for this account. Enrol through the web application.'
            );
        }

        $token = $user->createToken(
            name: $deviceName ?: 'api',
            expiresAt: now()->addMinutes(config('wcc.security.api_token_ttl_minutes')),
        );

        $this->audit->record('auth.api_login', $user, actor: $user);

        return [$user, $token];
    }
}
