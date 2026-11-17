<?php

namespace App\Modules\Domains\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Domains\Contracts\DomainVerificationProviderInterface;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Jobs\ActivateGoogleDomainJob;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\DomainVerificationCheck;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\Notifications\NotificationEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The job side of the domain workflows. Each step checks the request is
 * still waiting for exactly that step, so retried or duplicated jobs are
 * harmless, and calls Google before writing the result locally.
 */
class DomainRequestProcessor
{
    public function __construct(
        private readonly DomainVerificationProviderInterface $verification,
        private readonly WorkspaceProviderInterface $workspace,
        private readonly DomainRequestService $requests,
        private readonly DomainHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /** Step 1 after approval: obtain Google's DNS TXT verification value. */
    public function issueVerificationToken(DomainRequest $request, ?User $actor = null): void
    {
        if ($request->status !== DomainRequestStatus::Approved || $request->isRemoval()) {
            return;
        }

        $token = $this->verification->dnsTxtToken($request->domain_name);

        $request->forceFill([
            'status' => DomainRequestStatus::VerificationPending,
            'verification_method' => 'dns_txt',
            'verification_token' => $token,
            'pending_since' => null,
        ])->save();

        $this->history->record($request, 'verification_instructions_issued', ['provider' => $this->verification->name()], $actor);
    }

    /** Step 2: Google checks the DNS record. Not found is not a failure — the request simply keeps waiting. */
    public function verify(DomainRequest $request, ?User $actor = null): void
    {
        if ($request->status !== DomainRequestStatus::Verifying) {
            return;
        }

        $verified = $this->verification->verify($request->domain_name);

        DomainVerificationCheck::query()->create([
            'domain_request_id' => $request->getKey(),
            'type' => 'google_verify',
            'result' => $verified ? 'pass' : 'fail',
            'details' => ['provider' => $this->verification->name()],
            'checked_by' => $actor?->getKey(),
        ]);

        if (! $verified) {
            $request->forceFill([
                'status' => DomainRequestStatus::VerificationPending,
                'pending_since' => null,
                'last_error' => 'Google could not find the verification record yet. DNS changes can take time to spread; try again later.',
            ])->save();

            $this->history->record($request, 'verification_not_found', actor: $actor);

            return;
        }

        DB::transaction(function () use ($request, $actor) {
            $request->forceFill([
                'status' => DomainRequestStatus::Verified,
                'verified_by' => $actor?->getKey(),
                'verified_at' => now(),
                'pending_since' => now(),
                'last_error' => null,
            ])->save();

            $this->history->record($request, 'verified', ['provider' => $this->verification->name()], $actor);
            $this->audit->record('domain.verified', $request, new: ['domain' => $request->domain_name],
                organisation: $request->organisation, actor: $actor);
        });

        ActivateGoogleDomainJob::dispatch($request->getKey(), $actor?->getKey());
    }

    /** Step 3: add the secondary domain to Google Workspace and activate it for the organisation. */
    public function activate(DomainRequest $request, ?User $actor = null): void
    {
        if ($request->status !== DomainRequestStatus::Verified) {
            return;
        }

        // Idempotent: an earlier attempt may already have added it.
        $remote = $this->workspace->getDomain($request->domain_name) ?? $this->workspace->addDomain($request->domain_name);
        $remote = $this->workspace->getDomain($request->domain_name) ?? $remote;

        DomainVerificationCheck::query()->create([
            'domain_request_id' => $request->getKey(),
            'type' => 'google_domain',
            'result' => 'pass',
            'details' => ['verified_in_workspace' => $remote->verified, 'provider' => $this->workspace->name()],
            'checked_by' => $actor?->getKey(),
        ]);

        DB::transaction(function () use ($request, $remote, $actor) {
            // A previously removed domain keeps its row (old mailboxes refer to it) and is revived.
            $domain = OrganisationDomain::query()->where('domain_name', $request->domain_name)->lockForUpdate()->first()
                ?? new OrganisationDomain(['domain_name' => $request->domain_name]);

            $domain->forceFill([
                'organisation_id' => $request->organisation_id,
                'domain_type' => $request->domain_type,
                'google_domain_id' => $remote->name,
                'verification_status' => $remote->verified ? 'verified' : 'pending',
                'is_primary' => $remote->isPrimary,
                'status' => 'active',
                'created_by' => $domain->exists ? $domain->created_by : $request->requested_by,
            ])->save();

            $request->forceFill([
                'organisation_domain_id' => $domain->getKey(),
                'status' => DomainRequestStatus::Completed,
                'completed_at' => now(),
                'pending_since' => null,
                'last_error' => null,
            ])->save();

            app(NotificationEvents::class)->domainActivated($request);

            $this->history->record($request, 'activated', ['verified_in_workspace' => $remote->verified], $actor);
            $this->audit->record('domain.activated', $domain, new: ['domain' => $domain->domain_name],
                organisation: $request->organisation, actor: $actor);
        });
    }

    /** Removal: re-check dependencies at the last moment, then remove from Google and retire the row. */
    public function remove(DomainRequest $request, ?User $actor = null): void
    {
        if ($request->status !== DomainRequestStatus::Approved || ! $request->isRemoval()) {
            return;
        }

        $accounts = $this->requests->accountsOn($request->domain_name);

        if ($accounts > 0) {
            throw new WorkspaceException("The domain still has {$accounts} mailbox(es); it was not removed.");
        }

        $result = 'already_absent';

        if ($this->workspace->getDomain($request->domain_name) !== null) {
            $this->workspace->removeDomain($request->domain_name);
            $result = 'removed';
        }

        DB::transaction(function () use ($request, $actor, $result) {
            $domain = $request->domain;

            // The row is kept: historical mailboxes and invoices refer to it.
            $domain?->forceFill(['status' => 'removed', 'verification_status' => 'removed', 'is_primary' => false])->save();

            $request->forceFill([
                'status' => DomainRequestStatus::Completed, 'completed_at' => now(), 'pending_since' => null, 'last_error' => null,
            ])->save();

            $this->history->record($request, 'removed', ['google' => $result, 'provider' => $this->workspace->name()], $actor);
            $this->audit->record('domain.removed', $request, new: ['domain' => $request->domain_name, 'google' => $result],
                organisation: $request->organisation, actor: $actor);
        });
    }

    /** A step finally failed. Recorded on the request, in history and in the audit log; retryable. */
    public function markFailed(DomainRequest $request, string $step, Throwable $error): void
    {
        if (! $request->status->isOpen() || $request->status === DomainRequestStatus::Failed) {
            return;
        }

        $message = Str::limit(class_basename($error).': '.$error->getMessage(), 1000);

        $request->forceFill([
            'status' => DomainRequestStatus::Failed, 'failed_step' => $step, 'last_error' => $message, 'pending_since' => null,
        ])->save();

        $this->history->record($request, 'step_failed', ['step' => $step, 'error' => $message]);
        app(NotificationEvents::class)->domainStepFailed($request, $step, $message);
        $this->audit->record('domain.step_failed', $request, new: ['domain' => $request->domain_name, 'step' => $step, 'error' => $message],
            organisation: $request->organisation, status: AuditLogger::STATUS_FAILURE);
    }
}
