<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Exceptions\InvalidRoleAssignment;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    private RoleAssignmentService $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = app(RoleAssignmentService::class);
    }

    public function test_super_admin_cannot_be_assigned_inside_an_organisation(): void
    {
        $this->expectException(InvalidRoleAssignment::class);

        $this->roles->assignOrganisationRole(User::factory()->create(), Organisation::factory()->create(), RoleName::SuperAdmin);
    }

    public function test_organisation_roles_cannot_be_assigned_platform_wide(): void
    {
        foreach ([RoleName::OrganisationManager, RoleName::BillingManager] as $role) {
            try {
                $this->roles->assignPlatformRole(User::factory()->create(), $role);
                $this->fail("{$role->value} must not be assignable at platform level.");
            } catch (InvalidRoleAssignment) {
                $this->assertDatabaseCount('user_roles', 0);
            }
        }
    }

    public function test_assignment_is_idempotent(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();

        $this->roles->assignOrganisationRole($user, $organisation, RoleName::OrganisationManager);
        $this->roles->assignOrganisationRole($user, $organisation, RoleName::OrganisationManager);

        $this->assertDatabaseCount('organisation_user_roles', 1);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'access.organisation_role.assigned')->count());
    }

    public function test_assignments_and_revocations_are_audited_with_the_actor(): void
    {
        $actor = $this->superAdmin();
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();

        $this->roles->assignOrganisationRole($user, $organisation, RoleName::OrganisationManager, $actor);
        $this->roles->revokeOrganisationRole($user, $organisation, RoleName::OrganisationManager, $actor);

        foreach (['access.organisation_role.assigned', 'access.organisation_role.revoked'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'user_id' => $actor->id,
                'organisation_id' => $organisation->id,
                'resource_id' => $user->uuid,
            ]);
        }
    }

    public function test_revoking_a_role_removes_access_immediately(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $this->assertTrue($manager->hasAbility(Ability::AccountsView, $organisation));

        $this->assertTrue($this->roles->revokeOrganisationRole($manager, $organisation, RoleName::OrganisationManager));

        $this->assertFalse($manager->hasAbility(Ability::AccountsView, $organisation));
        $this->assertFalse($this->roles->revokeOrganisationRole($manager, $organisation, RoleName::OrganisationManager));
    }

    public function test_roles_cannot_be_granted_through_mass_assignment(): void
    {
        $user = User::factory()->create();

        $this->expectException(MassAssignmentException::class);

        $user->update(['is_active' => false]);
    }
}
