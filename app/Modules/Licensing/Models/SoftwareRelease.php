<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoftwareRelease extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'requirements' => 'array', 'notes' => 'array', 'is_published' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function publicId(): string
    {
        return 'rel_'.$this->uuid;
    }
}
