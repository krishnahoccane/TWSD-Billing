<?php

namespace App\Modules\WhatsApp\Clients;

use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;

/**
 * Sends nothing. Keeps the messages in memory so tests (and local demos)
 * can see what would have been sent.
 */
class MockWhatsAppClient implements WhatsAppClientInterface
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function name(): string
    {
        return 'mock-whatsapp';
    }

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /** @return list<string> messages sent to one number */
    public function messagesTo(string $to): array
    {
        return array_values(array_map(fn (array $row) => $row['message'],
            array_filter($this->sent, fn (array $row) => $row['to'] === $to)));
    }
}
