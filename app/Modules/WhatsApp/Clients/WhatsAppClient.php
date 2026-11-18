<?php

namespace App\Modules\WhatsApp\Clients;

use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\WhatsApp\Exceptions\WhatsAppException;
use App\Modules\WhatsApp\Services\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * The WhatsApp notifier API:
 *   POST {endpoint}  multipart form: appkey, authkey, to, message
 * The keys come from the encrypted integration settings; they are never
 * logged, audited or shown.
 */
class WhatsAppClient implements WhatsAppClientInterface
{
    public function __construct(
        private readonly Http $http,
        private readonly WhatsAppConnection $connection,
    ) {}

    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(string $to, string $message): void
    {
        $keys = $this->connection->keys();

        if ($keys === null) {
            throw new WhatsAppException('The WhatsApp app key and auth key are not set.');
        }

        try {
            $response = $this->http->asMultipart()->acceptJson()->timeout(20)->connectTimeout(10)
                ->post((string) config('wcc.whatsapp.endpoint'), [
                    ['name' => 'appkey', 'contents' => $keys['appkey']],
                    ['name' => 'authkey', 'contents' => $keys['authkey']],
                    ['name' => 'to', 'contents' => $to],
                    ['name' => 'message', 'contents' => $message],
                ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('Could not reach the WhatsApp API: '.$e->getMessage(), retryable: true);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new WhatsAppException('WhatsApp API error '.$response->status().'.', retryable: true);
        }

        $body = $response->json();
        $status = is_array($body) ? ($body['status'] ?? null) : null;
        $refused = is_array($body) && ($status === false || ($body['success'] ?? null) === false
            || (is_string($status) && in_array(strtolower($status), ['error', 'failed', 'fail', 'false'], true)));

        if ($response->failed() || $refused) {
            $detail = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';

            throw new WhatsAppException('WhatsApp API refused the message (HTTP '.$response->status().')'
                .($detail !== '' ? ': '.mb_substr($detail, 0, 200) : '.'));
        }
    }
}
