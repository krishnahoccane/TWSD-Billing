<?php

namespace App\Modules\Domains\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain as it exists in Google Workspace (platform inventory, not
 * organisation-owned). Only Super Admins see these rows; organisations see
 * their OrganisationDomain mappings.
 */
class GoogleDomain extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_from_google_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OrganisationDomain, $this> */
    public function organisationDomain(): BelongsTo
    {
        return $this->belongsTo(OrganisationDomain::class);
    }

    /** In Google, not mapped to an organisation, still present. */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('organisation_domain_id')->whereNull('missing_from_google_at');
    }

    public function isAlias(): bool
    {
        return $this->alias_of !== null;
    }
}
