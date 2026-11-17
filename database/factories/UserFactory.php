<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->afterMaking(fn (User $user) => $user->is_active = false);
    }

    /**
     * A user with confirmed TOTP two-factor authentication.
     */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->afterMaking(function (User $user) use ($secret) {
            $user->forceFill([
                'two_factor_secret' => encrypt($secret ?? 'JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
                'two_factor_confirmed_at' => now(),
            ]);
        });
    }
}
