<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers, including a Content-Security-Policy with a per-request
 * script nonce (used by Vite, Livewire and the one inline theme script).
 *
 * 'unsafe-eval' is required by Alpine.js as bundled with Livewire 3; inline
 * event handlers are not allowed (the views use Alpine instead), and
 * framing, plugins and foreign form targets are blocked.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // The Vite dev server (npm run dev) serves scripts and a websocket from another origin.
        $hot = Vite::isRunningHot() ? trim((string) @file_get_contents(public_path('hot'))) : '';
        $hotWs = $hot !== '' ? preg_replace('#^http#', 'ws', $hot) : '';

        return implode('; ', [
            "default-src 'self'",
            trim("script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' {$hot}"),
            trim("style-src 'self' 'unsafe-inline' {$hot}"),
            "img-src 'self' data:",
            "font-src 'self' data:",
            trim("connect-src 'self' {$hot} {$hotWs}"),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
