<?php

namespace App\Modules\Licensing\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareProduct extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $attributes = ['key_prefix' => 'LDSK', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(SoftwarePlan::class)->orderBy('sort')->orderBy('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(SoftwareRelease::class);
    }

    public function latestRelease(?string $channel = 'stable'): ?SoftwareRelease
    {
        return $this->releases()->where('is_published', true)->when($channel, fn ($q) => $q->where('channel', $channel))
            ->orderByDesc('released_at')->orderByDesc('id')->first();
    }
}
