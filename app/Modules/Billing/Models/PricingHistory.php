<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class PricingHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pricing_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'effective_from' => 'date', 'created_at' => 'datetime'];
    }
}
