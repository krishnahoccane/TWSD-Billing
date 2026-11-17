<?php

namespace Tests\Feature\Organisations;

use App\Livewire\OrganisationTable;
use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Services\AccessResolver;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationExporter;
use Database\Factories\OrganisationDomainFactory;
use Livewire\Livewire;
use Tests\TestCase;

class OrganisationManagementTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Acme Exports',
            'legal_name' => 'Acme Exports Private Limited',
            'code' => 'acme',
            'pan' => 'abcde1234f',
            'gstin' => '36ABCDE1234F1Z5',
            'primary_contact_name' => 'Asha Rao',
            'primary_contact_email' => 'Asha@Acme.example',
            'contact_phone' => '+91 98765 43210',
            'billing_email' => 'accounts@acme.example',
            'country' => 'in',
            'state' => 'Telangana',
            'city' => 'Hyderabad',
            'postal_code' => '500001',
        ];
    }

    public function test_super_admin_can_create_an_organisation(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/organisations', $this->payload());

        $organisation = Organisation::query()->where('code', 'ACME')->firstOrFail();
        $response->assertRedirect(route('organisations.show', $organisation));

        $this->assertSame('ABCDE1234F', $organisation->pan);
        $this->assertSame('IN', $organisation->country);
        $this->assertSame('asha@acme.example', $organisation->primary_contact_email);
        $this->assertSame(OrganisationStatus::Active, $organisation->status);
        $this->assertSame($admin->id, $organisation->created_by);
        $this->assertNotEmpty($organisation->uuid);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'organisation.created',
            'user_id' => $admin->id,
            'organisation_id' => $organisation->id,
            'resource_id' => $organisation->uuid,
        ]);
    }

    public function test_creation_validates_input_and_rejects_duplicate_codes(): void
    {
        Organisation::factory()->create(['code' => 'ACME']);

        $this->actingAs($this->superAdmin())
            ->post('/organisations', $this->payload(['name' => '', 'pan' => 'BAD', 'gstin' => '123', 'billing_email' => 'nope']))
            ->assertSessionHasErrors(['name', 'code', 'pan', 'gstin', 'billing_email']);

        $this->assertSame(1, Organisation::query()->count());
    }

    public function test_super_admin_can_update_but_the_code_is_immutable(): void
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create(['code' => 'ORIG', 'name' => 'Old Name']);

        $this->actingAs($admin)
            ->put(route('organisations.update', $organisation), $this->payload(['name' => 'New Name', 'code' => 'CHANGED']))
            ->assertRedirect(route('organisations.show', $organisation));

        $organisation->refresh();
        $this->assertSame('New Name', $organisation->name);
        $this->assertSame('ORIG', $organisation->code);
        $this->assertSame($admin->id, $organisation->updated_by);

        $entry = AuditLog::query()->where('action', 'organisation.updated')->firstOrFail();
        $this->assertSame('Old Name', $entry->previous_data['name']);
        $this->assertSame('New Name', $entry->new_data['name']);
    }

    public function test_status_change_requires_a_reason_is_audited_and_deletes_nothing(): void
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create();
        $this->organisationManager($organisation);
        OrganisationDomainFactory::new()->create(['organisation_id' => $organisation->id]);

        $this->actingAs($admin)
            ->post(route('organisations.status', $organisation), ['status' => 'inactive'])
            ->assertSessionHasErrors('reason');

        $this->post(route('organisations.status', $organisation), ['status' => 'inactive', 'reason' => 'Contract ended'])
            ->assertRedirect(route('organisations.show', $organisation));

        $this->assertSame(OrganisationStatus::Inactive, $organisation->fresh()->status);
        $this->assertNull($organisation->fresh()->deleted_at);
        $this->assertDatabaseCount('organisation_user_roles', 1);
        $this->assertDatabaseCount('organisation_domains', 1);

        $entry = AuditLog::query()->where('action', 'organisation.status_changed')->firstOrFail();
        $this->assertSame('active', $entry->previous_data['status']);
        $this->assertSame(['status' => 'inactive', 'reason' => 'Contract ended'], $entry->new_data);
    }

    public function test_suspended_organisation_is_read_only_for_its_managers(): void
    {
        $organisation = Organisation::factory()->status(OrganisationStatus::Suspended)->create();
        $manager = $this->organisationManager($organisation);

        $this->assertTrue($manager->hasAbility(Ability::OrganisationsView, $organisation));
        $this->assertTrue($manager->hasAbility(Ability::AccountsView, $organisation));
        $this->assertTrue($manager->hasAbility(Ability::InvoicesView, $organisation));
        $this->assertTrue($manager->hasAbility(Ability::PaymentsInitiate, $organisation));

        foreach ([Ability::AccountsCreate, Ability::AccountsSuspend, Ability::DomainsRequest, Ability::OrganisationsManageContacts] as $ability) {
            $this->assertFalse($manager->hasAbility($ability, $organisation), "{$ability->value} must be read-only");
        }

        $this->actingAs($manager)->get(route('organisations.show', $organisation))->assertOk()->assertSee('read-only access');
        $this->assertTrue($this->superAdmin()->hasAbility(Ability::AccountsCreate, $organisation));
    }

    public function test_inactive_organisation_is_inaccessible_to_its_managers_and_recoverable(): void
    {
        $organisation = Organisation::factory()->status(OrganisationStatus::Inactive)->create();
        $manager = $this->organisationManager($organisation);

        $this->assertFalse($manager->hasAbility(Ability::OrganisationsView, $organisation));
        $this->actingAs($manager)->get(route('organisations.show', $organisation))->assertNotFound();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('organisations.show', $organisation))->assertOk();
        $this->post(route('organisations.status', $organisation), ['status' => 'active', 'reason' => 'Renewed']);

        app(AccessResolver::class)->forget();
        $this->assertTrue($manager->hasAbility(Ability::AccountsCreate, $organisation->fresh()));
    }

    public function test_organisation_users_cannot_create_update_or_change_status(): void
    {
        $organisation = Organisation::factory()->create(['name' => 'Untouched']);

        foreach ([$this->organisationManager($organisation), $this->billingManager($organisation), $this->operationsAdmin($organisation)] as $user) {
            $this->actingAs($user);

            $this->get('/organisations/create')->assertForbidden();
            $this->post('/organisations', $this->payload(['code' => 'X'.$user->id]))->assertForbidden();
            $this->get(route('organisations.edit', $organisation))->assertForbidden();
            $this->put(route('organisations.update', $organisation), $this->payload(['name' => 'Hacked']))->assertForbidden();
            $this->post(route('organisations.status', $organisation), ['status' => 'inactive', 'reason' => 'nope'])->assertForbidden();
        }

        $this->assertSame(1, Organisation::query()->count());
        $this->assertSame('Untouched', $organisation->fresh()->name);
        $this->assertSame(OrganisationStatus::Active, $organisation->fresh()->status);
    }

    public function test_another_organisations_pages_do_not_exist_for_a_manager(): void
    {
        [$mine, $theirs] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($mine);

        $this->actingAs($manager);
        $this->get(route('organisations.show', $mine))->assertOk();

        // UUID substitution on every organisation-scoped route.
        $this->get(route('organisations.show', $theirs))->assertNotFound();
        $this->get(route('organisations.edit', $theirs))->assertNotFound();
        $this->put(route('organisations.update', $theirs), $this->payload())->assertNotFound();
        $this->post(route('organisations.status', $theirs), ['status' => 'inactive', 'reason' => 'x'])->assertNotFound();
        $this->post(route('organisations.members.store', $theirs), ['email' => 'a@b.example', 'role' => 'organisation_manager'])->assertNotFound();
        $this->post(route('organisations.contacts.store', $theirs), ['type' => 'general', 'name' => 'X', 'email' => 'x@y.example'])->assertNotFound();
        $this->post(route('organisations.domains.store', $theirs), ['domain_name' => 'evil.example', 'domain_type' => 'secondary'])->assertNotFound();
        $this->get('/organisations/'.$theirs->id)->assertNotFound();

        $this->assertDatabaseCount('organisation_contacts', 0);
        $this->assertDatabaseCount('organisation_domains', 0);
    }

    public function test_list_search_and_filter_are_tenant_scoped(): void
    {
        $alpha = Organisation::factory()->create(['name' => 'Alpha Mills', 'code' => 'ALPHA']);
        $beta = Organisation::factory()->status(OrganisationStatus::Suspended)->create(['name' => 'Beta Mills', 'code' => 'BETA']);
        Organisation::factory()->create(['name' => 'Gamma Mills', 'code' => 'GAMMA']);
        $manager = $this->organisationManager($alpha, $beta);

        $this->actingAs($manager)->get('/organisations')->assertOk();

        Livewire::actingAs($manager)->test(OrganisationTable::class)
            ->assertSee('Alpha Mills')->assertSee('Beta Mills')->assertDontSee('Gamma Mills')
            ->set('search', 'Mills')->assertDontSee('Gamma Mills')
            ->set('search', 'GAMMA')->assertDontSee('Gamma Mills')->assertSee('No organisations match')
            ->set('search', '')->set('status', 'suspended')->assertSee('Beta Mills')->assertDontSee('Alpha Mills');

        Livewire::actingAs($this->superAdmin())->test(OrganisationTable::class)
            ->set('search', 'gamma')->assertSee('Gamma Mills')->assertDontSee('Alpha Mills');
    }

    public function test_list_is_paginated(): void
    {
        Organisation::factory()->count(25)->create();

        Livewire::actingAs($this->superAdmin())->test(OrganisationTable::class)
            ->assertViewHas('organisations', fn ($page) => $page->count() === 20 && $page->total() === 25);
    }

    public function test_user_without_any_organisation_cannot_open_the_list(): void
    {
        $this->actingAs(User::factory()->create())->get('/organisations')->assertForbidden();
    }

    public function test_csv_export_contains_only_visible_organisations_and_is_audited(): void
    {
        $mine = Organisation::factory()->create(['name' => 'Mine Co', 'code' => 'MINE']);
        Organisation::factory()->create(['name' => 'Hidden Co', 'code' => 'HIDDEN']);
        $manager = $this->organisationManager($mine);

        $csv = $this->actingAs($manager)->get('/organisations/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Mine Co', $csv);
        $this->assertStringNotContainsString('Hidden Co', $csv);
        $this->assertStringStartsWith('Code,Name', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organisation.exported', 'user_id' => $manager->id]);
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(1)", OrganisationExporter::cell('=HYPERLINK(1)'));
        $this->assertSame("'+1", OrganisationExporter::cell('+1'));
        $this->assertSame("'@cmd", OrganisationExporter::cell('@cmd'));
        $this->assertSame('Plain', OrganisationExporter::cell('Plain'));

        Organisation::factory()->create(['name' => '=1+1']);
        $csv = $this->actingAs($this->superAdmin())->get('/organisations/export')->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_activity_page_is_restricted_and_lists_organisation_events(): void
    {
        $admin = $this->superAdmin();
        $organisation = Organisation::factory()->create(['name' => 'Audited Co']);
        $other = Organisation::factory()->create(['name' => 'Other Co']);

        $this->actingAs($admin)->post(route('organisations.status', $organisation), ['status' => 'suspended', 'reason' => 'Overdue']);
        $this->post(route('organisations.status', $other), ['status' => 'suspended', 'reason' => 'Overdue']);

        $this->get('/organisations/activity')->assertOk()->assertSee('organisation.status_changed')->assertSee('Audited Co');
        $this->get('/organisations/activity?organisation='.$organisation->uuid)
            ->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 1);

        $this->actingAs($this->organisationManager($organisation))->get('/organisations/activity')->assertForbidden();
    }

    public function test_organisation_profile_shows_the_current_organisation_only(): void
    {
        [$mine, $theirs] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($mine);

        $this->actingAs($manager)->get('/organisation/profile')
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name)
            ->assertDontSee('Grant access')
            ->assertDontSee('Map domain');

        $this->actingAs(User::factory()->create())->get('/organisation/profile')->assertNotFound();
    }
}
