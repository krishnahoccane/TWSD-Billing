<?php

namespace App\Modules\Licensing\Models;

use App\Modules\Organisations\Models\Organisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A website that sells software through this application (musicdesk.tech).
 * Its API key is stored only as a SHA-256 hash and shown once; its webhook
 * secret is encrypted with APP_KEY.
 */
class Storefront extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $hidden = ['api_key_hash', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(SoftwareProduct::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class, 'storefront_customers')->withTimestamps();
    }

    public function servesCustomer(Organisation $organisation): bool
    {
        return $this->customers()->whereKey($organisation->getKey())->exists();
    }
}
