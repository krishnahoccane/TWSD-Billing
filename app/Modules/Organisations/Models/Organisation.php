<?php

namespace App\Modules\Organisations\Models;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Support\Concerns\HasUuid;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'code', 'pan', 'gstin',
        'primary_contact_name', 'primary_contact_email', 'contact_phone', 'billing_email',
        'registered_address', 'billing_address', 'country', 'state', 'city', 'postal_code',
        'notes',
    ];

    protected $attributes = [
        'status' => 'active',
        'currency' => null,
        'country' => 'IN',
    ];

    protected function casts(): array
    {
        return ['status' => OrganisationStatus::class];
    }

    protected static function newFactory(): OrganisationFactory
    {
        return OrganisationFactory::new();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(OrganisationUserRole::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganisationDomain::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(OrganisationContact::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(OrganisationSetting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tenant filter: only organisations in which the user holds the ability.
     */
    public function scopeVisibleTo(Builder $query, User $user, Ability $ability = Ability::OrganisationsView): Builder
    {
        $user->organisationScope($ability)->apply($query, $this->qualifyColumn('id'));

        return $query;
    }
}
