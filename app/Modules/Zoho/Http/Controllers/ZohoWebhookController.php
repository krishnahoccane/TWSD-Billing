<?php

namespace App\Modules\Zoho\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Zoho\Jobs\ProcessZohoWebhookJob;
use App\Modules\Zoho\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/webhooks/zoho — not token-authenticated; verified instead.
 *
 * Accepted when EITHER:
 *  - header X-WCC-Webhook-Token equals ZOHO_WEBHOOK_SECRET (configure it as a
 *    custom header on the Zoho webhook), or
 *  - header X-Zoho-Webhook-Signature is a valid HMAC-SHA256 (base64) made
 *    with that secret. Zoho's documentation describes the signed string
 *    inconsistently, so the documented variants are all accepted; each
 *    still requires the secret.
 *
 * Even a verified webhook is only a trigger: the payment is re-read from
 * Zoho's API before anything is recorded. Duplicate deliveries are ignored.
 */
class ZohoWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('wcc.zoho.webhook_secret');

        if ($secret === '') {
            return response()->json(['message' => 'Webhooks are not configured.'], 503);
        }

        $raw = $request->getContent();

        if (! $this->verified($request, $secret, $raw)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : $request->except([]);

        $paymentId = $payload['payment']['payment_id'] ?? $payload['customerpayment']['payment_id'] ?? $payload['payment_id'] ?? null;

        try {
            $event = WebhookEvent::query()->create([
                'provider' => 'zoho',
                // The same delivery twice has the same body.
                'event_id' => hash('sha256', $raw),
                'event_type' => $paymentId !== null ? 'customer_payment' : 'unknown',
                // Only what is needed to act; never the whole body.
                'payload' => $paymentId !== null ? ['payment_id' => (string) $paymentId] : null,
                'status' => 'received',
            ]);
        } catch (QueryException) {
            return response()->json(['message' => 'Already received.']);
        }

        ProcessZohoWebhookJob::dispatch($event->getKey());

        return response()->json(['message' => 'Received.']);
    }

    private function verified(Request $request, string $secret, string $raw): bool
    {
        $token = (string) $request->header('X-WCC-Webhook-Token', '');

        if ($token !== '' && hash_equals($secret, $token)) {
            return true;
        }

        $signature = (string) $request->header('X-Zoho-Webhook-Signature', '');

        if ($signature === '') {
            return false;
        }

        $pairs = $request->query();
        $body = json_decode($raw, true);

        if (! is_array($body)) {
            parse_str($raw, $body);
        }

        foreach ((array) $body as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $pairs[$key] = $value;
            }
        }

        ksort($pairs);
        $sorted = '';

        foreach ($pairs as $key => $value) {
            $sorted .= $key.$value;
        }

        $queryOnly = $request->query();
        ksort($queryOnly);
        $queryString = '';

        foreach ($queryOnly as $key => $value) {
            $queryString .= $key.(is_scalar($value) ? $value : '');
        }

        foreach ([$sorted, $queryString.$raw, $raw] as $candidate) {
            if (hash_equals(base64_encode(hash_hmac('sha256', $candidate, $secret, true)), $signature)) {
                return true;
            }
        }

        return false;
    }
}
