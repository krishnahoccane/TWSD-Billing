<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the record of which days a mailbox is chargeable, and to which
 * organisation. Called whenever a mailbox becomes billable (created,
 * imported, assigned, restored) or stops (soft-deleted, reassigned).
 * Suspension does not close a period: suspended mailboxes stay billable.
 */
class BillingPeriodTracker
{
    /** Start charging from $from (inclusive). No-op if already open for this organisation. */
    public function open(GoogleAccount $account, CarbonInterface|string|null $from = null): void
    {
        if ($account->organisation_id === null) {
            return;
        }

        $from = $this->date($from);

        $open = AccountBillingPeriod::query()
            ->where('google_account_id', $account->getKey())
            ->whereNull('ends_on')
            ->first();

        if ($open !== null) {
            if ($open->organisation_id === $account->organisation_id) {
                return;
            }

            // Moved to another organisation: the old one is charged up to the day before.
            $this->closeRow($open, $from->subDay());
        }

        AccountBillingPeriod::query()->create([
            'google_account_id' => $account->getKey(),
            'organisation_id' => $account->organisation_id,
            'starts_on' => $from->toDateString(),
        ]);
    }

    /**
     * Stop charging: $effectiveDate is the first day that is NOT charged
     * (the soft-deletion effective date).
     */
    public function close(GoogleAccount $account, CarbonInterface|string|null $effectiveDate = null): void
    {
        $lastDay = $this->date($effectiveDate)->subDay();

        AccountBillingPeriod::query()
            ->where('google_account_id', $account->getKey())
            ->whereNull('ends_on')
            ->get()
            ->each(fn (AccountBillingPeriod $period) => $this->closeRow($period, $lastDay));
    }

    /**
     * Correct the first billable day of the mailbox's current (open) period.
     * Returns the previous start date. Callers authorise, audit and wrap
     * this in a transaction.
     *
     * Refused when the new date would overlap the mailbox's earlier period
     * (another organisation would be charged twice), is in the future or
     * before Google created the mailbox, or when an issued invoice already
     * covers any day between the old and the new date: issued invoices are
     * never recalculated (void it, or use an adjustment).
     *
     * @throws ValidationException
     */
    public function moveStart(GoogleAccount $account, CarbonImmutable $newStart): CarbonImmutable
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['billable_from' => $message])->errorBag('billing');

        $open = AccountBillingPeriod::query()
            ->where('google_account_id', $account->getKey())
            ->whereNull('ends_on')
            ->lockForUpdate()
            ->first();

        if ($open === null || $open->organisation_id !== $account->organisation_id) {
            $fail('This mailbox is not being billed to its organisation at the moment, so there is no billing start to change.');
        }

        $previous = CarbonImmutable::parse($open->starts_on->toDateString());
        $today = $this->date(null);

        if ($newStart->toDateString() > $today->toDateString()) {
            $fail('The billing start cannot be in the future.');
        }

        $created = $account->google_created_at === null ? null
            : CarbonImmutable::parse($account->google_created_at)->setTimezone(config('wcc.billing.timezone'))->startOfDay();

        if ($created !== null && $newStart->toDateString() < $created->toDateString()) {
            $fail('The billing start cannot be before the mailbox was created in Google ('.$created->format('d M Y').').');
        }

        // Earlier periods of this mailbox (possibly another organisation). Rows that
        // ended before they started are zero-day placeholders and do not count.
        $earlierEnd = AccountBillingPeriod::query()
            ->where('google_account_id', $account->getKey())
            ->whereKeyNot($open->getKey())
            ->whereNotNull('ends_on')
            ->whereColumn('ends_on', '>=', 'starts_on')
            ->max('ends_on');

        if ($earlierEnd !== null && $newStart->lte(CarbonImmutable::parse(substr((string) $earlierEnd, 0, 10)))) {
            $fail('The mailbox was billed under an earlier period until '.CarbonImmutable::parse(substr((string) $earlierEnd, 0, 10))->format('d M Y').'. Choose a later date.');
        }

        $from = $newStart->min($previous);

        $issued = Invoice::query()
            ->where('organisation_id', $account->organisation_id)
            ->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
            ->whereDate('period_end', '>=', $from->toDateString())
            ->orderBy('period_start')
            ->first();

        if ($issued !== null) {
            $fail("Invoice {$issued->invoice_number} has already been issued for ".$issued->period_start->format('d M').' – '.$issued->period_end->format('d M Y')
                .'. Issued invoices are never recalculated: void it first, or use a billing adjustment.');
        }

        $open->forceFill(['starts_on' => $newStart->toDateString()])->save();

        return $previous;
    }

    private function closeRow(AccountBillingPeriod $period, CarbonImmutable $lastDay): void
    {
        // Opened and closed on the same day: zero billable days. The row is
        // kept (ends before it starts) so the calculator simply skips it.
        $period->forceFill(['ends_on' => $lastDay->toDateString()])->save();
    }

    private function date(CarbonInterface|string|null $value): CarbonImmutable
    {
        return $value === null
            ? CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay()
            : CarbonImmutable::parse(is_string($value) ? $value : $value->toDateString())->startOfDay();
    }
}
