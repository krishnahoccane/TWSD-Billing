<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
