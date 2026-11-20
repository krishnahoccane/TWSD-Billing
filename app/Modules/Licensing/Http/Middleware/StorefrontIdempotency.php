<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Exceptions\LicensingException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * POSTs that create something need "Idempotency-Key: <uuid>". A repeat with
 * the same key and body returns the stored response (kept 24 h); the same
 * key with a different body is refused.
 */
class StorefrontIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9_\-:.]+$/', $key)) {
            throw new LicensingException('idempotency_key_required', 'Send an Idempotency-Key header (a UUID) with this request.', 400);
        }

        $storefront = $request->attributes->get('storefront');
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        DB::table('storefront_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();

        $stored = DB::table('storefront_idempotency_keys')->where('storefront_id', $storefront->getKey())->where('key', $key)->first();

        if ($stored !== null) {
            if ($stored->request_hash !== $hash) {
                throw new LicensingException('idempotency_key_reused', 'This Idempotency-Key was used for a different request.', 422);
            }

            return new JsonResponse(json_decode($stored->response, true), $stored->status_code, ['Idempotent-Replayed' => 'true']);
        }

        $request->attributes->set('idempotency_key', $key);
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            try {
                DB::table('storefront_idempotency_keys')->insert([
                    'storefront_id' => $storefront->getKey(),
                    'key' => $key,
                    'request_hash' => $hash,
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(),
                    'created_at' => now(),
                ]);
            } catch (QueryException) {
                // A concurrent identical request stored it first.
            }
        }

        return $response;
    }
}
