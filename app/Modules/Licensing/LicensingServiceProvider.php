<?php

namespace App\Modules\Licensing;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Licensing\Http\Controllers\DownloadController;
use App\Modules\Licensing\Http\Controllers\LicenseServer\LicenseServerController;
use App\Modules\Licensing\Models\SoftwareLicense;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LicensingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // License abilities, evaluated in the license's own organisation.
        Gate::define('license.view', fn (User $user, SoftwareLicense $license) => $user->hasAbility(Ability::LicensingView, $license->organisation));
        Gate::define('license.manage', fn (User $user, SoftwareLicense $license) => $user->hasAbility(Ability::LicensingManage, $license->organisation));

        // Tenant-scoped binding: another organisation's license is a 404.
        Route::bind('softwareLicense', function (string $uuid) {
            $user = request()->user() ?? abort(401);
            $license = SoftwareLicense::query()->visibleTo($user, Ability::LicensingView)->where('uuid', $uuid)->firstOrFail();

            abort_unless($user->can('license.view', $license), 404);

            return $license;
        });

        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute(600)->by('storefront:'.sha1((string) $request->bearerToken())));
        RateLimiter::for('license-server', fn (Request $request) => Limit::perMinute(30)->by('license:'.$request->ip()));

        // Single-use release download links (no login: the unguessable token is the credential).
        Route::get('/downloads/{token}', DownloadController::class)->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:30,1')->name('licensing.download');

        // License server for LabelDesk installs (no session, no CSRF).
        Route::prefix('api/license/v1')->middleware(['api', 'throttle:license-server'])->group(function () {
            Route::post('/activate', [LicenseServerController::class, 'activate'])->name('license.activate');
            Route::post('/check-in', [LicenseServerController::class, 'checkIn'])->name('license.check-in');
            Route::post('/deactivate', [LicenseServerController::class, 'deactivate'])->name('license.deactivate');
            Route::get('/public-key', [LicenseServerController::class, 'publicKey'])->name('license.public-key');
        });
    }
}
