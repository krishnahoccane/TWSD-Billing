<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\WhatsApp\Exceptions\WhatsAppException;
use App\Modules\WhatsApp\Services\WhatsAppConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One WhatsApp message to one number. Retries timeouts and server errors
 * with backoff; a refused message fails at once and stays in failed_jobs
 * and the integration log. Nothing is sent once the integration has been
 * switched off.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 40;

    public function __construct(public string $to, public string $message)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(WhatsAppClientInterface $client, WhatsAppConnection $connection): void
    {
        if (! $connection->settings()['enabled'] || ! $connection->hasKeys()) {
            return;
        }

        try {
            $client->send($this->to, $this->message);
        } catch (WhatsAppException $e) {
            if ($e->retryable) {
                throw $e;
            }

            $this->fail($e);
        }
    }
}
