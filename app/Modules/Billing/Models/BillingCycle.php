<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingCycle extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'billing_cycles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'generated_at' => 'datetime'];
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
