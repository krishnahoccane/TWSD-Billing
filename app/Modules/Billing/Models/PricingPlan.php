<?php

namespace App\Modules\Billing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class PricingPlan extends Model
{
    use HasUuid;

    protected $table = 'pricing_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'monthly_price_minor' => 'integer'];
    }
}
