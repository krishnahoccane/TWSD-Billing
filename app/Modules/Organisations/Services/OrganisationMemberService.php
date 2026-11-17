<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Authentication\Services\UserService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Managers, billing managers and operations admins of one organisation.
 * Callers authorise first (OrganisationPolicy::manageMembers).
 */
class OrganisationMemberService
{
    public function __construct(
        private readonly RoleAssignmentService $roles,
        private readonly UserService $users,
    ) {}

    /**
     * @return Collection<int, OrganisationUserRole>
     */
    public function members(Organisation $organisation): Collection
    {
        return OrganisationUserRole::query()
            ->where('organisation_id', $organisation->getKey())
            ->with(['user:id,uuid,name,email,is_active,last_login_at', 'role:id,name,label'])
            ->get()
            ->sortBy(fn (OrganisationUserRole $assignment) => $assignment->user->name)
            ->values();
    }

    /**
     * Assign by e-mail. An unknown address creates a new dashboard user,
     * which requires the actor to hold users.manage and to supply a name.
     *
     * @throws ValidationException
     */
    public function assignByEmail(
        Organisation $organisation,
        string $email,
        RoleName $role,
        User $actor,
        ?string $name = null,
    ): OrganisationUserRole {
        return DB::transaction(function () use ($organisation, $email, $role, $actor, $name) {
            $user = User::query()->where('email', Str::lower(trim($email)))->first();

            if ($user === null) {
                if (! $actor->hasAbility(Ability::UsersManage)) {
                    throw ValidationException::withMessages([
                        'email' => 'No dashboard user exists with this email address.',
                    ]);
                }

                if (blank($name)) {
                    throw ValidationException::withMessages([
                        'name' => 'Enter a name to create a new dashboard user for this email address.',
                    ]);
                }

                $user = $this->users->create($name, $email, $actor);
            }

            return $this->roles->assignOrganisationRole($user, $organisation, $role, $actor);
        });
    }

    public function revoke(Organisation $organisation, User $user, RoleName $role, User $actor): bool
    {
        return $this->roles->revokeOrganisationRole($user, $organisation, $role, $actor);
    }

    /**
     * Roles that may be granted inside an organisation.
     *
     * @return array<string, string>
     */
    public static function assignableRoles(): array
    {
        return collect(RoleName::cases())
            ->filter(fn (RoleName $role) => $role->scope()->allowsOrganisationAssignment())
            ->mapWithKeys(fn (RoleName $role) => [$role->value => $role->label()])
            ->all();
    }
}
