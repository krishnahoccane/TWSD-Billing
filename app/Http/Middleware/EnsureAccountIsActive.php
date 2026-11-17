<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session (or revokes the API token) of a user who was
 * deactivated after signing in.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            $token = $user->currentAccessToken();

            if ($token !== null && method_exists($token, 'delete')) {
                $token->delete();
            }

            abort(403, 'This account has been deactivated.');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'This account has been deactivated. Contact your administrator.',
        ]);
    }
}
