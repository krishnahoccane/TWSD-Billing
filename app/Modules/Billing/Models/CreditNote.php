<?php

namespace App\Modules\Billing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    use BelongsToOrganisation, HasUuid;

    protected $table = 'credit_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'issued_at' => 'datetime'];
    }
}
