<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontWebhookEvent extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function publicId(): string
    {
        return 'evt_'.$this->uuid;
    }
}
