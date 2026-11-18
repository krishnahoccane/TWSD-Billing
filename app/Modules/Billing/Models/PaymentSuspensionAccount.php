<?php

namespace App\Modules\Billing\Models;

use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mailbox in a payment suspension.
 *  - action "suspended": active before, suspended for non-payment, reactivated when paid.
 *  - action "already_suspended": suspended by hand before; left alone and never reactivated.
 */
class PaymentSuspensionAccount extends Model
{
    public const SUSPENDED = 'suspended';

    public const ALREADY_SUSPENDED = 'already_suspended';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reactivated_at' => 'datetime'];
    }

    public function suspension(): BelongsTo
    {
        return $this->belongsTo(PaymentSuspension::class, 'payment_suspension_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }
}
