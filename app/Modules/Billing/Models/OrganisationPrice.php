<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class OrganisationPrice extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'organisation_pricing';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'monthly_price_minor' => 'integer'];
    }
}
