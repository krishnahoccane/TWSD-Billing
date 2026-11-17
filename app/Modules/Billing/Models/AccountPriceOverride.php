<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPriceOverride extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'account_pricing_overrides';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'monthly_price_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }
}
