<?php

namespace App\Modules\Domains;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Domains\Contracts\DomainVerificationProviderInterface;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Domains\Policies\DomainRequestPolicy;
use App\Modules\Domains\Providers\GoogleDomainVerificationProvider;
use App\Modules\Domains\Providers\MockDomainVerificationProvider;
use App\Modules\Domains\Support\DnsResolver;
use App\Modules\Domains\Support\FakeDnsResolver;
use App\Modules\GoogleWorkspace\Providers\GoogleAccessTokenProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MockDomainVerificationProvider::class);

        // Same rule as the Workspace provider: live only when explicitly
        // enabled, and never from the automated test suite.
        $this->app->singleton(DomainVerificationProviderInterface::class, function (Application $app) {
            $live = (config('wcc.google.enabled') || $app->isProduction()) && ! $app->runningUnitTests();

            return $live
                ? new GoogleDomainVerificationProvider($app->make(Http::class), $app->make(GoogleAccessTokenProvider::class))
                : $app->make(MockDomainVerificationProvider::class);
        });

        // Tests never perform real DNS lookups.
        $this->app->singleton(DnsResolver::class, fn (Application $app) => $app->runningUnitTests() ? new FakeDnsResolver : new DnsResolver);
    }

    public function boot(): void
    {
        Gate::policy(DomainRequest::class, DomainRequestPolicy::class);

        // Tenant-scoped bindings: a request or domain outside the caller's
        // organisations is a 404, like one that does not exist.
        Route::bind('domainRequest', function (string $uuid) {
            $user = request()->user() ?? abort(401);

            return DomainRequest::query()->visibleTo($user, Ability::DomainsView)->where('uuid', $uuid)->firstOrFail();
        });

        Route::bind('domain', function (string $uuid) {
            $user = request()->user() ?? abort(401);

            return OrganisationDomain::query()->visibleTo($user, Ability::DomainsView)->where('uuid', $uuid)->firstOrFail();
        });
    }
}
