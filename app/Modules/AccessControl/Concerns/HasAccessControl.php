<?php

namespace App\Modules\AccessControl\Concerns;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\AccessControl\Support\OrganisationScope;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Convenience accessors on User. All decisions are delegated to
 * AccessResolver so there is exactly one implementation of the rules.
 */
trait HasAccessControl
{
    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    public function organisationRoleAssignments(): HasMany
    {
        return $this->hasMany(OrganisationUserRole::class);
    }

    public function hasAbility(Ability $ability, ?Organisation $organisation = null): bool
    {
        return app(AccessResolver::class)->can($this, $ability, $organisation);
    }

    public function hasAbilityAnywhere(Ability $ability): bool
    {
        return app(AccessResolver::class)->canAnywhere($this, $ability);
    }

    public function hasGlobalAccess(): bool
    {
        return app(AccessResolver::class)->hasGlobalAccess($this);
    }

    public function organisationScope(Ability $ability): OrganisationScope
    {
        return app(AccessResolver::class)->scopeFor($this, $ability);
    }

    /**
     * Holders of any platform-level role are "privileged" and must use 2FA.
     */
    public function isPrivileged(): bool
    {
        return app(AccessResolver::class)->hasPlatformRole($this);
    }
}
