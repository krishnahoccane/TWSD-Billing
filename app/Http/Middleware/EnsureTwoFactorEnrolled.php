<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged users (holders of any platform-level role) cannot use the
 * application until TOTP two-factor authentication is confirmed.
 * The security page and Fortify's own endpoints sit outside this middleware.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! config('wcc.security.enforce_two_factor')
            || $user->hasTwoFactorEnabled()
            || ! $user->isPrivileged()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, 'Two-factor authentication must be enabled for this account. Enrol through the web application.');
        }

        return redirect()->route('account.security')
            ->with('warning', 'Your role requires two-factor authentication. Set it up to continue.');
    }
}
