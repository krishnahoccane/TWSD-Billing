<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Zoho\Models\WebhookEvent;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Support\Str;
use Throwable;

/** Handles a stored Zoho webhook: re-reads the payment from Zoho's API and records it. */
class ProcessZohoWebhookJob extends ZohoJob
{
    protected function run(ZohoSync $sync): void
    {
        $event = WebhookEvent::query()->find($this->recordId);

        if ($event === null || $event->status === 'processed') {
            return;
        }

        $result = $sync->processWebhook($event);

        $event->forceFill([
            'status' => str_starts_with($result, 'ignored') ? 'ignored' : 'processed',
            'error' => str_starts_with($result, 'ignored') ? $result : null,
            'processed_at' => now(),
        ])->save();
    }

    protected function recordFailure(ZohoSync $sync, Throwable $error): void
    {
        WebhookEvent::query()->whereKey($this->recordId)->update([
            'status' => 'failed',
            'error' => Str::limit(class_basename($error).': '.$error->getMessage(), 1000),
        ]);
    }
}
