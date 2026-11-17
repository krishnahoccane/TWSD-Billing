<?php

namespace Tests\Feature\GoogleWorkspace;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Jobs\CreateGoogleUserJob;
use App\Modules\GoogleWorkspace\Jobs\ReconcileGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Jobs\SuspendGoogleUserJob;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\AccountSecret;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleAccountHistory;
use App\Modules\GoogleWorkspace\Models\GoogleApiLog;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;
use App\Modules\GoogleWorkspace\Services\InitialPasswordVault;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Queue;

class AccountLifecycleTest extends WorkspaceTestCase
{
    private function createPayload($organisation, $domain, array $overrides = []): array
    {
        return $overrides + [
            'organisation' => $organisation->uuid,
            'domain' => $domain->uuid,
            'username' => 'Priya.Sharma',
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'recovery_email' => 'priya@personal.example',
            'recovery_phone' => '+919876543210',
        ];
    }

    public function test_manager_creates_a_mailbox_through_the_mock_provider(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->get('/accounts/create')->assertOk()->assertSee('acme.test');
        $this->post('/accounts', $this->createPayload($organisation, $domain))->assertSessionHasNoErrors()->assertRedirect();

        $account = GoogleAccount::query()->where('primary_email', 'priya.sharma@acme.test')->firstOrFail();
        $remote = $this->workspace()->getUser('priya.sharma@acme.test');

        $this->assertNotNull($remote);
        $this->assertSame($remote->id, $account->google_user_id);
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertSame(BillingStatus::Billable, $account->billing_status);
        $this->assertNotNull($account->billable_from);
        $this->assertNull($account->pending_operation);
        $this->assertSame($organisation->id, $account->organisation_id);
        $this->assertSame($domain->id, $account->domain_id);
        $this->assertSame($manager->id, $account->created_by);

        $this->assertSame(['creation_requested', 'created'], $account->history()->reorder('id')->pluck('event')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.created', 'user_id' => $manager->id, 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('google_api_logs', ['operation' => 'users.insert', 'target' => 'priya.sharma@acme.test', 'status' => 'success', 'provider' => 'mock']);
    }

    public function test_initial_password_is_revealed_once_and_never_recorded(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $manager = $this->organisationManager($organisation);
        $colleague = $this->organisationManager($organisation);

        $this->actingAs($manager)->post('/accounts', $this->createPayload($organisation, $domain));
        $account = GoogleAccount::query()->firstOrFail();
        $stored = AccountSecret::query()->firstOrFail();

        // Encrypted at rest, never plaintext.
        $raw = $stored->getRawOriginal('payload');
        $password = $stored->payload;
        $this->assertSame(20, strlen($password));
        $this->assertStringNotContainsString($password, $raw);

        // Only the creator may reveal it.
        $this->actingAs($colleague)->post(route('accounts.reveal', $account))->assertNotFound();
        $this->actingAs($manager)->get(route('accounts.show', $account))->assertOk()->assertSee('Reveal password')->assertDontSee($password);

        $revealed = $this->post(route('accounts.reveal', $account))->assertOk()->assertSee($password);
        $this->assertStringContainsString('no-store', (string) $revealed->headers->get('Cache-Control'));

        $this->assertDatabaseCount('account_secrets', 0);
        $this->post(route('accounts.reveal', $account))->assertNotFound();

        $everything = AuditLog::query()->get()->toJson().GoogleAccountHistory::query()->get()->toJson().GoogleApiLog::query()->get()->toJson();
        $this->assertStringNotContainsString($password, $everything);
    }

    public function test_expired_initial_password_cannot_be_revealed(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->post('/accounts', $this->createPayload($organisation, $domain));
        $account = GoogleAccount::query()->firstOrFail();

        $this->travel(InitialPasswordVault::LIFETIME_HOURS + 1)->hours();

        $this->post(route('accounts.reveal', $account))->assertNotFound();
        $this->assertSame(1, app(InitialPasswordVault::class)->purgeExpired());
    }

    public function test_duplicate_addresses_are_rejected_locally_and_against_google(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $this->actingAs($this->organisationManager($organisation));

        $this->post('/accounts', $this->createPayload($organisation, $domain))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->createPayload($organisation, $domain))->assertSessionHasErrors('username');

        // Exists in Google but not locally (for example not imported yet).
        $this->workspace()->seedUser('taken@acme.test');
        $this->post('/accounts', $this->createPayload($organisation, $domain, ['username' => 'taken']))->assertSessionHasErrors('username');

        $this->assertSame(1, GoogleAccount::query()->count());
        $this->assertSame(1, $this->workspace()->callCount('users.insert'));
    }

