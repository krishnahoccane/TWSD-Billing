<?php

use App\Modules\Billing\Jobs\EnforcePaymentSuspensionsJob;
use App\Modules\Billing\Jobs\GenerateBillingCycleJob;
use App\Modules\Billing\Jobs\SendPaymentRemindersJob;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleDomainsJob;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleUsersJob;
use App\Modules\GoogleWorkspace\Jobs\ReconcileGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Jobs\SyncGoogleAccountsJob;
use App\Modules\Licensing\Jobs\SendLicenseExpiryEventsJob;
use App\Modules\System\Http\Controllers\SystemController;
use App\Modules\Zoho\Jobs\ReconcileZohoPaymentJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Google Workspace synchronisation. Requires `php artisan schedule:run`
| every minute (cron) and a queue worker on the `google` queue.
|
| The Directory API has no cheap "changed since" listing, and the whole
| customer (~600 users) is two requests, so the 15-minute job lists all
| users. The daily job additionally flags accounts Google no longer returns.
*/

Schedule::job(new SyncGoogleAccountsJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ReconcileGoogleAccountsJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ImportGoogleDomainsJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ImportGoogleUsersJob)->dailyAt('02:30')->withoutOverlapping();

/*
| Automatic billing in arrears: on the 1st, invoices for the previous
| calendar month are generated and issued (and sent to Zoho Books).
| Runs started by hand for older months create drafts instead.
*/
Schedule::job(new GenerateBillingCycleJob(autoIssue: true))->monthlyOn(1, '03:00')->timezone(config('wcc.billing.timezone'))->withoutOverlapping();

// Zoho Books: fallback for webhooks — send pending invoices, push offline
// payments, record Zoho payments, report differences. Every 30 minutes.
Schedule::job(new ReconcileZohoPaymentJob)->everyThirtyMinutes()->withoutOverlapping();

// Payment reminders: 2 and 1 days before the due date, on it, and 1 and 2
// days after. A paid invoice gets no further reminders.
Schedule::job(new SendPaymentRemindersJob)->dailyAt('09:00')->timezone(config('wcc.billing.timezone'))->withoutOverlapping();

// Non-payment: suspend the organisation's active mailboxes once the grace
// days after the due date have passed; reactivate them once paid. Hourly
// (payments also trigger it straight away).
Schedule::job(new EnforcePaymentSuspensionsJob)->hourlyAt(5)->withoutOverlapping();

// Software licensing: license.expiring (30/14/3 days) and license.expired webhooks to the storefront.
Schedule::job(new SendLicenseExpiryEventsJob)->dailyAt('08:00')->timezone(config('wcc.billing.timezone'))->withoutOverlapping();

// Heartbeat shown on System → Health, so a missing cron entry is noticed.
Schedule::call(fn () => Cache::put(SystemController::HEARTBEAT_KEY, now()->toIso8601String(), 3600))->everyMinute()->name('scheduler-heartbeat');
