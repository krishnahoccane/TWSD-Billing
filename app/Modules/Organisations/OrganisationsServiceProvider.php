<?php

namespace App\Modules\Organisations;

use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Models\OrganisationContact;
use App\Modules\Organisations\Policies\OrganisationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrganisationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Organisation::class, OrganisationPolicy::class);

        // First isolation layer: {organisation} resolves only inside the
        // caller's own scope. Another tenant's uuid is indistinguishable
        // from one that does not exist (404). Runs after authentication.
        Route::bind('organisation', function (string $uuid) {
            $user = request()->user() ?? abort(401);

            return Organisation::query()->visibleTo($user)->where('uuid', $uuid)->firstOrFail();
        });

        // {contact} must belong to the {organisation} in the same URL.
        Route::bind('contact', function (string $uuid, $route) {
            $organisation = $route->parameter('organisation');

            abort_unless($organisation instanceof Organisation, 404);

            return OrganisationContact::query()
                ->where('organisation_id', $organisation->getKey())
                ->where('uuid', $uuid)
                ->firstOrFail();
        });
    }
}
