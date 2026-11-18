<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\PaymentSuspensionService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Without an organisation (scheduled, hourly): suspend organisations whose
 * invoice is unpaid after the grace days, and lift suspensions that have
 * been paid. With an organisation (after a payment is recorded): only
 * check whether that organisation's suspension can be lifted.
 */
class EnforcePaymentSuspensionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public ?int $organisationId = null)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return (string) ($this->organisationId ?? 'all');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PaymentSuspensionService $suspensions): void
    {
        if ($this->organisationId === null) {
            $suspensions->run();

            return;
        }

        if ($organisation = Organisation::query()->find($this->organisationId)) {
            $suspensions->liftIfPaid($organisation);
        }
    }
}
