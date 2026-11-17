<?php

namespace Tests\Feature\Organisations;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Authentication\Services\UserService;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Models\OrganisationContact;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrganisationMembersTest extends TestCase
{
    public function test_super_admin_assigns_an_existing_user_as_manager(): void
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('organisations.members.store', $organisation), ['email' => strtoupper($user->email), 'role' => 'organisation_manager'])
            ->assertRedirect();

        $this->assertTrue($user->hasAbility(Ability::AccountsCreate, $organisation));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.organisation_role.assigned', 'user_id' => $admin->id, 'organisation_id' => $organisation->id,
        ]);
    }

    public function test_multiple_managers_and_one_manager_for_multiple_organisations(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        [$first, $second] = User::factory()->count(2)->create();

        $this->actingAs($this->superAdmin());
        $this->post(route('organisations.members.store', $a), ['email' => $first->email, 'role' => 'organisation_manager']);
        $this->post(route('organisations.members.store', $a), ['email' => $second->email, 'role' => 'organisation_manager']);
        $this->post(route('organisations.members.store', $b), ['email' => $first->email, 'role' => 'organisation_manager']);
        $this->post(route('organisations.members.store', $b), ['email' => $second->email, 'role' => 'billing_manager']);

        $this->assertTrue($first->hasAbility(Ability::AccountsView, $a));
        $this->assertTrue($second->hasAbility(Ability::AccountsView, $a));
        $this->assertTrue($first->hasAbility(Ability::AccountsView, $b));
        $this->assertFalse($second->hasAbility(Ability::AccountsView, $b));
        $this->assertTrue($second->hasAbility(Ability::InvoicesView, $b));

        $this->get(route('organisations.show', $a))->assertOk()->assertSee($first->email)->assertSee($second->email);
        $this->get('/managers')->assertOk()->assertSee($first->email)->assertSee($b->name);
    }

    public function test_assigning_an_unknown_email_creates_a_user_who_sets_their_own_password(): void
    {
        Notification::fake();
        $organisation = Organisation::factory()->create();

        $this->actingAs($this->superAdmin());

        $this->post(route('organisations.members.store', $organisation), ['email' => 'new.person@example.com', 'role' => 'billing_manager'])
            ->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);

        $this->post(route('organisations.members.store', $organisation), [
            'email' => 'New.Person@Example.com', 'name' => 'New Person', 'role' => 'billing_manager',
        ])->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertTrue($user->hasAbility(Ability::InvoicesView, $organisation));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'resource_id' => $user->uuid]);
    }

    public function test_super_admin_role_cannot_be_granted_inside_an_organisation(): void
    {
        $organisation = Organisation::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post(route('organisations.members.store', $organisation), ['email' => $user->email, 'role' => 'super_admin'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('organisation_user_roles', 0);
    }

    public function test_only_super_admins_manage_members(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $target = User::factory()->create();

        $this->actingAs($manager);
        $this->post(route('organisations.members.store', $organisation), ['email' => $target->email, 'role' => 'organisation_manager'])->assertForbidden();
        $this->delete(route('organisations.members.destroy', [$organisation, $manager]), ['role' => 'organisation_manager'])->assertForbidden();
        $this->get('/managers')->assertForbidden();

        $this->assertFalse($target->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($manager->hasAbility(Ability::AccountsView, $organisation));
    }

    public function test_super_admin_can_remove_access(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $this->actingAs($this->superAdmin())
            ->delete(route('organisations.members.destroy', [$organisation, $manager]), ['role' => 'organisation_manager'])
            ->assertRedirect();

        $this->assertFalse($manager->hasAbility(Ability::AccountsView, $organisation));
        $this->assertDatabaseHas('users', ['id' => $manager->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.organisation_role.revoked']);
    }

    public function test_managers_manage_contacts_and_billing_managers_only_billing_contacts(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $billing = $this->billingManager($organisation);
        $contact = ['name' => 'Ravi Kumar', 'email' => 'ravi@example.com'];

        $this->actingAs($manager)
            ->post(route('organisations.contacts.store', $organisation), $contact + ['type' => 'technical'])
            ->assertSessionHasNoErrors();

        $this->actingAs($billing);
        $this->post(route('organisations.contacts.store', $organisation), $contact + ['type' => 'general'])->assertForbidden();
        $this->post(route('organisations.contacts.store', $organisation), $contact + ['type' => 'billing'])->assertSessionHasNoErrors();

        $technical = OrganisationContact::query()->where('type', 'technical')->firstOrFail();
        $this->delete(route('organisations.contacts.destroy', [$organisation, $technical]))->assertForbidden();

        $this->assertDatabaseCount('organisation_contacts', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organisation.contact.created', 'organisation_id' => $organisation->id]);

        $this->actingAs($manager)->delete(route('organisations.contacts.destroy', [$organisation, $technical]))->assertRedirect();
        $this->assertDatabaseCount('organisation_contacts', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organisation.contact.deleted']);
    }

    public function test_a_contact_cannot_be_deleted_through_another_organisations_url(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a, $b);
        $this->actingAs($manager)->post(route('organisations.contacts.store', $a), ['type' => 'general', 'name' => 'A Contact', 'phone' => '040 1234']);
        $contact = OrganisationContact::query()->firstOrFail();

        $this->delete(route('organisations.contacts.destroy', [$b, $contact]))->assertNotFound();
        $this->assertDatabaseCount('organisation_contacts', 1);
    }

    public function test_only_super_admin_maps_domains_and_a_domain_belongs_to_one_organisation(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();

        $this->actingAs($this->organisationManager($a))
            ->post(route('organisations.domains.store', $a), ['domain_name' => 'acme.example', 'domain_type' => 'secondary'])
            ->assertForbidden();

        $this->actingAs($this->superAdmin());
        $this->post(route('organisations.domains.store', $a), ['domain_name' => ' ACME.Example. ', 'domain_type' => 'secondary'])
            ->assertSessionHasNoErrors();
        $this->post(route('organisations.domains.store', $b), ['domain_name' => 'acme.example', 'domain_type' => 'secondary'])
            ->assertSessionHasErrorsIn('domain', 'domain_name');
        $this->post(route('organisations.domains.store', $b), ['domain_name' => 'not a domain', 'domain_type' => 'secondary'])
            ->assertSessionHasErrorsIn('domain', 'domain_name');

        $this->assertDatabaseCount('organisation_domains', 1);
        $this->assertDatabaseHas('organisation_domains', [
            'domain_name' => 'acme.example', 'organisation_id' => $a->id, 'verification_status' => 'unverified',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.mapped', 'organisation_id' => $a->id]);
    }

    public function test_super_admin_creates_and_deactivates_dashboard_users(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/users')->assertOk()->assertSee($admin->email);

        $this->post('/users', ['name' => 'Ops Person', 'email' => 'Ops@Example.com', 'platform_role' => 'operations_admin'])
            ->assertRedirect('/users');

        $ops = User::query()->where('email', 'ops@example.com')->firstOrFail();
        $this->assertTrue($ops->isPrivileged());
        $this->assertFalse($ops->hasGlobalAccess());
        Notification::assertSentTo($ops, ResetPassword::class);

        $this->post('/users', ['name' => 'Dup', 'email' => 'ops@example.com'])->assertSessionHasErrorsIn('user', 'email');
        $this->post('/users', ['name' => 'Bad', 'email' => 'bad@example.com', 'platform_role' => 'organisation_manager'])
            ->assertSessionHasErrorsIn('user', 'platform_role');

        $token = $ops->createToken('test');
        $this->post(route('users.active', $ops), ['active' => 0])->assertSessionHas('success');

        $this->assertFalse($ops->fresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'resource_id' => $ops->uuid]);

        $this->post(route('users.active', $ops), ['active' => 1]);
        $this->assertTrue($ops->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves_or_the_last_super_admin(): void
    {
        $admin = $this->superAdmin();
        $second = $this->superAdmin();

        $this->actingAs($admin)->post(route('users.active', $admin), ['active' => 0])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);

        $this->post(route('users.active', $second), ['active' => 0])->assertSessionHas('success');
        $this->assertFalse($second->fresh()->is_active);

        // $admin is now the last active Super Admin; a second (inactive) admin cannot remove them either.
        $this->assertSame(1, User::query()->where('is_active', true)->whereHas('platformRoles')->count());
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated(): void
    {
        $admin = $this->superAdmin();
        $actor = User::factory()->create();

        try {
            app(UserService::class)->setActive($admin, false, $actor);
            $this->fail('The last active Super Admin must not be deactivatable.');
        } catch (\DomainException) {
            $this->assertTrue($admin->fresh()->is_active);
        }
    }

    public function test_user_management_is_super_admin_only(): void
    {
        $organisation = Organisation::factory()->create();
        $target = User::factory()->create();

        foreach ([$this->organisationManager($organisation), $this->billingManager($organisation), $this->operationsAdmin($organisation)] as $user) {
            $this->actingAs($user);
            $this->get('/users')->assertForbidden();
            $this->post('/users', ['name' => 'X', 'email' => 'x'.$user->id.'@example.com'])->assertForbidden();
            $this->post(route('users.active', $target), ['active' => 0])->assertForbidden();
            $this->post(route('users.password-link', $target))->assertForbidden();
        }

        $this->assertTrue($target->fresh()->is_active);
    }
}
