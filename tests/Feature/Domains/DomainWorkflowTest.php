<?php

namespace Tests\Feature\Domains;

use App\Modules\Domains\Contracts\DomainVerificationProviderInterface;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Domains\Providers\GoogleDomainVerificationProvider;
use App\Modules\Domains\Providers\MockDomainVerificationProvider;
use App\Modules\Domains\Services\DomainRequestProcessor;
use App\Modules\Domains\Support\DnsResolver;
use App\Modules\Domains\Support\FakeDnsResolver;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Providers\GoogleAccessTokenProvider;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\GoogleWorkspace\WorkspaceTestCase;

class DomainWorkflowTest extends WorkspaceTestCase
{
    private function verification(): MockDomainVerificationProvider
    {
        return app(MockDomainVerificationProvider::class);
    }

    private function submit(Organisation $organisation, string $domain = 'newco.test'): DomainRequest
    {
        $this->actingAs($this->organisationManager($organisation))
            ->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => $domain, 'note' => 'DNS is with our registrar'])
            ->assertSessionHasNoErrors();

        return DomainRequest::query()->where('domain_name', $domain)->latest('id')->firstOrFail();
    }

    public function test_manager_submits_a_domain_request_that_changes_nothing_in_google(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->get('/domains/requests/create')->assertOk();
        $this->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => ' NewCo.Test. '])
            ->assertSessionHasNoErrors()->assertRedirect();

        $request = DomainRequest::query()->firstOrFail();
        $this->assertSame('newco.test', $request->domain_name);
        $this->assertSame(DomainRequestStatus::Pending, $request->status);
        $this->assertSame($manager->id, $request->requested_by);
        $this->assertNull($request->verification_token);

        // A request is only a claim: no domain exists locally or in Google yet.
        $this->assertDatabaseCount('organisation_domains', 0);
        $this->assertNull($this->workspace()->getDomain('newco.test'));
        $this->assertSame(0, $this->workspace()->callCount('domains.insert'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.requested', 'user_id' => $manager->id, 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('domain_history', ['event' => 'requested', 'domain_name' => 'newco.test']);

        $this->get(route('domains.requests.show', $request))->assertOk()->assertSee('Waiting for Super Admin approval')->assertDontSee('Approve…');
    }

    public function test_duplicate_and_invalid_domains_are_rejected(): void
    {
        [$organisation] = $this->organisationWithDomain('mapped.test');
        $this->workspace()->seedDomain('ingoogle.test');
        $this->actingAs($this->organisationManager($organisation));
        $post = fn (string $domain) => $this->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => $domain]);

        $post('not a domain')->assertSessionHasErrors('domain_name');
        $post('https://example.test/path')->assertSessionHasErrors('domain_name');
        $post('mapped.test')->assertSessionHasErrors('domain_name');      // already an organisation's domain
        $post('ingoogle.test')->assertSessionHasErrors('domain_name');    // already in Google Workspace
        $post('fresh.test')->assertSessionHasNoErrors();
        $post('fresh.test')->assertSessionHasErrors('domain_name');       // a request is already in progress

        $this->assertSame(1, DomainRequest::query()->count());
    }

    public function test_another_organisation_cannot_claim_a_domain_that_is_in_progress_or_owned(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        $b = Organisation::factory()->create();
        $this->submit($a, 'shared.test');

        $this->actingAs($this->organisationManager($b));
        $this->post('/domains/requests', ['organisation' => $b->uuid, 'domain_name' => 'shared.test'])->assertSessionHasErrors('domain_name');
        $this->post('/domains/requests', ['organisation' => $b->uuid, 'domain_name' => 'alpha.test'])->assertSessionHasErrors('domain_name');
        // Submitting for an organisation the manager does not belong to: not found.
        $this->post('/domains/requests', ['organisation' => $a->uuid, 'domain_name' => 'other.test'])->assertNotFound();

        $this->assertSame(1, DomainRequest::query()->count());
    }

    public function test_only_a_super_admin_other_than_the_requester_can_approve(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $manager = $request->requester;

        // The manager cannot approve their own (or any) request.
        $this->actingAs($manager)->post(route('domains.requests.approve', $request))->assertForbidden();
        $this->actingAs($this->billingManager($organisation))->post(route('domains.requests.approve', $request))->assertNotFound();
        $this->actingAs($this->operationsAdmin($organisation))->post(route('domains.requests.approve', $request))->assertForbidden();
        $this->assertSame(DomainRequestStatus::Pending, $request->fresh()->status);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/domains/requests/pending')->assertOk()->assertSee('newco.test');
        $this->post(route('domains.requests.approve', $request), ['note' => 'Customer confirmed'])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(DomainRequestStatus::VerificationPending, $request->status);
        $this->assertSame($admin->id, $request->decided_by);
        $this->assertStringStartsWith('google-site-verification=', $request->verification_token);
        // Approval alone adds nothing to Google and activates nothing.
        $this->assertNull($this->workspace()->getDomain('newco.test'));
        $this->assertDatabaseCount('organisation_domains', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.approved', 'user_id' => $admin->id]);

        // The manager now sees the DNS instructions but not the admin controls.
        $this->actingAs($manager)->get(route('domains.requests.show', $request))
            ->assertOk()->assertSee($request->verification_token)->assertSee('TXT')->assertDontSee('Verify with Google');
    }

    public function test_a_super_admin_cannot_approve_a_request_they_submitted_themselves(): void
    {
        $organisation = Organisation::factory()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => 'self.test']);
        $request = DomainRequest::query()->firstOrFail();

        $this->post(route('domains.requests.approve', $request))->assertSessionHasErrors('request');
        $this->assertSame(DomainRequestStatus::Pending, $request->fresh()->status);

        $this->actingAs($this->superAdmin())->post(route('domains.requests.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame(DomainRequestStatus::VerificationPending, $request->fresh()->status);
    }

    public function test_requests_can_be_rejected_or_cancelled(): void
    {
        $organisation = Organisation::factory()->create();
        $rejected = $this->submit($organisation, 'reject.test');
        $cancelled = $this->submit($organisation, 'cancel.test');
        $otherManager = $this->organisationManager($organisation);

        $this->actingAs($this->superAdmin());
        $this->post(route('domains.requests.reject', $rejected), [])->assertSessionHasErrors('reason');
        $this->post(route('domains.requests.reject', $rejected), ['reason' => 'Not your domain'])->assertSessionHasNoErrors();
        $this->assertSame(DomainRequestStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame('Not your domain', $rejected->fresh()->decision_note);
        $this->post(route('domains.requests.approve', $rejected))->assertSessionHasErrors('request');

        // Only the requester may cancel.
        $this->actingAs($otherManager)->post(route('domains.requests.cancel', $cancelled))->assertForbidden();
        $this->actingAs($cancelled->requester)->post(route('domains.requests.cancel', $cancelled))->assertSessionHasNoErrors();
        $this->assertSame(DomainRequestStatus::Cancelled, $cancelled->fresh()->status);

        // A closed request frees the name for a new one.
        $this->post('/domains/requests', ['organisation' => $organisation->uuid, 'domain_name' => 'cancel.test'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->workspace()->callCount('domains.insert'));
    }

    public function test_dns_check_and_google_verification_activate_the_domain(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $admin = $this->superAdmin();
        $this->verification()->autoVerify = false;

        $this->actingAs($admin)->post(route('domains.requests.approve', $request));
        $request->refresh();

        // Record not published yet.
        $this->post(route('domains.requests.check-dns', $request))->assertSessionHas('warning');
        $this->post(route('domains.requests.verify', $request))->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(DomainRequestStatus::VerificationPending, $request->status, 'Not found is not a failure; it keeps waiting.');
        $this->assertStringContainsString('could not find', $request->last_error);
        $this->assertNull($this->workspace()->getDomain('newco.test'));
        $this->assertDatabaseCount('organisation_domains', 0);

        // The owner publishes the record.
        app(DnsResolver::class)->records['newco.test'] = ['v=spf1 -all', $request->verification_token];
        $this->verification()->verifiable[] = 'newco.test';

        $this->post(route('domains.requests.check-dns', $request))->assertSessionHas('success');
        $this->post(route('domains.requests.verify', $request))->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(DomainRequestStatus::Completed, $request->status);
        $this->assertNotNull($request->verified_at);
        $this->assertSame($admin->id, $request->verified_by);

        // Added to Google as a secondary domain and activated for the organisation.
        $this->assertNotNull($this->workspace()->getDomain('newco.test'));
        $this->assertSame(1, $this->workspace()->callCount('domains.insert'));
        $domain = OrganisationDomain::query()->where('domain_name', 'newco.test')->firstOrFail();
        $this->assertSame($organisation->id, $domain->organisation_id);
        $this->assertSame('active', $domain->status);
        $this->assertSame($domain->id, $request->organisation_domain_id);

        $this->assertSame(['fail', 'fail', 'pass', 'pass', 'pass'], $request->verificationChecks()->reorder('id')->pluck('result')->all());
        foreach (['domain.verified', 'domain.activated'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'user_id' => $admin->id, 'organisation_id' => $organisation->id]);
        }

        // The manager can now create a mailbox on it.
        $this->actingAs($request->requester)->post('/accounts', [
            'organisation' => $organisation->uuid, 'domain' => $domain->uuid, 'username' => 'first', 'first_name' => 'First', 'last_name' => 'User',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('google_accounts', ['primary_email' => 'first@newco.test', 'account_status' => 'active']);

        $this->get('/domains')->assertOk()->assertSee('newco.test');
        $this->get(route('domains.show', $domain))->assertOk()->assertSee('Activated');
    }

    public function test_activation_cannot_be_triggered_without_authority_or_verification(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $manager = $request->requester;

        // Not approved yet: nobody can verify.
        $this->actingAs($this->superAdmin())->post(route('domains.requests.verify', $request))->assertSessionHasErrors('request');

        $this->actingAs($this->superAdmin())->post(route('domains.requests.approve', $request));

        // The manager cannot run verification, DNS checks or retries.
        $this->actingAs($manager);
        $this->post(route('domains.requests.verify', $request))->assertForbidden();
        $this->post(route('domains.requests.check-dns', $request))->assertForbidden();
        $this->post(route('domains.requests.retry', $request))->assertForbidden();

        // Calling the activation step directly on an unverified request does nothing.
        app(DomainRequestProcessor::class)->activate($request->fresh());

        $this->assertSame(DomainRequestStatus::VerificationPending, $request->fresh()->status);
        $this->assertNull($this->workspace()->getDomain('newco.test'));
        $this->assertDatabaseCount('organisation_domains', 0);
    }

    public function test_an_assigned_operations_admin_can_process_but_not_approve(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $ops = $this->operationsAdmin($organisation);

        $this->actingAs($ops)->get('/domains/requests/pending')->assertForbidden();
        $this->post(route('domains.requests.approve', $request))->assertForbidden();

        $this->actingAs($this->superAdmin())->post(route('domains.requests.approve', $request));

        $this->actingAs($ops)->get('/domains/verification')->assertOk()->assertSee('newco.test');
        $this->post(route('domains.requests.verify', $request))->assertSessionHasNoErrors();
        $this->assertSame(DomainRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_domain_requests_and_domains_are_isolated_between_organisations(): void
    {
        [$a] = $this->organisationWithDomain('alpha.test');
        [$b, $domainB] = $this->organisationWithDomain('beta.test');
        $mine = $this->submit($a, 'mine.test');
        $theirs = $this->submit($b, 'theirs.test');

        $this->actingAs($mine->requester);

        $this->get('/domains')->assertOk()->assertSee('alpha.test')->assertDontSee('beta.test');
        $this->get('/domains/requests')->assertOk()->assertSee('mine.test')->assertDontSee('theirs.test');
        $this->get(route('domains.requests.show', $mine))->assertOk();

        $this->get(route('domains.requests.show', $theirs))->assertNotFound();
        $this->post(route('domains.requests.cancel', $theirs))->assertNotFound();
        $this->post(route('domains.requests.approve', $theirs))->assertNotFound();
        $this->post(route('domains.requests.reject', $theirs), ['reason' => 'attack'])->assertNotFound();
        $this->post(route('domains.requests.verify', $theirs))->assertNotFound();
        $this->get(route('domains.show', $domainB))->assertNotFound();
        $this->post(route('domains.removal', $domainB), ['reason' => 'attack', 'aliases_checked' => 1, 'groups_checked' => 1])->assertNotFound();
        $this->get('/domains/requests/'.$theirs->id)->assertNotFound();

        $this->assertSame(DomainRequestStatus::Pending, $theirs->fresh()->status);
        $this->assertSame('active', $domainB->fresh()->status);

        // Billing managers have no domain access at all.
        $this->actingAs($this->billingManager($a));
        $this->get('/domains')->assertForbidden();
        $this->get('/domains/requests/create')->assertForbidden();
        $this->get(route('domains.requests.show', $mine))->assertNotFound();
    }

    public function test_a_failed_step_is_recorded_and_can_be_retried(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $this->verification()->failNext(new WorkspaceException('Site Verification 403: insufficient permissions'));

        $this->actingAs($this->superAdmin())->post(route('domains.requests.approve', $request));

        $request->refresh();
        $this->assertSame(DomainRequestStatus::Failed, $request->status);
        $this->assertSame('token', $request->failed_step);
        $this->assertStringContainsString('insufficient permissions', $request->last_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.step_failed', 'status' => 'failure']);
        $this->get(route('domains.requests.show', $request))->assertOk()->assertSee('A step failed');

        $this->post(route('domains.requests.retry', $request))->assertSessionHasNoErrors();
        $this->assertSame(DomainRequestStatus::VerificationPending, $request->fresh()->status);
        $this->assertNull($request->fresh()->last_error);
    }

    public function test_a_transient_failure_while_adding_the_domain_is_retried_without_duplicates(): void
    {
        $organisation = Organisation::factory()->create();
        $request = $this->submit($organisation);
        $this->actingAs($this->superAdmin())->post(route('domains.requests.approve', $request));
        $request->refresh()->forceFill(['status' => DomainRequestStatus::Verified])->save();

        $this->workspace()->failNext('domains.insert', new WorkspaceTransientException('Backend Error'));
        $processor = app(DomainRequestProcessor::class);

        try {
            $processor->activate($request);
            $this->fail('A transient error must propagate so the job is retried.');
        } catch (WorkspaceTransientException) {
            $this->assertSame(DomainRequestStatus::Verified, $request->fresh()->status);
            $this->assertDatabaseCount('organisation_domains', 0);
        }

        $processor->activate($request->fresh());
        $processor->activate($request->fresh());   // duplicate delivery

        $this->assertSame(DomainRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(1, OrganisationDomain::query()->where('domain_name', 'newco.test')->count());
        $this->assertSame(2, $this->workspace()->callCount('domains.insert'));   // one failed + one successful
    }

    public function test_domain_removal_is_blocked_while_mailboxes_exist_and_needs_two_super_admins(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('leaving.test');
        $account = $this->existingAccount($organisation, 'last@leaving.test');
        $requester = $this->superAdmin();
        $checks = ['reason' => 'Organisation moved away', 'aliases_checked' => 1, 'groups_checked' => 1];

        // Managers cannot request removal.
        $this->actingAs($this->organisationManager($organisation))->post(route('domains.removal', $domain), $checks)->assertForbidden();

        $this->actingAs($requester);
        $this->post(route('domains.removal', $domain), ['reason' => 'x y z'])->assertSessionHasErrorsIn('removal', ['aliases_checked', 'groups_checked']);
        // Blocked: a mailbox still uses the domain (soft-deleted ones count too).
        $this->post(route('domains.removal', $domain), $checks)->assertSessionHasErrors('domain');
        $account->forceFill(['account_status' => AccountStatus::SoftDeleted])->save();
        $this->post(route('domains.removal', $domain), $checks)->assertSessionHasErrors('domain');
        $this->assertSame(0, DomainRequest::query()->count());

        $account->forceFill(['account_status' => AccountStatus::PermanentlyDeleted, 'email_unique_key' => null])->save();
        $this->post(route('domains.removal', $domain), $checks)->assertSessionHasNoErrors();
        $request = DomainRequest::query()->firstOrFail();
        $this->assertSame(DomainRequest::TYPE_REMOVE, $request->type);
        $this->assertNotNull($this->workspace()->getDomain('leaving.test'));

        // The requester cannot approve; the approver must type the domain.
        $this->post(route('domains.requests.approve', $request), ['confirm_domain' => 'leaving.test'])->assertSessionHasErrors('request');
        $approver = $this->superAdmin();
        $this->actingAs($approver)->post(route('domains.requests.approve', $request), ['confirm_domain' => 'wrong.test'])->assertSessionHasErrors('confirm_domain');
        $this->assertNotNull($this->workspace()->getDomain('leaving.test'));

        $this->post(route('domains.requests.approve', $request), ['confirm_domain' => 'leaving.test'])->assertSessionHasNoErrors();

        $this->assertNull($this->workspace()->getDomain('leaving.test'));
        $this->assertSame(DomainRequestStatus::Completed, $request->fresh()->status);
        // The row is retired, not deleted: old mailboxes still refer to it.
        $this->assertSame('removed', $domain->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.removed', 'user_id' => $approver->id]);

        // A removed domain can be requested again and is revived on the same row.
        $again = $this->submit($organisation, 'leaving.test');
        $this->actingAs($approver)->post(route('domains.requests.approve', $again));
        $this->post(route('domains.requests.verify', $again));
        $this->assertSame('active', $domain->fresh()->status);
        $this->assertSame(1, OrganisationDomain::query()->where('domain_name', 'leaving.test')->count());
    }

    public function test_removal_rechecks_mailboxes_at_execution_time(): void
    {
        [$organisation, $domain] = $this->organisationWithDomain('race.test');
        $request = DomainRequest::query()->create([
            'organisation_id' => $organisation->id, 'organisation_domain_id' => $domain->id, 'type' => DomainRequest::TYPE_REMOVE,
            'domain_name' => 'race.test', 'domain_type' => 'secondary', 'status' => DomainRequestStatus::Approved,
            'requested_by' => $this->superAdmin()->id,
        ]);
        // A mailbox appears after approval.
        $this->existingAccount($organisation, 'late@race.test');

        try {
            app(DomainRequestProcessor::class)->remove($request);
            $this->fail('Removal must refuse while a mailbox exists.');
        } catch (WorkspaceException) {
            $this->assertNotNull($this->workspace()->getDomain('race.test'));
            $this->assertSame('active', $domain->fresh()->status);
            $this->assertSame(0, $this->workspace()->callCount('domains.delete'));
        }
    }

    public function test_api_domain_endpoints_enforce_roles_and_isolation(): void
    {
        $this->getJson('/api/v1/domains')->assertUnauthorized();
        $this->getJson('/api/v1/domains/requests')->assertUnauthorized();

        [$a] = $this->organisationWithDomain('alpha.test');
        [$b] = $this->organisationWithDomain('beta.test');
        $theirs = $this->submit($b, 'theirs.test');
        $manager = $this->organisationManager($a);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/domains')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.domain_name', 'alpha.test');
        $this->postJson('/api/v1/domains/requests', ['organisation' => $b->uuid, 'domain_name' => 'x.test'])->assertNotFound();
        $created = $this->postJson('/api/v1/domains/requests', ['organisation' => $a->uuid, 'domain_name' => 'Api-Domain.test'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.verification', null);
        $uuid = $created->json('data.id');

        $this->getJson('/api/v1/domains/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/domains/requests/{$uuid}/approve")->assertForbidden();
        $this->postJson("/api/v1/domains/requests/{$uuid}/verify")->assertForbidden();
        $this->postJson("/api/v1/domains/requests/{$theirs->uuid}/approve")->assertNotFound();
        $this->postJson("/api/v1/domains/requests/{$theirs->uuid}/reject", ['reason' => 'attack'])->assertNotFound();

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/domains/requests/{$uuid}/approve")
            ->assertOk()->assertJsonPath('data.status', 'verification_pending')->assertJsonPath('data.verification.record_type', 'TXT');
        $this->postJson("/api/v1/domains/requests/{$uuid}/verify")->assertStatus(202)->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/domains/requests/{$theirs->uuid}/reject", ['reason' => 'Not verified'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('organisation_domains', ['domain_name' => 'api-domain.test', 'organisation_id' => $a->id, 'status' => 'active']);
    }

    public function test_google_verification_client_sends_the_documented_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://www.googleapis.com/siteVerification/v1/token' => Http::response(['method' => 'DNS_TXT', 'token' => 'google-site-verification=abc123']),
            'https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod=DNS_TXT' => Http::sequence()
                ->push(['error' => ['message' => 'The necessary verification token could not be found on your site.']], 400)
                ->push(['id' => 'dns%3A%2F%2Fexample.com', 'site' => ['type' => 'INET_DOMAIN', 'identifier' => 'example.com'], 'owners' => ['a@b.test']]),
        ]);

        $tokens = new class(app(Factory::class), app('cache.store'), ['service_account_json' => null, 'admin_email' => null, 'delegation_enabled' => false]) extends GoogleAccessTokenProvider
        {
            public function token(): string
            {
                return 'test-access-token';
            }
        };
        $provider = new GoogleDomainVerificationProvider(app(Factory::class), $tokens);

        $this->assertSame('google-site-verification=abc123', $provider->dnsTxtToken('example.com'));
        $this->assertFalse($provider->verify('example.com'));
        $this->assertTrue($provider->verify('example.com'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token')
            && $r->data() === ['site' => ['type' => 'INET_DOMAIN', 'identifier' => 'example.com'], 'verificationMethod' => 'DNS_TXT']
            && $r->hasHeader('Authorization', 'Bearer test-access-token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/webResource?verificationMethod=DNS_TXT')
            && $r->data() === ['site' => ['type' => 'INET_DOMAIN', 'identifier' => 'example.com']]);
    }

    public function test_tests_never_use_live_verification_or_real_dns(): void
    {
        config(['wcc.google.enabled' => true]);
        app()->forgetInstance(DomainVerificationProviderInterface::class);

        $this->assertInstanceOf(MockDomainVerificationProvider::class, app(DomainVerificationProviderInterface::class));
        $this->assertInstanceOf(FakeDnsResolver::class, app(DnsResolver::class));
        $this->assertContains('https://www.googleapis.com/auth/siteverification', GoogleAccessTokenProvider::SCOPES);
    }
}
