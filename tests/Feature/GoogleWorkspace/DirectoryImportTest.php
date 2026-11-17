<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleUsersJob;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleSyncLog;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;
use App\Modules\Organisations\Models\Organisation;
use Database\Factories\OrganisationDomainFactory;
use Illuminate\Support\Facades\Queue;

class DirectoryImportTest extends WorkspaceTestCase
{
    private function importer(): GoogleDirectoryImporter
    {
        return app(GoogleDirectoryImporter::class);
    }

    public function test_six_hundred_users_are_imported_across_every_page(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $this->workspace()->seedUsers(600, ['alpha.test', 'beta.test', 'unmapped.test']);

        $log = $this->importer()->importUsers(flagMissing: true);

        // 500 per page => two pages; the first response is not the whole directory.
        $this->assertSame(2, $log->pages);
        $this->assertSame(2, $this->workspace()->callCount('users.list'));
        $this->assertSame(600, $log->fetched);
        $this->assertSame(600, $log->created);
        $this->assertSame(0, $log->errors);
        $this->assertSame(GoogleSyncLog::COMPLETED, $log->status);
        $this->assertSame(600, GoogleAccount::query()->count());
        $this->assertSame(600, GoogleAccount::query()->distinct()->count('google_user_id'));

        // Matched by domain; unmatched users are NOT auto-assigned.
        $this->assertSame(200, GoogleAccount::query()->where('organisation_id', $a->id)->count());
        $this->assertSame(200, GoogleAccount::query()->where('organisation_id', $b->id)->count());
        $this->assertSame(200, GoogleAccount::query()->whereNull('organisation_id')->count());
        $this->assertSame(200, $log->unassigned);
        $this->assertSame(400, GoogleAccount::query()->where('billing_status', BillingStatus::Billable)->count());
        $this->assertSame(200, GoogleAccount::query()->whereNull('organisation_id')->where('billing_status', BillingStatus::Pending)->count());
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->organisationWithDomain('alpha.test');
        $this->workspace()->seedUsers(30, ['alpha.test']);

        $this->importer()->importUsers();
        $second = $this->importer()->importUsers();

        $this->assertSame(30, GoogleAccount::query()->count());
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
    }

    public function test_sync_never_changes_ownership_even_when_the_address_moves_domain(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $account = $this->existingAccount($a, 'mover@alpha.test');

        // Renamed in Google onto organisation B's domain.
        $this->workspace()->reset();
        $this->workspace()->seedUser('placeholder@alpha.test');
        $moved = $this->workspace()->seedUser('mover@beta.test', 'Moved', 'Person');
        $account->forceFill(['google_user_id' => $moved->id])->save();

        $this->importer()->importUsers();

        $account->refresh();
        $this->assertSame('mover@beta.test', $account->primary_email);
        $this->assertSame('Moved', $account->first_name);
        $this->assertSame($a->id, $account->organisation_id, 'Ownership must not follow the domain.');
        $this->assertNotSame($b->id, $account->organisation_id);
    }

    public function test_status_drift_from_google_is_reconciled(): void
    {
        [$organisation] = $this->organisationWithDomain('alpha.test');
        $active = $this->existingAccount($organisation, 'one@alpha.test');
        $suspended = $this->existingAccount($organisation, 'two@alpha.test', AccountStatus::Suspended);
        $busy = $this->existingAccount($organisation, 'three@alpha.test');
        $busy->forceFill(['pending_operation' => AccountOperation::Reactivate, 'account_status' => AccountStatus::ReactivationPending])->save();

        $this->workspace()->suspendUser($active->google_user_id);
        $this->workspace()->reactivateUser($suspended->google_user_id);
        $this->workspace()->suspendUser($busy->google_user_id);

        $this->importer()->importUsers();

        $this->assertSame(AccountStatus::Suspended, $active->fresh()->account_status);
        $this->assertSame(AccountStatus::Active, $suspended->fresh()->account_status);
        // An in-flight operation is left alone.
        $this->assertSame(AccountStatus::ReactivationPending, $busy->fresh()->account_status);
        $this->assertSame('status_reconciled', $active->history()->first()->event);
        // Suspended in Google is still billable.
        $this->assertSame(BillingStatus::Billable, $active->fresh()->billing_status);
    }

    public function test_full_import_flags_accounts_missing_from_google_without_deleting_them(): void
    {
        [$organisation] = $this->organisationWithDomain('alpha.test');
        $kept = $this->existingAccount($organisation, 'kept@alpha.test');
        $vanished = $this->existingAccount($organisation, 'vanished@alpha.test');
        $this->workspace()->deleteUser($vanished->google_user_id);

        $partial = $this->importer()->importUsers(flagMissing: false);
        $this->assertNull($vanished->fresh()->missing_from_google_at);
        $this->assertSame(0, $partial->missing);

        $full = $this->importer()->importUsers(flagMissing: true);

        $this->assertSame(1, $full->missing);
        $this->assertNotNull($vanished->fresh()->missing_from_google_at);
        $this->assertNull($kept->fresh()->missing_from_google_at);
        $this->assertSame(AccountStatus::Active, $vanished->fresh()->account_status);
        $this->assertSame(2, GoogleAccount::query()->count());
    }

