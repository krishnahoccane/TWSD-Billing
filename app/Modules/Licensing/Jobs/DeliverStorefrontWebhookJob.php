<?php

namespace App\Modules\Licensing\Jobs;

use App\Modules\Licensing\Models\StorefrontWebhookEvent;
use App\Modules\Licensing\Services\StorefrontWebhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * POSTs one webhook to the storefront. Retries 1 m, 5 m, 30 m, 2 h, 6 h,
 * 24 h, 24 h; the storefront de-duplicates on the event id.
 */
class DeliverStorefrontWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(public int $eventId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600, 86400, 86400];
    }

    public function handle(Http $http): void
    {
        $event = StorefrontWebhookEvent::query()->with('storefront')->find($this->eventId);

        if ($event === null || $event->status === 'delivered' || $event->storefront === null || blank($event->storefront->webhook_url)) {
            return;
        }

        $body = (string) json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        try {
            $response = $http->withBody($body, 'application/json')
                ->withHeaders(['WCC-Signature' => StorefrontWebhooks::signature((string) $event->storefront->webhook_secret, $body, time()),
                    'User-Agent' => 'WCC-Webhooks/1.0'])
                ->timeout(10)->connectTimeout(5)
                ->post((string) $event->storefront->webhook_url);

            if (! $response->successful()) {
                throw new RuntimeException('Storefront answered HTTP '.$response->status().'.');
            }
        } catch (Throwable $e) {
            $event->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e;
        }

        $event->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null])->save();
    }

    public function failed(?Throwable $error): void
    {
        StorefrontWebhookEvent::query()->whereKey($this->eventId)->where('status', '!=', 'delivered')
            ->update(['status' => 'failed', 'last_error' => mb_substr((string) $error?->getMessage(), 0, 1000)]);
    }
}