    public function test_creation_input_is_validated(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $this->actingAs($this->organisationManager($organisation));

        $this->post('/accounts', $this->createPayload($organisation, $domain, [
            'username' => 'bad name@', 'first_name' => '', 'recovery_phone' => '98765', 'recovery_email' => 'nope',
        ]))->assertSessionHasErrors(['username', 'first_name', 'recovery_phone', 'recovery_email']);

        $this->post('/accounts', $this->createPayload($organisation, $domain, ['username' => 'a..b']))->assertSessionHasErrors('username');
        $this->assertSame(0, GoogleAccount::query()->count());
    }

    public function test_account_details_are_updated_in_google_then_locally(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'edit@acme.test');
        $otherOrganisation = Organisation::factory()->create();

        $this->actingAs($this->organisationManager($organisation))
            ->put(route('accounts.update', $account), [
                'first_name' => 'Renamed', 'last_name' => 'Person', 'display_name' => 'R. Person',
                'recovery_email' => '', 'recovery_phone' => '+14155550100',
                // Fields a manager must not be able to change:
                'organisational_unit_path' => '/Executives', 'organisation_id' => $otherOrganisation->id,
                'primary_email' => 'hijack@other.test', 'account_status' => 'soft_deleted', 'monthly_price' => 0,
            ])->assertRedirect(route('accounts.show', $account));

        $account->refresh();
        $this->assertSame('Renamed', $account->first_name);
        $this->assertSame('+14155550100', $account->recovery_phone);
        $this->assertSame('Renamed', $this->workspace()->getUser($account->google_user_id)->givenName);

