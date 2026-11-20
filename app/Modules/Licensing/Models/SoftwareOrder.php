<?php

namespace App\Modules\Licensing\Models;

use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A paid order reported by a storefront (payment and invoice are the storefront's). */
class SoftwareOrder extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const KINDS = ['new_license', 'renewal', 'extra_domains', 'change_plan', 'convert_lifetime', 'updates_renewal'];

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'amount_minor' => 'integer', 'amount_usd_minor' => 'integer', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function publicId(): string
    {
        return 'ord_'.$this->uuid;
    }
}
