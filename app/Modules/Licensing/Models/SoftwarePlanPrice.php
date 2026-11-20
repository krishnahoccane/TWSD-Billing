<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A price in one currency, in minor units (cents, paise). Never a float. */
class SoftwarePlanPrice extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SoftwarePlan::class, 'software_plan_id');
    }

    public function publicId(): string
    {
        return 'price_'.$this->uuid;
    }
}
