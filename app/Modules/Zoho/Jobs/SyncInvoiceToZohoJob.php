<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Zoho\Services\ZohoSync;
use Throwable;

/** Creates the customer (once) and the invoice in Zoho Books, marks it sent and stores its link. */
class SyncInvoiceToZohoJob extends ZohoJob
{
    protected function run(ZohoSync $sync): void
    {
        $invoice = Invoice::query()->find($this->recordId);

        if ($invoice !== null && in_array($invoice->status, [Invoice::ISSUED, Invoice::PARTIALLY_PAID, Invoice::PAID], true)) {
            $sync->syncInvoice($invoice);
        }
    }

    protected function recordFailure(ZohoSync $sync, Throwable $error): void
    {
        if ($invoice = Invoice::query()->find($this->recordId)) {
            $sync->markFailed($invoice, 'invoice', $error);
        }
    }
}
