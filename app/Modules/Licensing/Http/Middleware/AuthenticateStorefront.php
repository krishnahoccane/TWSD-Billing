<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Services\StorefrontService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront API: "Authorization: Bearer sk_live_…". The storefront is
 * attached to the request ($request->attributes->get('storefront')); every
 * query in the storefront controllers is limited to its own products and
 * customers.
 */
class AuthenticateStorefront
{
    public function __construct(private readonly StorefrontService $storefronts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $storefront = filled($request->bearerToken()) ? $this->storefronts->authenticate((string) $request->bearerToken()) : null;

        if ($storefront === null) {
            throw new LicensingException('unauthenticated', 'Invalid or missing API key.', 401);
        }

        if ($storefront->last_used_at === null || $storefront->last_used_at->lt(now()->subMinutes(5))) {
            $storefront->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('storefront', $storefront);

        return $next($request);
    }
}
