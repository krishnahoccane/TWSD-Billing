<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Enums\OrganisationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Payment reminders for unpaid invoices (run daily):
 *
 *   due − 2  reminder_1   Reminder 1
 *   due − 1  reminder_2   Reminder 2
 *   due      final_1      Final reminder
 *   due + 1  final_2      Final reminder
 *   due + 2  final_3      Final reminder: mailboxes are due for automatic suspension
 *
 * Nothing is scheduled in advance: each day only invoices that are still
 * open with a balance get a reminder, so a payment cancels every later
 * reminder automatically. invoice_reminders (unique per invoice and stage)
 * makes sure a reminder is never sent twice.
 */
class PaymentReminderService
{
    /** Days until the due date => stage. */
    public const STAGES = [2 => 'reminder_1', 1 => 'reminder_2', 0 => 'final_1', -1 => 'final_2', -2 => 'final_3'];

    public function __construct(private readonly NotificationEvents $notify) {}

    /** @return int reminders sent */
    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay();
        $tolerance = (int) config('wcc.billing.rounding_tolerance_minor', 100);
        $sent = 0;

        $invoices = Invoice::query()
            ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
            ->where('balance_minor', '>', $tolerance)          // a few paise of rounding never trigger reminders
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today->subDays(2)->toDateString())
            ->whereDate('due_date', '<=', $today->addDays(2)->toDateString())
            ->with('organisation')
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            if ($invoice->organisation === null || $invoice->organisation->status === OrganisationStatus::Inactive) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($invoice->due_date->toDateString(), config('wcc.billing.timezone')), false);
            $stage = self::STAGES[$daysLeft] ?? null;

            if ($stage === null || ! $this->claim($invoice, $stage)) {
                continue;
            }

            $this->notify->paymentReminder($invoice, $stage);
            $sent++;
        }

        return $sent;
    }

    /** Records the stage first; false when it was already sent (or another worker got there first). */
    private function claim(Invoice $invoice, string $stage): bool
    {
        try {
            DB::table('invoice_reminders')->insert(['invoice_id' => $invoice->getKey(), 'stage' => $stage, 'sent_at' => now()]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}
