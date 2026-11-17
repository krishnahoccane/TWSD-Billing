<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Billing\Models\Payment;
use App\Modules\Zoho\Services\ZohoSync;
use Throwable;

/** Records an offline payment (verified by a Super Admin) in Zoho Books. */
class PushPaymentToZohoJob extends ZohoJob
{
    protected function run(ZohoSync $sync): void
    {
        if ($payment = Payment::query()->find($this->recordId)) {
            $sync->pushPayment($payment);
        }
    }

    protected function recordFailure(ZohoSync $sync, Throwable $error): void
    {
        if ($payment = Payment::query()->find($this->recordId)) {
            $sync->markFailed($payment, 'payment', $error);
        }
    }
}
