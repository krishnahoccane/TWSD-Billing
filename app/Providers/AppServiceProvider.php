<?php

namespace App\Providers;

use App\Modules\Installer\Support\InstallState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Surface N+1 queries, silently discarded attributes and missing
        // attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Timestamps are stored in UTC and shown in APP_TIMEZONE.
        $display = function (): CarbonInterface {
            /** @var CarbonInterface $this */
            $zone = (string) config('app.display_timezone') ?: 'UTC';

            return $this->copy()->setTimezone(in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
        };
        Carbon::macro('inDisplayTimezone', $display);
        CarbonImmutable::macro('inDisplayTimezone', $display);

        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        Paginator::defaultView('components.ui.pagination');

        // Not while installing: the installer may run before HTTPS is set up.
        if ($this->app->isProduction() && InstallState::installed()) {
            URL::forceScheme('https');
        }
    }
}
