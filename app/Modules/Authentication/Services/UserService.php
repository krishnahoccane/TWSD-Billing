<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Audit\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Dashboard user lifecycle. Callers authorise first (users.manage).
 *
 * Nobody ever chooses or sees another person's password: a new user gets
 * an unusable random password and a set-password link by e-mail.
 */
class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessResolver $resolver,
        private readonly RoleAssignmentService $roles,
    ) {}

    public function create(string $name, string $email, User $actor, ?RoleName $platformRole = null): User
    {
        $user = DB::transaction(function () use ($name, $email, $actor, $platformRole) {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => Str::password(40),
            ]);

            $this->audit->record(
                action: 'user.created',
                resource: $user,
                new: ['name' => $user->name, 'email' => $user->email],
                actor: $actor,
            );

            if ($platformRole !== null) {
                $this->roles->assignPlatformRole($user, $platformRole, $actor);
            }

            return $user;
        });

        $this->sendPasswordLink($user, $actor);

        return $user;
    }

    /**
     * E-mails a link with which the user sets their own password.
     * Returns false when the broker refused (for example, throttled).
     */
    public function sendPasswordLink(User $user, User $actor): bool
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        $sent = $status === Password::RESET_LINK_SENT;

        $this->audit->record(
            action: 'user.password_link_sent',
            resource: $user,
            status: $sent ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE,
            actor: $actor,
        );

        return $sent;
    }

    /**
     * @throws DomainException when the change would lock the platform
     */
    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        if (! $active) {
            if ($user->is($actor)) {
                throw new DomainException('You cannot deactivate your own account.');
            }

            if ($this->isLastActiveGlobalAdmin($user)) {
                throw new DomainException('This is the last active Super Admin and cannot be deactivated.');
            }
        }

        return DB::transaction(function () use ($user, $active, $actor) {
            $user->forceFill(['is_active' => $active])->save();

            if (! $active) {
                // Cut off API access immediately; web sessions end on the next request.
                $user->tokens()->delete();
            }

            $this->audit->record(
                action: $active ? 'user.activated' : 'user.deactivated',
                resource: $user,
                previous: ['is_active' => ! $active],
                new: ['is_active' => $active],
                actor: $actor,
            );

            $this->resolver->forget($user);

            return $user;
        });
    }

    private function isLastActiveGlobalAdmin(User $user): bool
    {
        if (! $this->resolver->hasGlobalAccess($user)) {
            return false;
        }

        return ! DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.name', Ability::PlatformGlobalAccess->value)
            ->where('users.is_active', true)
            ->where('users.id', '!=', $user->getKey())
            ->exists();
    }
}
