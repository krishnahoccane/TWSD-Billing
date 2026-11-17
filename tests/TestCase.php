<?php

namespace Tests;

use App\Models\User;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Runs DatabaseSeeder (roles and permissions) for every test. */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function superAdmin(bool $twoFactor = true): User
    {
        $user = $twoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();

        app(RoleAssignmentService::class)->assignPlatformRole($user, RoleName::SuperAdmin);

        return $user;
    }

    protected function operationsAdmin(Organisation ...$organisations): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $roles = app(RoleAssignmentService::class);

        $roles->assignPlatformRole($user, RoleName::OperationsAdmin);

        foreach ($organisations as $organisation) {
            $roles->assignOrganisationRole($user, $organisation, RoleName::OperationsAdmin);
        }

        return $user;
    }

    protected function organisationManager(Organisation ...$organisations): User
    {
        return $this->userWithOrganisationRole(RoleName::OrganisationManager, ...$organisations);
    }

    protected function billingManager(Organisation ...$organisations): User
    {
        return $this->userWithOrganisationRole(RoleName::BillingManager, ...$organisations);
    }

    private function userWithOrganisationRole(RoleName $role, Organisation ...$organisations): User
    {
        $user = User::factory()->create();

        foreach ($organisations as $organisation) {
            app(RoleAssignmentService::class)->assignOrganisationRole($user, $organisation, $role);
        }

        return $user;
    }
}