        $this->assertSame('/', $account->organisational_unit_path);
        $this->assertSame($organisation->id, $account->organisation_id);
        $this->assertSame('edit@acme.test', $account->primary_email);
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.updated', 'resource_id' => $account->uuid]);
    }

    public function test_suspend_and_reactivate(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'staff@acme.test');
        $this->actingAs($this->organisationManager($organisation));

        $this->post(route('accounts.suspend', $account), [])->assertSessionHasErrors('reason');
        $this->post(route('accounts.suspend', $account), ['reason' => 'Left the company'])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::Suspended, $account->account_status);
        $this->assertTrue($account->is_suspended);
        $this->assertSame('Left the company', $account->suspension_reason);
        $this->assertTrue($this->workspace()->getUser($account->google_user_id)->suspended);
        $this->assertSame(1, $this->workspace()->callCount('users.signOut'));
        // Suspended mailboxes remain billable.
        $this->assertSame(BillingStatus::Billable, $account->billing_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.suspended', 'resource_id' => $account->uuid]);

        // Cannot suspend twice.
        $this->post(route('accounts.suspend', $account), ['reason' => 'Again'])->assertSessionHasErrors('account');

        $this->post(route('accounts.reactivate', $account))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertFalse($this->workspace()->getUser($account->google_user_id)->suspended);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.reactivated', 'resource_id' => $account->uuid]);
    }

    public function test_soft_delete_suspends_but_never_deletes_the_google_user(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'leaver@acme.test');
        $googleId = $account->google_user_id;
        $this->actingAs($this->organisationManager($organisation));

        $this->post(route('accounts.soft-delete', $account), ['reason' => 'Resigned', 'confirm_email' => 'wrong@acme.test'])
            ->assertSessionHasErrors('confirm_email');
        $this->assertSame(AccountStatus::Active, $account->fresh()->account_status);

        $this->post(route('accounts.soft-delete', $account), ['reason' => 'Resigned', 'confirm_email' => 'leaver@acme.test'])
            ->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::SoftDeleted, $account->account_status);
        $this->assertSame(BillingStatus::NonBillable, $account->billing_status);
        $this->assertNotNull($account->deleted_at);
        $this->assertSame(now(config('wcc.billing.timezone'))->toDateString(), $account->deletion_effective_date->toDateString());
        $this->assertSame($googleId, $account->google_user_id);

        // Still present in Google, only suspended.
        $remote = $this->workspace()->getUser($googleId);
        $this->assertNotNull($remote);
        $this->assertTrue($remote->suspended);
        $this->assertSame(0, $this->workspace()->callCount('users.delete'));

        $this->get('/accounts/deleted')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.soft_deleted', 'resource_id' => $account->uuid]);
    }

    public function test_super_admin_restores_a_soft_deleted_mailbox_and_managers_cannot(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'back@acme.test', AccountStatus::SoftDeleted);

        $this->actingAs($this->organisationManager($organisation))->post(route('accounts.restore', $account))->assertForbidden();

        $this->actingAs($this->superAdmin())->post(route('accounts.restore', $account))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertSame(BillingStatus::Billable, $account->billing_status);
        $this->assertNull($account->deleted_at);
        $this->assertNull($account->deletion_effective_date);
        $this->assertFalse($this->workspace()->getUser($account->google_user_id)->suspended);
    }

    public function test_permanent_deletion_needs_a_request_and_a_second_super_admin(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'gone@acme.test', AccountStatus::SoftDeleted);
        $googleId = $account->google_user_id;
        $requester = $this->superAdmin();
        $approver = $this->superAdmin();
        $checks = ['ownership_verified' => 1, 'retention_checked' => 1, 'vault_checked' => 1, 'dependencies_checked' => 1];

        // Managers can never start or approve it.
        $this->actingAs($this->organisationManager($organisation))
            ->post(route('accounts.deletion-requests.store', $account), $checks + ['reason' => 'Please'])->assertForbidden();

        // Every check must be confirmed.
        $this->actingAs($requester)
            ->post(route('accounts.deletion-requests.store', $account), ['reason' => 'Contract ended', 'ownership_verified' => 1])
            ->assertSessionHasErrorsIn('permanent', ['retention_checked', 'vault_checked', 'dependencies_checked']);

        $this->post(route('accounts.deletion-requests.store', $account), $checks + ['reason' => 'Contract ended'])->assertSessionHasNoErrors();
        $request = AccountDeletionRequest::query()->firstOrFail();

        // Nothing is deleted by the request itself.
        $this->assertNotNull($this->workspace()->getUser($googleId));
        $this->assertSame(AccountStatus::SoftDeleted, $account->fresh()->account_status);

        // The requester cannot approve their own request.
        $this->post(route('accounts.deletion-requests.approve', [$account, $request]), ['confirm_email' => 'gone@acme.test'])
            ->assertSessionHasErrors('request');
        $this->assertNotNull($this->workspace()->getUser($googleId));

        // A second Super Admin must type the address.
        $this->actingAs($approver)
            ->post(route('accounts.deletion-requests.approve', [$account, $request]), ['confirm_email' => 'nope'])
            ->assertSessionHasErrors('confirm_email');
        $this->post(route('accounts.deletion-requests.approve', [$account, $request]), ['confirm_email' => 'gone@acme.test'])
            ->assertSessionHasNoErrors();

        $account->refresh();
        $request->refresh();
        $this->assertNull($this->workspace()->getUser($googleId));
        $this->assertSame(AccountStatus::PermanentlyDeleted, $account->account_status);
        $this->assertNotNull($account->permanently_deleted_at);
        // History is kept: the row, its Google id and its address remain.
        $this->assertSame($googleId, $account->google_user_id);
        $this->assertSame('gone@acme.test', $account->primary_email);
        $this->assertNull($account->email_unique_key);
        $this->assertSame(AccountDeletionRequest::EXECUTED, $request->status);
        $this->assertSame($approver->id, $request->decided_by);
        $this->assertSame('deleted', $request->api_response['result']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.permanently_deleted', 'user_id' => $approver->id]);
    }

    public function test_the_deletion_operation_itself_refuses_to_run_without_approval(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'safe@acme.test', AccountStatus::SoftDeleted);
        $request = AccountDeletionRequest::query()->create([
            'google_account_id' => $account->id, 'requested_by' => $this->superAdmin()->id,
            'reason' => 'x', 'checks' => [], 'status' => AccountDeletionRequest::PENDING,
        ]);
        $account->forceFill(['pending_operation' => AccountOperation::PermanentDelete, 'previous_status' => AccountStatus::SoftDeleted])->save();

        try {
            app(GoogleAccountOperations::class)->permanentlyDelete($account, $request);
            $this->fail('An unapproved request must not delete anything.');
        } catch (WorkspaceException) {
            $this->assertNotNull($this->workspace()->getUser($account->google_user_id));
            $this->assertSame(0, $this->workspace()->callCount('users.delete'));
        }
    }

    public function test_rejected_deletion_request_leaves_the_mailbox_restorable(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'keep@acme.test', AccountStatus::SoftDeleted);
        $checks = ['ownership_verified' => 1, 'retention_checked' => 1, 'vault_checked' => 1, 'dependencies_checked' => 1];

        $this->actingAs($this->superAdmin())->post(route('accounts.deletion-requests.store', $account), $checks + ['reason' => 'Mistake']);
        $request = AccountDeletionRequest::query()->firstOrFail();

        // Cannot restore while a request is open.
        $this->post(route('accounts.restore', $account))->assertSessionHasErrors('account');

        $this->actingAs($this->superAdmin())
            ->post(route('accounts.deletion-requests.reject', [$account, $request]), ['reason' => 'Still needed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountDeletionRequest::REJECTED, $request->fresh()->status);
        $this->post(route('accounts.restore', $account))->assertSessionHasNoErrors();
        $this->assertSame(AccountStatus::Active, $account->fresh()->account_status);
    }

    public function test_a_permanent_api_failure_marks_creation_failed_and_can_be_retried(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $manager = $this->organisationManager($organisation);
        $this->workspace()->failNext('users.insert', new WorkspaceException('Invalid Input: primary_user_email'));

        $this->actingAs($manager)->post('/accounts', $this->createPayload($organisation, $domain));

        $account = GoogleAccount::query()->firstOrFail();
        $this->assertSame(AccountStatus::CreationFailed, $account->account_status);
        $this->assertNull($account->pending_operation);
        $this->assertNull($account->google_user_id);
        $this->assertStringContainsString('Invalid Input', $account->last_error);
        $this->assertSame(BillingStatus::Pending, $account->billing_status);
        $this->assertDatabaseHas('google_api_logs', ['operation' => 'users.insert', 'status' => 'failure']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.operation_failed', 'status' => 'failure']);
        $this->assertSame('operation_failed', $account->history()->first()->event);

        $this->get(route('accounts.show', $account))->assertOk()->assertSee('Retry creation');
        $this->post(route('accounts.retry', $account))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertNotNull($account->google_user_id);
        $this->assertNull($account->last_error);
    }

    public function test_a_timeout_is_retried_and_then_succeeds(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'slow@acme.test');
        $account->forceFill(['pending_operation' => AccountOperation::Suspend, 'previous_status' => AccountStatus::Active,
            'account_status' => AccountStatus::SuspensionPending])->save();

        $this->workspace()->failNext('users.suspend', new WorkspaceTransientException('Gateway timeout'));
        $job = new SuspendGoogleUserJob($account->id);

        try {
            $job->handle(app(GoogleAccountOperations::class));
            $this->fail('A transient error must be rethrown so the queue retries the job.');
        } catch (WorkspaceTransientException) {
            // Still pending — not failed, not lost.
            $this->assertSame(AccountStatus::SuspensionPending, $account->fresh()->account_status);
            $this->assertSame(AccountOperation::Suspend, $account->fresh()->pending_operation);
        }

        $this->assertSame(5, $job->tries);
        $this->assertSame([15, 60, 180, 600], $job->backoff());

        $job->handle(app(GoogleAccountOperations::class));
        $this->assertSame(AccountStatus::Suspended, $account->fresh()->account_status);
    }

    public function test_a_rate_limit_releases_the_job_for_the_requested_delay(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'busy@acme.test');
        $account->forceFill(['pending_operation' => AccountOperation::Suspend, 'previous_status' => AccountStatus::Active])->save();

        $this->workspace()->failNext('users.suspend', new WorkspaceRateLimitException(retryAfter: 42));

        $job = (new SuspendGoogleUserJob($account->id))->withFakeQueueInteractions();
        $job->handle(app(GoogleAccountOperations::class));

        $job->assertReleased(delay: 42);
        $job->assertNotFailed();
        $this->assertSame(AccountOperation::Suspend, $account->fresh()->pending_operation);
    }

    public function test_exhausted_retries_restore_the_previous_state_and_record_the_error(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $account = $this->existingAccount($organisation, 'stuck@acme.test');
        $account->forceFill(['pending_operation' => AccountOperation::Suspend, 'previous_status' => AccountStatus::Active,
            'account_status' => AccountStatus::SuspensionPending])->save();

        (new SuspendGoogleUserJob($account->id))->failed(new WorkspaceTransientException('Still timing out'));

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertNull($account->pending_operation);
        $this->assertStringContainsString('Still timing out', $account->last_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.operation_failed', 'resource_id' => $account->uuid]);
    }

    public function test_duplicate_job_execution_creates_only_one_google_user(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $this->actingAs($this->organisationManager($organisation))->post('/accounts', $this->createPayload($organisation, $domain));
        $account = GoogleAccount::query()->firstOrFail();

        // The same job delivered twice more.
        (new CreateGoogleUserJob($account->id))->handle(app(GoogleAccountOperations::class));
        (new CreateGoogleUserJob($account->id))->handle(app(GoogleAccountOperations::class));

        $this->assertSame(1, $this->workspace()->callCount('users.insert'));
        $this->assertSame(1, $account->history()->where('event', 'created')->count());
        $this->assertSame(1, count($this->workspace()->listUsers()->users));
    }

    public function test_google_success_followed_by_a_local_failure_is_reconciled_without_a_duplicate(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('acme.test');
        $manager = $this->organisationManager($organisation);

        Queue::fake();
        $this->actingAs($manager)->post('/accounts', $this->createPayload($organisation, $domain));
        $account = GoogleAccount::query()->firstOrFail();
        $this->assertSame(AccountStatus::PendingCreation, $account->account_status);

        // Simulate: Google created the user, then the local save was lost.
        $remote = $this->workspace()->seedUser('priya.sharma@acme.test', 'Priya', 'Sharma');

        app(GoogleAccountOperations::class)->create($account, $manager);

        $account->refresh();
        $this->assertSame($remote->id, $account->google_user_id);
        $this->assertSame(AccountStatus::Active, $account->account_status);
        $this->assertSame(0, $this->workspace()->callCount('users.insert'));
        $this->assertTrue($account->history()->where('event', 'created')->first()->details['adopted_existing_google_user']);
        // The adopted user gets a fresh password the creator can reveal.
        $this->assertTrue(app(InitialPasswordVault::class)->isAvailableTo($account, $manager));
    }

    public function test_stuck_operations_are_requeued_by_the_reconcile_job(): void
    {
        [$organisation] = $this->organisationWithDomain('acme.test');
        $stuck = $this->existingAccount($organisation, 'stuck@acme.test');
        $recent = $this->existingAccount($organisation, 'recent@acme.test');

        foreach ([[$stuck, now()->subMinutes(30)], [$recent, now()->subMinute()]] as [$account, $since]) {
            $account->forceFill(['pending_operation' => AccountOperation::Suspend, 'previous_status' => AccountStatus::Active,
                'account_status' => AccountStatus::SuspensionPending, 'pending_since' => $since])->save();
        }

        Queue::fake();
        (new ReconcileGoogleAccountsJob)->handle(app(InitialPasswordVault::class));

        Queue::assertPushed(SuspendGoogleUserJob::class, 1);
        Queue::assertPushed(SuspendGoogleUserJob::class, fn (SuspendGoogleUserJob $job) => $job->accountId === $stuck->id);
    }
}
