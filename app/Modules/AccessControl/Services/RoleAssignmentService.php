<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Exceptions\InvalidRoleAssignment;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Models\UserRole;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;

/**
 * The only supported way to grant or revoke roles. Enforces role scope,
 * is idempotent, and audits every change.
 *
 * Callers are responsible for authorising the acting user first
 * (users.manage / organisations.manage_managers).
 */
class RoleAssignmentService
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function assignPlatformRole(User $user, RoleName|Role $role, ?User $actor = null): UserRole
    {
        $role = $this->resolveRole($role);

        if (! $role->scope->allowsPlatformAssignment()) {
            throw InvalidRoleAssignment::notPlatformAssignable($role);
        }

        return DB::transaction(function () use ($user, $role, $actor) {
            $assignment = UserRole::query()->firstOrCreate(
                ['user_id' => $user->getKey(), 'role_id' => $role->getKey()],
                ['assigned_by' => $actor?->getKey()],
            );

            if ($assignment->wasRecentlyCreated) {
                $this->audit->record(
                    action: 'access.platform_role.assigned',
                    resource: $user,
                    new: ['role' => $role->name],
                    actor: $actor,
                );
            }

            $this->resolver->forget($user);

            return $assignment;
        });
    }

    public function revokePlatformRole(User $user, RoleName|Role $role, ?User $actor = null): bool
    {
        $role = $this->resolveRole($role);

        return DB::transaction(function () use ($user, $role, $actor) {
            $deleted = UserRole::query()
                ->where('user_id', $user->getKey())
                ->where('role_id', $role->getKey())
                ->delete();

            if ($deleted > 0) {
                $this->audit->record(
                    action: 'access.platform_role.revoked',
                    resource: $user,
                    previous: ['role' => $role->name],
                    actor: $actor,
                );
            }

            $this->resolver->forget($user);

            return $deleted > 0;
        });
    }

    public function assignOrganisationRole(
        User $user,
        Organisation $organisation,
        RoleName|Role $role,
        ?User $actor = null,
    ): OrganisationUserRole {
        $role = $this->resolveRole($role);

        if (! $role->scope->allowsOrganisationAssignment()) {
            throw InvalidRoleAssignment::notOrganisationAssignable($role);
        }

        return DB::transaction(function () use ($user, $organisation, $role, $actor) {
            $assignment = OrganisationUserRole::query()->firstOrCreate(
                [
                    'organisation_id' => $organisation->getKey(),
                    'user_id' => $user->getKey(),
                    'role_id' => $role->getKey(),
                ],
                ['assigned_by' => $actor?->getKey()],
            );

            if ($assignment->wasRecentlyCreated) {
                $this->audit->record(
                    action: 'access.organisation_role.assigned',
                    resource: $user,
                    new: ['role' => $role->name, 'organisation' => $organisation->uuid],
                    organisation: $organisation,
                    actor: $actor,
                );
            }

            $this->resolver->forget($user);

            return $assignment;
        });
    }

    public function revokeOrganisationRole(
        User $user,
        Organisation $organisation,
        RoleName|Role $role,
        ?User $actor = null,
    ): bool {
        $role = $this->resolveRole($role);

        return DB::transaction(function () use ($user, $organisation, $role, $actor) {
            $deleted = OrganisationUserRole::query()
                ->where('organisation_id', $organisation->getKey())
                ->where('user_id', $user->getKey())
                ->where('role_id', $role->getKey())
                ->delete();

            if ($deleted > 0) {
                $this->audit->record(
                    action: 'access.organisation_role.revoked',
                    resource: $user,
                    previous: ['role' => $role->name, 'organisation' => $organisation->uuid],
                    organisation: $organisation,
                    actor: $actor,
                );
            }

            $this->resolver->forget($user);

            return $deleted > 0;
        });
    }

    private function resolveRole(RoleName|Role $role): Role
    {
        return $role instanceof Role ? $role : Role::findByName($role);
    }
}
