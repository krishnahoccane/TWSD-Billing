<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\PaymentReminderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Daily: the payment reminder schedule (due −2, −1, 0, +1, +2). */
class SendPaymentRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(PaymentReminderService $reminders): void
    {
        $reminders->run();
    }
}
