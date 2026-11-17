<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: one "invoice overdue" notification per invoice, the first day it
 * is past its due date with a balance outstanding.
 */
class SendOverdueInvoiceRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(NotificationEvents $notify): void
    {
        $today = CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString();

        Invoice::query()
            ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
            ->where('balance_minor', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($notify) {
                $notify->invoiceOverdue($invoice);
                $invoice->forceFill(['overdue_notified_at' => now()])->save();
            });
    }
}
