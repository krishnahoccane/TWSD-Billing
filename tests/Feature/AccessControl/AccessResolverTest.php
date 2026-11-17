<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\AccessControl\Services\RoleAssignmentService;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

class AccessResolverTest extends TestCase
{
    public function test_seeder_creates_roles_and_every_permission(): void
    {
        $this->assertSame(count(RoleName::cases()), Role::query()->count());
        $this->assertSame(count(Ability::cases()), Permission::query()->count());
        $this->assertSame(
            count(Ability::cases()),
            Role::findByName(RoleName::SuperAdmin)->permissions()->count(),
        );
    }

    public function test_seeder_is_idempotent_and_preserves_customised_roles(): void
    {
        $ops = Role::findByName(RoleName::OperationsAdmin);
        $ops->permissions()->attach(Permission::query()->where('name', Ability::PricingManage->value)->value('id'));
        $expected = $ops->permissions()->count();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(RoleName::cases()), Role::query()->count());
        $this->assertSame(count(Ability::cases()), Permission::query()->count());
        $this->assertSame($expected, $ops->permissions()->count());
    }

    public function test_super_admin_has_explicit_global_access_to_every_organisation(): void
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create();

        $this->assertTrue($admin->hasGlobalAccess());
        $this->assertTrue($admin->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($admin->hasAbility(Ability::PricingManage, $organisation));
        $this->assertTrue($admin->hasAbility(Ability::SettingsManage));
        $this->assertTrue($admin->organisationScope(Ability::InvoicesView)->all);
    }

    public function test_organisation_manager_cannot_act_in_another_organisation(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a);

        $this->assertTrue($manager->hasAbility(Ability::AccountsView, $a));
        $this->assertTrue($manager->hasAbility(Ability::InvoicesView, $a));

        foreach ([Ability::AccountsView, Ability::DomainsView, Ability::InvoicesView, Ability::PaymentsView, Ability::OrganisationsView] as $ability) {
            $this->assertFalse($manager->hasAbility($ability, $b), "{$ability->value} leaked into organisation B");
        }

        $scope = $manager->organisationScope(Ability::AccountsView);
        $this->assertFalse($scope->all);
        $this->assertSame([$a->id], $scope->ids);
    }

    public function test_one_manager_can_manage_multiple_organisations(): void
    {
        [$a, $b, $c] = Organisation::factory()->count(3)->create();
        $manager = $this->organisationManager($a, $b);

        $this->assertTrue($manager->hasAbility(Ability::AccountsCreate, $a));
        $this->assertTrue($manager->hasAbility(Ability::AccountsCreate, $b));
        $this->assertFalse($manager->hasAbility(Ability::AccountsCreate, $c));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $manager->organisationScope(Ability::AccountsCreate)->ids);
    }

    public function test_an_organisation_can_have_multiple_managers(): void
    {
        $organisation = Organisation::factory()->create();
        $first = $this->organisationManager($organisation);
        $second = $this->organisationManager($organisation);

        $this->assertTrue($first->hasAbility(Ability::AccountsSuspend, $organisation));
        $this->assertTrue($second->hasAbility(Ability::AccountsSuspend, $organisation));
    }

    public function test_a_user_can_hold_different_roles_in_different_organisations(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $user = $this->organisationManager($a);
        app(RoleAssignmentService::class)
            ->assignOrganisationRole($user, $b, RoleName::BillingManager);

        $this->assertTrue($user->hasAbility(Ability::AccountsSuspend, $a));
        $this->assertFalse($user->hasAbility(Ability::AccountsSuspend, $b));
        $this->assertTrue($user->hasAbility(Ability::InvoicesView, $b));
    }

    public function test_organisation_manager_restrictions(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $denied = [
            Ability::PricingManage,
            Ability::DomainsApprove,
            Ability::DomainsRemove,
            Ability::AccountsApprovePermanentDeletion,
            Ability::AccountsExecutePermanentDeletion,
            Ability::AccountsReassignOrganisation,
            Ability::AccountsSoftDelete,
            Ability::OrganisationsChangeStatus,
            Ability::OrganisationsManageManagers,
            Ability::InvoicesManage,
        ];

        foreach ($denied as $ability) {
            $this->assertFalse($manager->hasAbility($ability, $organisation), "{$ability->value} must be denied");
        }

        foreach ([Ability::SettingsManage, Ability::IntegrationsManageGoogle, Ability::IntegrationsViewLogs, Ability::UsersManage, Ability::PlatformGlobalAccess] as $ability) {
            $this->assertFalse($manager->hasAbility($ability), "{$ability->value} must be denied");
        }

        $this->assertFalse($manager->hasGlobalAccess());
    }

    public function test_billing_manager_has_financial_access_only(): void
    {
        $organisation = Organisation::factory()->create();
        $billing = $this->billingManager($organisation);

        foreach ([Ability::BillingView, Ability::InvoicesView, Ability::InvoicesDownload, Ability::PaymentsView, Ability::PaymentsInitiate, Ability::BillingManageContacts] as $ability) {
            $this->assertTrue($billing->hasAbility($ability, $organisation), "{$ability->value} must be allowed");
        }

        foreach ([Ability::AccountsView, Ability::AccountsCreate, Ability::AccountsSuspend, Ability::DomainsView, Ability::DomainsRequest, Ability::PricingManage] as $ability) {
            $this->assertFalse($billing->hasAbility($ability, $organisation), "{$ability->value} must be denied");
        }
    }

    public function test_operations_admin_is_limited_to_assigned_organisations_by_default(): void
    {
        [$assigned, $other] = Organisation::factory()->count(2)->create();
        $ops = $this->operationsAdmin($assigned);

        $this->assertTrue($ops->hasAbility(Ability::AccountsSync));
        $this->assertTrue($ops->hasAbility(Ability::IntegrationsViewLogs));
        $this->assertTrue($ops->hasAbility(Ability::AccountsSuspend, $assigned));
        $this->assertFalse($ops->hasAbility(Ability::AccountsSuspend, $other));
        $this->assertFalse($ops->hasGlobalAccess());
    }

    public function test_operations_admin_has_no_financial_or_destructive_abilities_by_default(): void
    {
        $organisation = Organisation::factory()->create();
        $ops = $this->operationsAdmin($organisation);

        foreach (Ability::cases() as $ability) {
            if ($ability->isSensitive()) {
                $this->assertFalse($ops->hasAbility($ability, $organisation), "{$ability->value} must be denied in org");
                $this->assertFalse($ops->hasAbility($ability), "{$ability->value} must be denied at platform level");
            }
        }

        $this->assertFalse($ops->hasAbility(Ability::InvoicesView, $organisation));
    }

    public function test_sensitive_abilities_are_separately_grantable_to_operations_admin(): void
    {
        $organisation = Organisation::factory()->create();
        $ops = $this->operationsAdmin($organisation);

        Role::findByName(RoleName::OperationsAdmin)->permissions()->attach(
            Permission::query()->where('name', Ability::AccountsSoftDelete->value)->value('id'),
        );
        app(AccessResolver::class)->forget();

        $this->assertTrue($ops->hasAbility(Ability::AccountsSoftDelete, $organisation));
        $this->assertFalse($ops->hasAbility(Ability::PricingManage, $organisation));
    }

    public function test_platform_role_reaches_organisations_only_with_global_access(): void
    {
        $organisation = Organisation::factory()->create();
        $ops = $this->operationsAdmin();

        $this->assertFalse($ops->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($ops->organisationScope(Ability::AccountsView)->isEmpty());

        Role::findByName(RoleName::OperationsAdmin)->permissions()->attach(
            Permission::query()->where('name', Ability::PlatformGlobalAccess->value)->value('id'),
        );
        app(AccessResolver::class)->forget();

        $this->assertTrue($ops->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($ops->organisationScope(Ability::AccountsView)->all);
        // Global access widens reach, not the permission set.
        $this->assertFalse($ops->hasAbility(Ability::PricingManage, $organisation));
    }

    public function test_inactive_user_holds_no_abilities(): void
    {
        $organisation = Organisation::factory()->create();
        $admin = $this->superAdmin();
        $admin->forceFill(['is_active' => false])->save();

        $this->assertFalse($admin->hasAbility(Ability::SettingsManage));
        $this->assertFalse($admin->hasAbility(Ability::AccountsView, $organisation));
        $this->assertFalse($admin->hasGlobalAccess());
        $this->assertTrue($admin->organisationScope(Ability::AccountsView)->isEmpty());
    }

    public function test_user_without_any_role_holds_no_abilities(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();

        foreach (Ability::cases() as $ability) {
            $this->assertFalse($user->hasAbility($ability));
            $this->assertFalse($user->hasAbility($ability, $organisation));
        }
    }

    public function test_abilities_are_enforced_through_laravel_gates(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a);

        $this->assertTrue($manager->can(Ability::AccountsView->value, $a));
        $this->assertFalse($manager->can(Ability::AccountsView->value, $b));
        $this->assertFalse($manager->can(Ability::SettingsManage->value));
        $this->assertTrue($this->superAdmin()->can(Ability::SettingsManage->value));
    }

    public function test_organisation_queries_are_tenant_filtered(): void
    {
        [$a, $b, $c] = Organisation::factory()->count(3)->create();
        $manager = $this->organisationManager($a, $b);

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            Organisation::query()->visibleTo($manager)->pluck('id')->all(),
        );
        $this->assertSame(3, Organisation::query()->visibleTo($this->superAdmin())->count());
        $this->assertSame(0, Organisation::query()->visibleTo(User::factory()->create())->count());
    }

    public function test_assignments_to_a_deleted_organisation_grant_nothing(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $organisation->delete();
        app(AccessResolver::class)->forget();

        $this->assertFalse($manager->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($manager->organisationScope(Ability::AccountsView)->isEmpty());
    }

    public function test_uuid_substitution_cannot_switch_into_another_organisation(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a);
        $context = app(OrganisationContext::class);

        $this->assertTrue($context->switchTo($manager, $a->uuid)->is($a));

        try {
            $context->switchTo($manager, $b->uuid);
            $this->fail('Switching into an unassigned organisation must be rejected.');
        } catch (AuthorizationException) {
            $this->assertTrue($context->current($manager)->is($a));
        }

        // A tampered session value is ignored rather than trusted.
        session(['wcc.organisation' => $b->uuid]);
        $this->assertTrue($context->current($manager)->is($a));

        $this->expectException(AuthorizationException::class);
        $context->switchTo($manager, null);
    }
}
