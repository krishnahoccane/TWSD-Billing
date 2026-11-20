<?php

namespace App\Modules\Licensing\Services;

use App\Modules\Licensing\Jobs\DeliverStorefrontWebhookJob;
use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Models\StorefrontWebhookEvent;
use App\Modules\Licensing\Support\ApiPresenter;
use Throwable;

/**
 * Signed webhooks to the storefront a license belongs to. Stored first,
 * then delivered by a queued job (at-least-once, retried for ~3 days).
 * A webhook problem never interrupts the change that caused it.
 */
class StorefrontWebhooks
{
    public function licenseEvent(SoftwareLicense $license, string $type): void
    {
        $this->queue($license->storefront_id ? Storefront::query()->find($license->storefront_id) : null, $type,
            $license, fn () => ['license' => ApiPresenter::license($license->fresh(['plan', 'product', 'activations']))]);
    }

    public function activationEvent(SoftwareActivation $activation, string $type): void
    {
        $license = $activation->license;
        $this->queue($license?->storefront_id ? Storefront::query()->find($license->storefront_id) : null, $type,
            $license, fn () => ['activation' => ApiPresenter::activation($activation)]);
    }

    /**
     * Sign a body for delivery: header "WCC-Signature: t=<unix>,v1=<hex>",
     * v1 = HMAC-SHA256(secret, "<t>.<raw body>").
     */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @param  callable(): array<string, mixed>  $data */
    private function queue(?Storefront $storefront, string $type, ?SoftwareLicense $license, callable $data): void
    {
        if ($storefront === null || ! $storefront->is_active || blank($storefront->webhook_url) || blank($storefront->webhook_secret)) {
            return;
        }

        try {
            $event = StorefrontWebhookEvent::query()->create([
                'storefront_id' => $storefront->getKey(),
                'type' => $type,
                'payload' => [],
                'status' => 'pending',
            ]);

            $event->forceFill(['payload' => [
                'id' => $event->publicId(),
                'type' => $type,
                'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'storefront' => $storefront->code,
                'customer_id' => $license?->organisation ? ApiPresenter::customerId($license->organisation) : null,
                'data' => $data(),
            ]])->save();

            DeliverStorefrontWebhookJob::dispatch($event->getKey())->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
