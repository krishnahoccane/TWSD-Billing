<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwarePlan extends Model
{
    use HasUuid;

    public const PRICE_KINDS = ['annual', 'lifetime', 'extra_domain', 'extra_domain_lifetime', 'updates_renewal'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['features' => 'array', 'is_featured' => 'boolean', 'is_active' => 'boolean', 'included_domains' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SoftwarePlanPrice::class);
    }

    public function price(string $kind, string $currency): ?SoftwarePlanPrice
    {
        return $this->prices->first(fn (SoftwarePlanPrice $price) => $price->kind === $kind && $price->currency === $currency && $price->is_active);
    }
}
