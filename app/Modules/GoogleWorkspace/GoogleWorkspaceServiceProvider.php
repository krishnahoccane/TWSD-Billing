<?php

namespace App\Modules\GoogleWorkspace;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Policies\GoogleAccountPolicy;
use App\Modules\GoogleWorkspace\Providers\GoogleAccessTokenProvider;
use App\Modules\GoogleWorkspace\Providers\GoogleWorkspaceProvider;
use App\Modules\GoogleWorkspace\Providers\LoggingWorkspaceProvider;
use App\Modules\GoogleWorkspace\Providers\MockWorkspaceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GoogleWorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In tests the mock is purely in memory; locally it keeps its
        // state in a file so data survives between requests.
        $this->app->singleton(MockWorkspaceProvider::class, fn (Application $app) => new MockWorkspaceProvider(
            $app->runningUnitTests() ? null : storage_path('app/private/mock-workspace.json'),
        ));

        $this->app->singleton(GoogleAccessTokenProvider::class, fn (Application $app) => new GoogleAccessTokenProvider(
            $app->make(Http::class),
            $app->make('cache.store'),
            [
                'service_account_json' => config('wcc.google.service_account_json'),
                'admin_email' => config('wcc.google.admin_email'),
                'delegation_enabled' => (bool) config('wcc.google.delegation_enabled'),
            ],
        ));

        $this->app->singleton(WorkspaceProviderInterface::class, function (Application $app) {
            // Live Google calls happen ONLY when explicitly enabled, and
            // never from the automated test suite.
            // In production the real client is always used: if it is not
            // configured, operations fail visibly instead of being simulated.
            $live = (config('wcc.google.enabled') || $app->isProduction()) && ! $app->runningUnitTests();

            $provider = $live
                ? new GoogleWorkspaceProvider(
                    $app->make(Http::class),
                    $app->make(GoogleAccessTokenProvider::class),
                    (string) config('wcc.google.customer_id', 'my_customer'),
                )
                : $app->make(MockWorkspaceProvider::class);

            return new LoggingWorkspaceProvider($provider);
        });
    }

    public function boot(): void
    {
        Gate::policy(GoogleAccount::class, GoogleAccountPolicy::class);

        // Tenant-scoped binding: an account outside the caller's scope is a
        // 404, exactly like one that does not exist. Unassigned accounts are
        // only inside the scope of users with global access.
        Route::bind('account', function (string $uuid) {
            $user = request()->user() ?? abort(401);

            return GoogleAccount::query()
                ->visibleTo($user, Ability::AccountsView)
                ->where('uuid', $uuid)
                ->firstOrFail();
        });

        Route::bind('deletionRequest', function (string $uuid, $route) {
            $account = $route->parameter('account');

            abort_unless($account instanceof GoogleAccount, 404);

            return AccountDeletionRequest::query()
                ->where('google_account_id', $account->getKey())
                ->where('uuid', $uuid)
                ->firstOrFail();
        });
    }
}
