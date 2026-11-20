<?php

namespace App\Modules\Licensing\Support;

use App\Modules\Licensing\Exceptions\LicensingException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error envelope of the storefront and license-server APIs:
 *   { "error": { "code": "domain_limit_reached", "message": "…", "field": null }, "request_id": "…" }
 */
final class ApiErrors
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/v1/storefront/*', 'api/license/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }

        [$status, $code, $message, $field, $headers] = match (true) {
            $e instanceof LicensingException => [$e->status, $e->errorCode, $e->getMessage(), $e->field, []],
            $e instanceof ValidationException => [422, 'validation_failed', $e->validator->errors()->first(),
                array_key_first($e->validator->errors()->messages()), []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests.', null, $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), match ($e->getStatusCode()) {
                401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', default => 'http_error',
            }, $e->getStatusCode() === 404 ? 'Not found.' : ($e->getMessage() ?: 'Request refused.'), null, $e->getHeaders()],
            default => [500, 'server_error', 'Server error.', null, []],
        };

        if ($status >= 500) {
            report($e);
        }

        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message, 'field' => $field],
            'request_id' => Context::get('request_id'),
        ], $status, $headers);
    }
}
