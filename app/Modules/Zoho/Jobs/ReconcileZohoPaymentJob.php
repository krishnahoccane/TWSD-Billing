<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled fallback for webhooks (hourly) and the "Sync now" button:
 * sends issued invoices that are not in Zoho yet, pushes offline payments,
 * records payments made in Zoho and reports every invoice whose total,
 * balance or status differs from Zoho.
 */
class ReconcileZohoPaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $triggeredBy = null)
    {
        $this->onQueue('zoho');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function uniqueId(): string
    {
        return 'zoho-reconcile';
    }

    public function handle(ZohoSync $sync, AuditLogger $audit): void
    {
        $summary = $sync->reconcileAll();

        $audit->record('zoho.reconciled', new: $summary + ['mode' => $sync->mode()],
            status: $summary['failures'] > 0 ? AuditLogger::STATUS_FAILURE : AuditLogger::STATUS_SUCCESS);
    }
}
