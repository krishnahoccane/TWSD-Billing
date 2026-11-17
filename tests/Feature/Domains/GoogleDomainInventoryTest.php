<?php

namespace Tests\Feature\Domains;

use App\Modules\Domains\Models\GoogleDomain;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;
use App\Modules\Organisations\Models\Organisation;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

class GoogleDomainInventoryTest extends WorkspaceTestCase
{
    private function syncDomains(): void
    {
        app(GoogleDirectoryImporter::class)->importDomains();
    }

    public function test_domain_sync_stores_every_google_domain_and_alias_without_assigning_them(): void
    {
        [$alpha, $mapped] = $this->organisationWithDomain('alpha.test');
        $this->workspace()->seedDomain('platform.test', isPrimary: true);
        $this->workspace()->seedDomain('newco.test', verified: false);
        $this->workspace()->seedDomain('newco-alias.test', aliasOf: 'newco.test');

        $this->syncDomains();

        $this->assertSame(4, GoogleDomain::query()->count());
        $this->assertSame($mapped->id, GoogleDomain::query()->where('domain_name', 'alpha.test')->value('organisation_domain_id'));
        $this->assertEqualsCanonicalizing(
            ['platform.test', 'newco.test', 'newco-alias.test'],
            GoogleDomain::query()->unassigned()->pluck('domain_name')->all(),
        );
        $this->assertSame('newco.test', GoogleDomain::query()->where('domain_name', 'newco-alias.test')->value('alias_of'));
        // Nothing is assigned automatically.
        $this->assertSame(1, OrganisationDomain::query()->count());

        // Re-running is idempotent; a domain gone from Google is flagged, not deleted.
        $this->workspace()->reset();
        $this->workspace()->seedDomain('alpha.test');
        $this->syncDomains();

        $this->assertSame(4, GoogleDomain::query()->count());
        $this->assertNotNull(GoogleDomain::query()->where('domain_name', 'newco.test')->value('missing_from_google_at'));
        $this->assertSame(0, GoogleDomain::query()->unassigned()->count());
    }

    public function test_super_admin_assigns_an_unassigned_domain_and_its_mailboxes(): void
    {
        [$owner] = $this->organisationWithDomain('owner.test');
        $target = Organisation::factory()->create();
        $this->workspace()->seedDomain('newco.test', verified: true);
        $this->workspace()->seedUsers(3, ['newco.test']);
        app(GoogleDirectoryImporter::class)->importUsers();
        $this->syncDomains();

        // A mailbox on that domain that already belongs to someone is never moved.
        $owned = GoogleAccount::query()->where('primary_email', 'user0001@newco.test')->firstOrFail();
        $owned->forceFill(['organisation_id' => $owner->id])->save();

        $admin = $this->superAdmin();
        $domain = GoogleDomain::query()->where('domain_name', 'newco.test')->firstOrFail();

        $this->actingAs($admin)->get('/domains/unassigned')->assertOk()->assertSee('newco.test')->assertDontSee('owner.test');
        $this->get('/domains')->assertOk()->assertSee('Unassigned');

        $this->post("/domains/unassigned/{$domain->uuid}/assign", ['organisation' => $target->uuid, 'with_accounts' => '1'])
            ->assertRedirect(route('domains.unassigned'))
            ->assertSessionHas('success');

        $mapping = OrganisationDomain::query()->where('domain_name', 'newco.test')->firstOrFail();
        $this->assertSame($target->id, $mapping->organisation_id);
        $this->assertSame('verified', $mapping->verification_status);
        $this->assertSame($mapping->id, $domain->fresh()->organisation_domain_id);
        $this->assertSame(2, GoogleAccount::query()->where('organisation_id', $target->id)->count());
        $this->assertSame(2, GoogleAccount::query()->where('organisation_id', $target->id)->where('billing_status', BillingStatus::Billable)->count());
        $this->assertSame($owner->id, $owned->fresh()->organisation_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.mapped', 'organisation_id' => $target->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.organisation_assigned', 'organisation_id' => $target->id]);

        // Now listed under All domains, gone from Unassigned, and cannot be assigned twice.
        $this->get('/domains')->assertSee('newco.test');
        $this->get('/domains/unassigned')->assertDontSee('user0002@newco.test');
        $this->post("/domains/unassigned/{$domain->uuid}/assign", ['organisation' => $owner->uuid])
            ->assertSessionHasErrors('organisation');
        $this->assertSame(1, OrganisationDomain::query()->where('domain_name', 'newco.test')->count());
    }

    public function test_assigning_without_mailboxes_leaves_them_unassigned(): void
    {
        $target = Organisation::factory()->create();
        $this->workspace()->seedDomain('newco.test');
        $this->workspace()->seedUsers(2, ['newco.test']);
        app(GoogleDirectoryImporter::class)->importUsers();
        $this->syncDomains();
        $domain = GoogleDomain::query()->where('domain_name', 'newco.test')->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post("/domains/unassigned/{$domain->uuid}/assign", ['organisation' => $target->uuid])
            ->assertRedirect();

        $this->assertDatabaseHas('organisation_domains', ['domain_name' => 'newco.test', 'organisation_id' => $target->id]);
        $this->assertSame(2, GoogleAccount::query()->whereNull('organisation_id')->count());
    }

    public function test_organisation_users_cannot_see_or_assign_google_domains(): void
    {
        [$alpha] = $this->organisationWithDomain('alpha.test');
        [$beta] = $this->organisationWithDomain('beta.test');
        $this->workspace()->seedDomain('newco.test');
        $this->syncDomains();
        $domain = GoogleDomain::query()->where('domain_name', 'newco.test')->firstOrFail();

        $manager = $this->organisationManager($alpha);
        $this->actingAs($manager);

        $this->get('/domains')->assertOk()->assertSee('alpha.test')->assertDontSee('beta.test')
            ->assertDontSee('newco.test')->assertDontSee('Sync domains from Google');
        $this->get('/domains/unassigned')->assertForbidden();
        // Not even to their own organisation, and the inventory id does not resolve.
        $this->post("/domains/unassigned/{$domain->uuid}/assign", ['organisation' => $alpha->uuid])->assertNotFound();
        $this->post('/workspace/sync', ['type' => 'domains'])->assertForbidden();

        $this->assertNull($domain->fresh()->organisation_domain_id);
        $this->assertSame(0, OrganisationDomain::query()->where('domain_name', 'newco.test')->count());
    }

    public function test_sync_button_on_the_domains_page_runs_the_domain_sync_immediately(): void
    {
        $this->workspace()->seedDomain('newco.test');

        $this->actingAs($this->superAdmin())
            ->from('/domains')
            ->post('/workspace/sync', ['type' => 'domains'])
            ->assertRedirect('/domains')
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 found in Google, 1 new, 1 not assigned'));

        $this->assertDatabaseHas('google_domains', ['domain_name' => 'newco.test', 'organisation_domain_id' => null]);
    }
}
