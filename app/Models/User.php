<?php

namespace App\Models;

use App\Modules\AccessControl\Concerns\HasAccessControl;
use App\Support\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasAccessControl, HasApiTokens, HasFactory, HasUuid, Notifiable, TwoFactorAuthenticatable;

    /**
     * is_active and role assignments are deliberately not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    // Explicit defaults so a freshly created (not yet re-read) model can be
    // inspected under Model::shouldBeStrict().
    protected $attributes = [
        'is_active' => true,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'notification_preferences' => null,
        'phone' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