    public function test_a_failed_listing_is_recorded_and_rethrown_for_retry(): void
    {
        $this->workspace()->seedUsers(5, ['alpha.test']);
        $this->workspace()->failNext('users.list', new WorkspaceTransientException('Backend Error'));

        try {
            $this->importer()->importUsers();
            $this->fail('The failure must propagate so the job is retried.');
        } catch (WorkspaceTransientException) {
            $log = GoogleSyncLog::query()->firstOrFail();
            $this->assertSame(GoogleSyncLog::FAILED, $log->status);
            $this->assertStringContainsString('Backend Error', $log->error_message);
            $this->assertNotNull($log->finished_at);
        }

        $this->assertSame(5, $this->importer()->importUsers()->created);
    }

    public function test_domain_sync_reports_unmapped_and_missing_domains(): void
    {
        [, $verified] = $this->organisationWithDomain('alpha.test');
        $missing = OrganisationDomainFactory::new()->create(['domain_name' => 'not-in-google.test']);
        $this->workspace()->seedDomain('extra.test');
        $this->workspace()->seedDomain('pending.test', verified: false);
        OrganisationDomainFactory::new()->create(['domain_name' => 'pending.test']);
        $this->workspace()->createOrgUnit('Sales');

        $log = $this->importer()->importDomains();

        $this->assertSame('verified', $verified->fresh()->verification_status);
        $this->assertSame('not_found', $missing->fresh()->verification_status);
        $this->assertDatabaseHas('organisation_domains', ['domain_name' => 'pending.test', 'verification_status' => 'pending']);
        $this->assertSame(['extra.test'], $log->details['unmapped_google_domains']);
        // A domain found in Google is never auto-assigned to an organisation.
        $this->assertDatabaseMissing('organisation_domains', ['domain_name' => 'extra.test']);
        $this->assertDatabaseHas('google_organisational_units', ['path' => '/Sales']);
    }

    public function test_super_admin_assigns_an_unassigned_account_explicitly(): void
    {
        $organisation = Organisation::factory()->create();
        $this->workspace()->seedUser('orphan@unmapped.test');
        $this->importer()->importUsers();
        $account = GoogleAccount::query()->firstOrFail();
        $admin = $this->superAdmin();

        $this->assertNull($account->organisation_id);
        $this->actingAs($admin)->get('/accounts/unassigned')->assertOk();
        $this->get(route('accounts.show', $account))->assertOk()->assertSee('Assign to an organisation');

        $this->post(route('accounts.assign', $account), ['organisation' => $organisation->uuid])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame($organisation->id, $account->organisation_id);
        $this->assertSame(BillingStatus::Billable, $account->billing_status);
        $this->assertNotNull($account->billable_from);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.organisation_assigned', 'user_id' => $admin->id, 'organisation_id' => $organisation->id,
        ]);
    }

    public function test_sync_screen_runs_immediately_without_the_queue_and_is_restricted(): void
    {
        [$organisation] = $this->organisationWithDomain('alpha.test');
        $this->workspace()->seedUsers(3, ['alpha.test']);
        Queue::fake();

        $this->actingAs($this->organisationManager($organisation));
        $this->get('/workspace/sync')->assertForbidden();
        $this->post('/workspace/sync', ['type' => 'users_full'])->assertForbidden();
        $this->assertSame(0, GoogleSyncLog::query()->count());

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/workspace/sync')->assertOk()->assertSee('Mock mode');
        $this->post('/workspace/sync', ['type' => 'users_full'])
            ->assertRedirect()->assertSessionHas('success', fn (string $m) => str_contains($m, '3 fetched, 3 new'));
        $this->post('/workspace/sync', ['type' => 'domains'])->assertSessionHas('success');
        $this->post('/workspace/sync', ['type' => 'drop_everything'])->assertSessionHasErrors('type');

        // Done in the request: nothing waits for a queue worker.
        Queue::assertNothingPushed();
        $this->assertSame(3, GoogleAccount::query()->where('organisation_id', $organisation->id)->count());
        $this->assertDatabaseHas('google_sync_logs', ['type' => 'users_full', 'status' => 'completed', 'triggered_by' => $admin->id]);
        $this->assertDatabaseHas('google_sync_logs', ['type' => 'domains', 'status' => 'completed', 'triggered_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.sync_requested', 'user_id' => $admin->id]);
    }

    public function test_a_failed_immediate_sync_is_reported_and_logged(): void
    {
        $this->workspace()->failNext('users.list', new WorkspaceTransientException('Google is unavailable'));

        $this->actingAs($this->superAdmin())->post('/workspace/sync', ['type' => 'users_sync'])
            ->assertRedirect()->assertSessionHas('error', fn (string $m) => str_contains($m, 'Google is unavailable'));

        $this->assertDatabaseHas('google_sync_logs', ['type' => 'users_sync', 'status' => 'failed']);
    }

    public function test_import_job_runs_the_full_import(): void
    {
        $this->organisationWithDomain('alpha.test');
        $this->workspace()->seedUsers(3, ['alpha.test']);

        ImportGoogleUsersJob::dispatch();

        $this->assertSame(3, GoogleAccount::query()->count());
        $this->assertDatabaseHas('google_sync_logs', ['type' => 'users_full', 'status' => 'completed', 'fetched' => 3]);
    }
}
