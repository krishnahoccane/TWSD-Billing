<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Support\OrganisationScope;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;

/**
 * Single place that answers "may this user do X (in organisation Y)?".
 *
 * Rules:
 *  1. Inactive users hold no abilities.
 *  2. Platform assignments (user_roles) grant platform-level abilities.
 *  3. Platform assignments apply inside organisations ONLY when the user
 *     also holds platform.global_access. This is the explicit global-access
 *     capability; there is no implicit "admin sees everything" bypass.
 *  4. Organisation assignments (organisation_user_roles) grant abilities in
 *     that organisation only.
 *
 * Registered as a scoped singleton, so results are memoised per request and
 * never leak between requests or queue jobs.
 */
class AccessResolver
{
    /** @var array<int, array<string, true>> */
    private array $platform = [];

    /** @var array<int, array<int, array<string, true>>> */
    private array $organisation = [];

    public function can(User $user, Ability $ability, ?Organisation $organisation = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $platform = $this->platformAbilities($user);

        if ($organisation === null) {
            return isset($platform[$ability->value]);
        }

        if ($ability->isPlatformOnly()) {
            return false;
        }

        if (isset($platform[Ability::PlatformGlobalAccess->value], $platform[$ability->value])) {
            return true;
        }

        return isset($this->organisationAbilities($user)[$organisation->getKey()][$ability->value]);
    }

    /**
     * True when the user holds the ability at platform level or in at least
     * one organisation. Use only for navigation / page-level gating; resource
     * access must use can() with the concrete organisation.
     */
    public function canAnywhere(User $user, Ability $ability): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (isset($this->platformAbilities($user)[$ability->value])) {
            return true;
        }

        foreach ($this->organisationAbilities($user) as $abilities) {
            if (isset($abilities[$ability->value])) {
                return true;
            }
        }

        return false;
    }

    public function hasGlobalAccess(User $user): bool
    {
        return $user->is_active
            && isset($this->platformAbilities($user)[Ability::PlatformGlobalAccess->value]);
    }

    /**
     * Organisations in which the user holds the given ability.
     */
    public function scopeFor(User $user, Ability $ability): OrganisationScope
    {
        if (! $user->is_active || $ability->isPlatformOnly()) {
            return OrganisationScope::none();
        }

        $platform = $this->platformAbilities($user);

        if (isset($platform[Ability::PlatformGlobalAccess->value], $platform[$ability->value])) {
            return OrganisationScope::all();
        }

        $ids = [];

        foreach ($this->organisationAbilities($user) as $organisationId => $abilities) {
            if (isset($abilities[$ability->value])) {
                $ids[] = $organisationId;
            }
        }

        return OrganisationScope::only($ids);
    }

    public function hasPlatformRole(User $user): bool
    {
        return DB::table('user_roles')->where('user_id', $user->getKey())->exists();
    }

    /**
     * @return list<string>
     */
    public function roleLabels(User $user): array
    {
        $platform = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->getKey())
            ->pluck('roles.label');

        $organisation = DB::table('organisation_user_roles')
            ->join('roles', 'roles.id', '=', 'organisation_user_roles.role_id')
            ->where('organisation_user_roles.user_id', $user->getKey())
            ->pluck('roles.label');

        return $platform->merge($organisation)->unique()->values()->all();
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->platform = [];
            $this->organisation = [];

            return;
        }

        unset($this->platform[$user->getKey()], $this->organisation[$user->getKey()]);
    }

    /**
     * @return array<string, true>
     */
    private function platformAbilities(User $user): array
    {
        return $this->platform[$user->getKey()] ??= DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->getKey())
            ->distinct()
            ->pluck('permissions.name')
            ->mapWithKeys(fn (string $name) => [$name => true])
            ->all();
    }

    /**
     * @return array<int, array<string, true>>
     */
    private function organisationAbilities(User $user): array
    {
        if (isset($this->organisation[$user->getKey()])) {
            return $this->organisation[$user->getKey()];
        }

        $rows = DB::table('organisation_user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'organisation_user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('organisations', 'organisations.id', '=', 'organisation_user_roles.organisation_id')
            ->where('organisation_user_roles.user_id', $user->getKey())
            ->whereNull('organisations.deleted_at')
            // Decision D-06: assignments to an inactive organisation grant nothing.
            ->where('organisations.status', '!=', OrganisationStatus::Inactive->value)
            ->select('organisation_user_roles.organisation_id', 'permissions.name', 'organisations.status')
            ->distinct()
            ->get();

        $map = [];

        foreach ($rows as $row) {
            // Decision D-06: a suspended organisation is read-only for its own users.
            if ($row->status === OrganisationStatus::Suspended->value
                && ! Ability::from($row->name)->allowedWhenOrganisationSuspended()) {
                continue;
            }

            $map[(int) $row->organisation_id][$row->name] = true;
        }

        return $this->organisation[$user->getKey()] = $map;
    }
}
