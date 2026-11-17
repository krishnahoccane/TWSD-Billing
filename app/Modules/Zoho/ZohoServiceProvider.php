<?php

namespace App\Modules\Zoho;

use App\Modules\Zoho\Clients\LoggingZohoBooksClient;
use App\Modules\Zoho\Clients\MockZohoBooksClient;
use App\Modules\Zoho\Clients\ZohoBooksClient;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Services\ZohoConnection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

class ZohoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MockZohoBooksClient::class, fn (Application $app) => new MockZohoBooksClient(
            $app->runningUnitTests() ? null : storage_path('app/private/mock-zoho-books.json'),
        ));

        // Real Zoho Books only when ZOHO_ENABLED=true (or in production, where
        // a missing connection must fail visibly), and never in the test suite.
        $this->app->singleton(ZohoBooksClientInterface::class, function (Application $app) {
            $live = (config('wcc.zoho.enabled') || $app->isProduction()) && ! $app->runningUnitTests();

            return new LoggingZohoBooksClient($live
                ? new ZohoBooksClient($app->make(Http::class), $app->make(ZohoConnection::class))
                : $app->make(MockZohoBooksClient::class));
        });
    }
}
