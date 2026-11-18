<?php

namespace App\Modules\WhatsApp\Contracts;

use App\Modules\WhatsApp\Exceptions\WhatsAppException;

/**
 * Sends one WhatsApp text message. Implementations: WhatsAppClient (the
 * WhatsApp notifier API) and MockWhatsAppClient (local development, tests).
 */
interface WhatsAppClientInterface
{
    /** "whatsapp" or "mock-whatsapp", written to integration_sync_logs. */
    public function name(): string;

    /**
     * @param  string  $to  international format, digits only (no "+")
     *
     * @throws WhatsAppException
     */
    public function send(string $to, string $message): void;
}
