<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single-use, short-lived link to one release package for one license. Only the token's hash is stored. */
class SoftwareDownloadLink extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(SoftwareRelease::class, 'software_release_id');
    }
}
