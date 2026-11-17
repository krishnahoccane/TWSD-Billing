<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_minor' => 'integer', 'unallocated_minor' => 'integer'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
