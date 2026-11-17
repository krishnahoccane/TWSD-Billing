<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\BillingRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts a billing run: one CalculateOrganisationInvoiceJob per billable
 * organisation. With no dates it bills the previous calendar month
 * (billing is in arrears). The monthly schedule passes autoIssue=true. Safe to run twice — invoice generation is
 * idempotent per organisation and period.
 */
class GenerateBillingCycleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?int $triggeredBy = null,
        // The scheduled run issues invoices automatically; runs started by
        // hand (usually for older months) create drafts for review.
        public bool $autoIssue = false,
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'billing-run:'.($this->periodStart ?? 'previous-month');
    }

    public function handle(BillingRunner $runner): void
    {
        if ($this->periodStart === null || $this->periodEnd === null) {
            [$start, $end] = BillingRunner::previousMonth();
            $this->periodStart = $start->toDateString();
            $this->periodEnd = $end->toDateString();
        }

        foreach ($runner->billableOrganisations() as $organisation) {
            CalculateOrganisationInvoiceJob::dispatch($organisation->getKey(), $this->periodStart, $this->periodEnd, $this->triggeredBy, $this->autoIssue);
        }
    }
}
