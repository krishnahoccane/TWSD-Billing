<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation's mailboxes suspended automatically for an unpaid
 * invoice. The rows in payment_suspension_accounts record what every
 * mailbox was before, so that paying reactivates only the mailboxes this
 * suspension switched off — never ones that were already suspended by hand.
 */
class PaymentSuspension extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const ACTIVE = 'active';

    public const LIFTED = 'lifted';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'invoice_ids' => 'array',
            'suspended_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(PaymentSuspensionAccount::class);
    }
}
