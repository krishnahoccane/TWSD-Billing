<?php

namespace App\Modules\WhatsApp;

use App\Modules\WhatsApp\Clients\LoggingWhatsAppClient;
use App\Modules\WhatsApp\Clients\MockWhatsAppClient;
use App\Modules\WhatsApp\Clients\WhatsAppClient;
use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\WhatsApp\Services\WhatsAppConnection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MockWhatsAppClient::class);

        // Real messages only with WHATSAPP_ENABLED=true or in production,
        // and never in the test suite.
        $this->app->singleton(WhatsAppClientInterface::class, function (Application $app) {
            $live = (config('wcc.whatsapp.enabled') || $app->isProduction()) && ! $app->runningUnitTests();

            return new LoggingWhatsAppClient($live
                ? new WhatsAppClient($app->make(Http::class), $app->make(WhatsAppConnection::class))
                : $app->make(MockWhatsAppClient::class));
        });
    }
}
