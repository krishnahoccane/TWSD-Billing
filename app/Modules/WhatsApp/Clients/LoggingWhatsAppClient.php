<?php

namespace App\Modules\WhatsApp\Clients;

use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\Zoho\Models\IntegrationSyncLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * One integration_sync_logs row per message: the number (masked), outcome
 * and duration. The message text and the keys are never logged.
 */
class LoggingWhatsAppClient implements WhatsAppClientInterface
{
    public function __construct(private readonly WhatsAppClientInterface $inner) {}

    public function inner(): WhatsAppClientInterface
    {
        return $this->inner;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function send(string $to, string $message): void
    {
        $started = hrtime(true);
        $error = null;

        try {
            $this->inner->send($to, $message);
        } catch (Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            try {
                IntegrationSyncLog::query()->create([
                    'provider' => $this->inner->name(),
                    'operation' => 'messages.create',
                    'target' => strlen($to) > 6 ? substr($to, 0, 2).str_repeat('*', strlen($to) - 6).substr($to, -4) : $to,
                    'status' => $error === null ? 'success' : 'failure',
                    'error' => $error === null ? null : Str::limit($error->getMessage(), 2000),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                    'request_id' => Context::get('request_id'),
                ]);
            } catch (Throwable $loggingFailure) {
                report($loggingFailure);
            }
        }
    }
}
