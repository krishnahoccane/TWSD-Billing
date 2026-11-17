<?php

namespace App\Modules\Installer\Http\Middleware;

use App\Modules\Installer\Support\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Not installed: every page goes to the installer and the API answers 503.
 * Installed: the installer no longer exists (404) — it can never be re-run
 * from the browser, even if .env is later lost; the lock file must be removed
 * on the server first.
 */
class InstallationGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $installerPath = $request->is('install', 'install/*');

        if (InstallState::installed()) {
            abort_if($installerPath, 404);

            return $next($request);
        }

        if ($installerPath || $request->is('up')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'The application has not been installed yet.'], 503);
        }

        return redirect('/install');
    }
}
