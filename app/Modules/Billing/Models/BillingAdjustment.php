<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class BillingAdjustment extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'billing_adjustments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'amount_minor' => 'integer', 'approved_at' => 'datetime'];
    }

    /** Signed amount: credits reduce the invoice. */
    public function signedAmount(): int
    {
        return $this->type === 'credit' ? -$this->amount_minor : $this->amount_minor;
    }
}
