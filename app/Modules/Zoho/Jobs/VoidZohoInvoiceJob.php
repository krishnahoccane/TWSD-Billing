<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Zoho\Services\ZohoSync;
use Throwable;

/** Voids the matching invoice in Zoho Books after it was voided here. */
class VoidZohoInvoiceJob extends ZohoJob
{
    protected function run(ZohoSync $sync): void
    {
        $invoice = Invoice::query()->find($this->recordId);

        if ($invoice !== null && $invoice->status === Invoice::VOID) {
            $sync->voidInvoice($invoice);
        }
    }

    protected function recordFailure(ZohoSync $sync, Throwable $error): void
    {
        if ($invoice = Invoice::query()->find($this->recordId)) {
            $sync->markFailed($invoice, 'invoice', $error);
        }
    }
}
