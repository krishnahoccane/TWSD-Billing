<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Livewire\AccountTable;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Services\OrganisationService;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class AccountIsolationTest extends WorkspaceTestCase
{
    public function test_organisation_a_cannot_see_or_touch_organisation_b_accounts(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $mine = $this->existingAccount($a, 'mine@alpha.test');
        $theirs = $this->existingAccount($b, 'theirs@beta.test');

        $this->actingAs($this->organisationManager($a));

        $this->get(route('accounts.show', $mine))->assertOk();

        // UUID substitution on every account route.
        $this->get(route('accounts.show', $theirs))->assertNotFound();
        $this->get(route('accounts.edit', $theirs))->assertNotFound();
        $this->put(route('accounts.update', $theirs), ['first_name' => 'X', 'last_name' => 'Y'])->assertNotFound();
        $this->post(route('accounts.suspend', $theirs), ['reason' => 'attack'])->assertNotFound();
        $this->post(route('accounts.reactivate', $theirs))->assertNotFound();
        $this->post(route('accounts.soft-delete', $theirs), ['reason' => 'attack', 'confirm_email' => 'theirs@beta.test'])->assertNotFound();
        $this->post(route('accounts.restore', $theirs))->assertNotFound();
        $this->post(route('accounts.reveal', $theirs))->assertNotFound();
        $this->post(route('accounts.assign', $theirs), ['organisation' => $a->uuid])->assertNotFound();
        $this->get('/accounts/'.$theirs->id)->assertNotFound();

        $theirs->refresh();
        $this->assertSame(AccountStatus::Active, $theirs->account_status);
        $this->assertSame($b->id, $theirs->organisation_id);
        $this->assertFalse($this->workspace()->getUser($theirs->google_user_id)->suspended);
    }

    public function test_account_list_is_tenant_scoped(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $this->existingAccount($a, 'mine@alpha.test');
        $this->existingAccount($b, 'theirs@beta.test');
        $manager = $this->organisationManager($a);

        $this->actingAs($manager)->get('/accounts')->assertOk();

        Livewire::actingAs($manager)->test(AccountTable::class)
            ->assertSee('mine@alpha.test')->assertDontSee('theirs@beta.test')
            ->set('search', 'theirs')->assertDontSee('theirs@beta.test')
            // A foreign organisation uuid in the filter is ignored, not obeyed.
            ->set('search', '')->set('organisation', $b->uuid)->assertDontSee('theirs@beta.test');

        Livewire::actingAs($this->superAdmin())->test(AccountTable::class)
            ->assertSee('mine@alpha.test')->assertSee('theirs@beta.test')
            ->set('organisation', $b->uuid)->assertSee('theirs@beta.test')->assertDontSee('mine@alpha.test');
    }

    public function test_the_locked_preset_cannot_be_changed_from_the_browser(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->organisationManager($a))->test(AccountTable::class)->set('preset', 'unassigned');
    }

    public function test_a_manager_cannot_create_a_mailbox_in_another_organisation_or_on_its_domain(): void
    {
        [$a, $domainA] = $this->organisationWithDomain('alpha.test');
        [$b, $domainB] = $this->organisationWithDomain('beta.test');
        $base = ['username' => 'intruder', 'first_name' => 'In', 'last_name' => 'Truder'];

        $this->actingAs($this->organisationManager($a));

        // Another organisation's uuid: treated as non-existent.
        $this->post('/accounts', $base + ['organisation' => $b->uuid, 'domain' => $domainB->uuid])->assertNotFound();
        // Own organisation but another organisation's domain.
        $this->post('/accounts', $base + ['organisation' => $a->uuid, 'domain' => $domainB->uuid])->assertStatus(422);

        $this->assertSame(0, GoogleAccount::query()->count());
        $this->assertSame(0, $this->workspace()->callCount('users.insert'));

        $this->post('/accounts', $base + ['organisation' => $a->uuid, 'domain' => $domainA->uuid])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('google_accounts', ['primary_email' => 'intruder@alpha.test', 'organisation_id' => $a->id]);
    }

    public function test_billing_managers_have_no_access_to_mailboxes(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('alpha.test');
        $account = $this->existingAccount($organisation, 'staff@alpha.test');

        $this->actingAs($this->billingManager($organisation));

        $this->get('/accounts')->assertForbidden();
        $this->get('/accounts/create')->assertForbidden();
        $this->get(route('accounts.show', $account))->assertNotFound();
        $this->post(route('accounts.suspend', $account), ['reason' => 'nope'])->assertNotFound();
        $this->post('/accounts', ['organisation' => $organisation->uuid, 'domain' => $domain->uuid,
            'username' => 'x', 'first_name' => 'X', 'last_name' => 'Y'])->assertNotFound();

        $this->assertSame(AccountStatus::Active, $account->fresh()->account_status);
    }

    public function test_managers_cannot_use_admin_only_account_functions(): void
    {
        [$organisation] = $this->organisationWithDomain('alpha.test');
        $deleted = $this->existingAccount($organisation, 'old@alpha.test', AccountStatus::SoftDeleted);
        $active = $this->existingAccount($organisation, 'now@alpha.test');
        $this->workspace()->seedUser('orphan@unmapped.test');
        app(GoogleDirectoryImporter::class)->importUsers();
        $orphan = GoogleAccount::query()->whereNull('organisation_id')->firstOrFail();

        $this->actingAs($this->organisationManager($organisation));

        $this->get('/accounts/unassigned')->assertForbidden();
        $this->get(route('accounts.show', $orphan))->assertNotFound();
        $this->post(route('accounts.assign', $orphan), ['organisation' => $organisation->uuid])->assertNotFound();
        $this->post(route('accounts.assign', $active), ['organisation' => $organisation->uuid])->assertForbidden();
        $this->post(route('accounts.restore', $deleted))->assertForbidden();
        $this->post(route('accounts.deletion-requests.store', $deleted), ['reason' => 'x'])->assertForbidden();
        $this->get('/integrations/google')->assertForbidden();
        $this->get('/integrations/logs')->assertForbidden();
        $this->post('/integrations/google/test')->assertForbidden();

        $this->assertNull($orphan->fresh()->organisation_id);
    }

    public function test_suspended_and_inactive_organisations_restrict_their_managers(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('alpha.test');
        $account = $this->existingAccount($organisation, 'staff@alpha.test');
        $manager = $this->organisationManager($organisation);
        $admin = $this->superAdmin();
        $organisations = app(OrganisationService::class);

        $organisations->changeStatus($organisation, OrganisationStatus::Suspended, 'Overdue', $admin);

        $this->actingAs($manager);
        $this->get(route('accounts.show', $account))->assertOk();
        $this->post(route('accounts.suspend', $account), ['reason' => 'x y z'])->assertForbidden();
        $this->post('/accounts', ['organisation' => $organisation->uuid, 'domain' => $domain->uuid,
            'username' => 'new', 'first_name' => 'N', 'last_name' => 'P'])->assertNotFound();

        $organisations->changeStatus($organisation->fresh(), OrganisationStatus::Inactive, 'Closed', $admin);

        $this->get(route('accounts.show', $account))->assertNotFound();
        // Nothing happened to the mailbox itself.
        $this->assertSame(AccountStatus::Active, $account->fresh()->account_status);
        $this->assertFalse($this->workspace()->getUser($account->google_user_id)->suspended);
    }

    public function test_api_enforces_the_same_isolation_and_policies(): void
    {
        [$a, $domainA] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $mine = $this->existingAccount($a, 'mine@alpha.test');
        $theirs = $this->existingAccount($b, 'theirs@beta.test');
        $deleted = $this->existingAccount($a, 'old@alpha.test', AccountStatus::SoftDeleted);

        $this->getJson('/api/v1/accounts')->assertUnauthorized();

        Sanctum::actingAs($this->organisationManager($a));

        $list = $this->getJson('/api/v1/accounts')->assertOk();
        $this->assertEqualsCanonicalizing([$mine->uuid, $deleted->uuid], $list->json('data.*.id'));
        $this->assertStringNotContainsString('theirs@beta.test', $list->getContent());
        $this->assertStringNotContainsString('google_user_id', $list->getContent());
        $this->getJson('/api/v1/accounts?organisation='.$b->uuid)->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/accounts/'.$theirs->uuid)->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$theirs->uuid.'/history')->assertNotFound();
        $this->postJson('/api/v1/accounts/'.$theirs->uuid.'/suspend', ['reason' => 'attack'])->assertNotFound();
        $this->postJson('/api/v1/accounts/'.$theirs->uuid.'/soft-delete', ['reason' => 'attack', 'confirm_email' => 'theirs@beta.test'])->assertNotFound();
        $this->putJson('/api/v1/accounts/'.$theirs->uuid, ['first_name' => 'X', 'last_name' => 'Y'])->assertNotFound();

        // Allowed in the caller's own organisation.
        $this->postJson('/api/v1/accounts', ['organisation' => $a->uuid, 'domain' => $domainA->uuid,
            'username' => 'api.user', 'first_name' => 'Api', 'last_name' => 'User'])
            ->assertStatus(202)->assertJsonPath('data.primary_email', 'api.user@alpha.test')->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/accounts/'.$mine->uuid.'/suspend', ['reason' => 'Leaver'])->assertStatus(202)->assertJsonPath('data.status', 'suspended');
        $this->getJson('/api/v1/accounts/'.$mine->uuid.'/history')->assertOk()->assertJsonPath('data.0.event', 'suspended');

        // Manager-forbidden operations.
        $this->postJson('/api/v1/accounts/'.$deleted->uuid.'/restore')->assertForbidden();
        $this->postJson('/api/v1/accounts/'.$deleted->uuid.'/permanent-delete', ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/integrations/status')->assertForbidden();
        $this->postJson('/api/v1/integrations/google/test')->assertForbidden();

        $this->assertSame(AccountStatus::Active, $theirs->fresh()->account_status);
        $this->assertNotNull($this->workspace()->getUser($deleted->google_user_id));
    }

    public function test_api_permanent_delete_requires_two_super_admins(): void
    {
        [$organisation] = $this->organisationWithDomain('alpha.test');
        $account = $this->existingAccount($organisation, 'gone@alpha.test', AccountStatus::SoftDeleted);
        $url = '/api/v1/accounts/'.$account->uuid.'/permanent-delete';
        $checks = ['reason' => 'Contract ended', 'ownership_verified' => true, 'retention_checked' => true,
            'vault_checked' => true, 'dependencies_checked' => true];

        Sanctum::actingAs($first = $this->superAdmin());
        $this->postJson($url, ['reason' => 'No checks'])->assertStatus(422);
        $this->postJson($url, $checks)->assertStatus(202)->assertJsonPath('data.status', 'pending');
        // Same admin tries to approve.
        $this->postJson($url, ['approve' => true, 'confirm_email' => 'gone@alpha.test'])->assertStatus(422);
        $this->assertNotNull($this->workspace()->getUser($account->google_user_id));

        Sanctum::actingAs($this->superAdmin());
        $this->postJson($url, ['approve' => true, 'confirm_email' => 'wrong'])->assertStatus(422);
        $this->postJson($url, ['approve' => true, 'confirm_email' => 'gone@alpha.test'])->assertStatus(202)->assertJsonPath('data.status', 'executed');

        $this->assertNull($this->workspace()->getUser($account->google_user_id));
        $this->assertSame(AccountStatus::PermanentlyDeleted, $account->fresh()->account_status);
        $this->assertNotSame($first->id, $account->deletionRequests()->first()->decided_by);
    }
}
