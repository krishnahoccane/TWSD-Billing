<?php

namespace App\Modules\Billing\Jobs;

use App\Models\User;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Calculates and stores the draft invoice (with its line items) for one
 * organisation and period. A business failure such as a missing price is
 * recorded on the billing cycle and not retried; infrastructure errors are.
 */
class CalculateOrganisationInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public int $organisationId,
        public string $periodStart,
        public string $periodEnd,
        public ?int $triggeredBy = null,
        public bool $autoIssue = false,
    ) {
        $this->onQueue('billing');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return "invoice:{$this->organisationId}:{$this->periodStart}:{$this->periodEnd}";
    }

    public function handle(InvoiceService $invoices, PaymentService $payments): void
    {
        $organisation = Organisation::query()->find($this->organisationId);

        if ($organisation === null) {
            return;
        }

        try {
            $actor = $this->triggeredBy === null ? null : User::query()->find($this->triggeredBy);

            $invoice = $invoices->generate(
                $organisation,
                CarbonImmutable::parse($this->periodStart),
                CarbonImmutable::parse($this->periodEnd),
                $actor,
            );

            // Automatic billing: issue straight away (numbered, visible, sent to Zoho).
            if ($this->autoIssue && $invoice !== null && $invoice->status === Invoice::DRAFT) {
                $invoices->issue($invoice, $actor);
                $payments->applyCredit($organisation);
            }
        } catch (BillingException $e) {
            $this->fail($e);
        }
    }
}
