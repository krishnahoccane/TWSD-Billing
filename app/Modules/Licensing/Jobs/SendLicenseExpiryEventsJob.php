<?php

namespace App\Modules\Licensing\Jobs;

use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Services\LicenseService;
use App\Modules\Licensing\Services\StorefrontWebhooks;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: "license.expiring" 30, 14 and 3 days before an annual license
 * expires, and "license.expired" once it has. Each notice is sent once
 * (recorded in the license timeline); the storefront sends the e-mails.
 */
class SendLicenseExpiryEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public const NOTICE_DAYS = [30, 14, 3];

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(LicenseService $licenses, StorefrontWebhooks $webhooks): void
    {
        SoftwareLicense::query()->where('status', SoftwareLicense::ACTIVE)->where('billing_type', 'annual')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(max(self::NOTICE_DAYS)))
            ->where('expires_at', '>', now()->subDays(7))
            ->with(['organisation', 'plan', 'product'])->orderBy('id')
            ->each(function (SoftwareLicense $license) use ($licenses, $webhooks) {
                $daysLeft = (int) ceil(now()->diffInHours($license->expires_at, false) / 24);
                $notice = $daysLeft <= 0 ? 'expired' : collect(self::NOTICE_DAYS)->sort()->first(fn (int $days) => $daysLeft <= $days);

                if ($notice === null) {
                    return;
                }

                $event = $notice === 'expired' ? 'expired' : 'expiring_'.$notice.'_days';

                if ($license->events()->where('event', $event)->where('created_at', '>=', $license->expires_at->copy()->subDays(40))->exists()) {
                    return;
                }

                $licenses->event($license, $event, ['expires_at' => $license->expires_at->toIso8601String()], 'system');
                $webhooks->licenseEvent($license, $notice === 'expired' ? 'license.expired' : 'license.expiring');
            });
    }
}
