<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class AccountBillingPeriod extends Model
{
    protected $table = 'account_billing_periods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
