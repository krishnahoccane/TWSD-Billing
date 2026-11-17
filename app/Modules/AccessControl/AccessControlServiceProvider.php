<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request / queue job, so memoised
        // permissions never outlive the unit of work that loaded them.
        $this->app->scoped(AccessResolver::class);
    }

    public function boot(): void
    {
        // Every Ability is a Gate ability:
        //   $user->can('accounts.view', $organisation)   organisation check
        //   $user->can('settings.manage')                platform check
        // There is intentionally no Gate::before() super-user bypass.
        foreach (Ability::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $user, ?Organisation $organisation = null) => $this->app
                    ->make(AccessResolver::class)
                    ->can($user, $ability, $organisation),
            );
        }
    }
}
