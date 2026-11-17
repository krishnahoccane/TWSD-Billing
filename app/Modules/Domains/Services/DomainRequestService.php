<?php

namespace App\Modules\Domains\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Enums\DomainType;
use App\Modules\Domains\Jobs\ActivateGoogleDomainJob;
use App\Modules\Domains\Jobs\ProcessDomainRequestJob;
use App\Modules\Domains\Jobs\RemoveGoogleDomainJob;
use App\Modules\Domains\Jobs\VerifyGoogleDomainJob;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\DomainVerificationCheck;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Domains\Support\DnsResolver;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The request side of the domain workflows: validate, record the intent
 * (status + history + audit) and queue the job that talks to Google.
 * Callers authorise first (DomainRequestPolicy).
 */
class DomainRequestService
{
    public function __construct(
        private readonly WorkspaceProviderInterface $workspace,
        private readonly DnsResolver $dns,
        private readonly DomainHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A manager asks for a new domain. This creates a pending request and
     * nothing else: no ownership is assumed and Google is not changed.
     *
     * @throws ValidationException
     */
    public function submit(Organisation $organisation, string $domainName, ?string $note, User $actor): DomainRequest
    {
        $domainName = DomainMappingService::normalise($domainName);

        if ($organisation->status !== OrganisationStatus::Active) {
            throw ValidationException::withMessages(['domain_name' => 'Domains can only be requested for an active organisation.']);
        }

        // The same answer whichever organisation holds it: no detail about other tenants.
        if (OrganisationDomain::query()->where('domain_name', $domainName)->where('status', '!=', 'removed')->exists()
            || $this->openRequestFor($domainName) !== null) {
            throw ValidationException::withMessages(['domain_name' => 'This domain is already registered or has a request in progress.']);
        }

        try {
            $inGoogle = $this->workspace->getDomain($domainName) !== null;
        } catch (WorkspaceException) {
            throw ValidationException::withMessages([
                'domain_name' => 'The domain could not be checked with Google Workspace right now. Please try again shortly.',
            ]);
        }

        if ($inGoogle) {
            throw ValidationException::withMessages([
                'domain_name' => 'This domain already exists in the Google Workspace account. Ask a Super Admin to map it to your organisation.',
            ]);
        }

        return DB::transaction(function () use ($organisation, $domainName, $note, $actor) {
            $request = DomainRequest::query()->create([
                'organisation_id' => $organisation->getKey(),
                'type' => DomainRequest::TYPE_ADD,
                'domain_name' => $domainName,
                'domain_type' => DomainType::Secondary,
                'status' => DomainRequestStatus::Pending,
                'requested_by' => $actor->getKey(),
                'request_note' => $note,
            ]);

            $this->history->record($request, 'requested', ['note' => $note], $actor);
            app(NotificationEvents::class)->domainRequestSubmitted($request);
            $this->audit->record('domain.requested', $request, new: ['domain' => $domainName], organisation: $organisation, actor: $actor);

            return $request;
        });
    }

    /**
     * A Super Admin asks for a domain to be removed. Blocked while any
     * mailbox still uses the domain; alias/group checks are attested.
     *
     * @param  array{aliases_checked: bool, groups_checked: bool}  $checks
     */
    public function submitRemoval(OrganisationDomain $domain, string $reason, array $checks, User $actor): DomainRequest
    {
        if ($domain->status !== 'active') {
            throw ValidationException::withMessages(['domain' => 'Only an active domain can be removed.']);
        }

        if ($domain->is_primary) {
            throw ValidationException::withMessages(['domain' => 'The primary domain of the Google Workspace account cannot be removed.']);
        }

        if (in_array(false, $checks, true)) {
            throw ValidationException::withMessages(['checks' => 'Every dependency check must be confirmed.']);
        }

        if ($this->openRequestFor($domain->domain_name) !== null) {
            throw ValidationException::withMessages(['domain' => 'A request is already in progress for this domain.']);
        }

        $this->assertNoAccounts($domain->domain_name);

        return DB::transaction(function () use ($domain, $reason, $checks, $actor) {
            $request = DomainRequest::query()->create([
                'organisation_id' => $domain->organisation_id,
                'organisation_domain_id' => $domain->getKey(),
                'type' => DomainRequest::TYPE_REMOVE,
                'domain_name' => $domain->domain_name,
                'domain_type' => $domain->domain_type,
                'status' => DomainRequestStatus::Pending,
                'requested_by' => $actor->getKey(),
                'request_note' => $reason,
                'checks' => $checks,
            ]);

            $this->history->record($request, 'removal_requested', ['reason' => $reason, 'checks' => $checks], $actor);
            $this->audit->record('domain.removal_requested', $request, new: ['domain' => $domain->domain_name, 'reason' => $reason],
                organisation: $domain->organisation, actor: $actor);

            return $request;
        });
    }

    public function cancel(DomainRequest $request, User $actor): void
    {
        $this->requireStatus($request, [DomainRequestStatus::Pending], 'Only a request that is still awaiting approval can be cancelled.');

        $this->close($request, DomainRequestStatus::Cancelled, 'cancelled', null, $actor);
    }

    /**
     * Approval by someone other than the requester (two-person rule).
     * Approval does not verify ownership; it only starts the next step.
     */
    public function approve(DomainRequest $request, ?string $note, User $actor): void
    {
        $this->requireStatus($request, [DomainRequestStatus::Pending], 'This request has already been decided.');

        if ($request->requested_by === $actor->getKey()
            && ($request->type === DomainRequest::TYPE_ADD || config('wcc.domains.require_second_approver', true))) {
            throw ValidationException::withMessages(['request' => 'A request cannot be approved by the person who submitted it.']);
        }

        if ($request->isRemoval()) {
            $this->assertNoAccounts($request->domain_name);
        }

        DB::transaction(function () use ($request, $note, $actor) {
            $request->forceFill([
                'status' => DomainRequestStatus::Approved,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'pending_since' => now(),
                'last_error' => null,
                'failed_step' => null,
            ])->save();

            $event = $request->isRemoval() ? 'removal_approved' : 'approved';

            if (! $request->isRemoval()) {
                app(NotificationEvents::class)->domainRequestApproved($request);
            }

            $this->history->record($request, $event, ['note' => $note], $actor);
            $this->audit->record('domain.'.$event, $request, new: ['domain' => $request->domain_name, 'requested_by' => $request->requester?->email],
                organisation: $request->organisation, actor: $actor);
        });

        $request->isRemoval()
            ? RemoveGoogleDomainJob::dispatch($request->getKey(), $actor->getKey())
            : ProcessDomainRequestJob::dispatch($request->getKey(), $actor->getKey());
    }

    public function reject(DomainRequest $request, string $reason, User $actor): void
    {
        $this->requireStatus($request, [DomainRequestStatus::Pending, DomainRequestStatus::VerificationPending, DomainRequestStatus::Failed],
            'This request can no longer be rejected.');

        $this->close($request, DomainRequestStatus::Rejected, $request->isRemoval() ? 'removal_rejected' : 'rejected', $reason, $actor);

        if (! $request->isRemoval()) {
            app(NotificationEvents::class)->domainRequestRejected($request);
        }
    }

    /**
     * Informational public DNS lookup for the TXT record. Google's own
     * verification (requestVerification) is what actually counts.
     */
    public function checkDns(DomainRequest $request, User $actor): bool
    {
        $this->requireStatus($request, [DomainRequestStatus::VerificationPending], 'DNS can only be checked while verification is pending.');

        $records = $this->dns->txtRecords($request->domain_name);
        $found = in_array($request->verification_token, $records, true);

        DomainVerificationCheck::query()->create([
            'domain_request_id' => $request->getKey(),
            'type' => 'dns_txt',
            'result' => $found ? 'pass' : 'fail',
            'details' => ['txt_records_found' => count($records)],
            'checked_by' => $actor->getKey(),
        ]);

        return $found;
    }

    /** The operator confirms the DNS record is in place and asks Google to verify. */
    public function requestVerification(DomainRequest $request, User $actor): void
    {
        $this->requireStatus($request, [DomainRequestStatus::VerificationPending], 'This request is not waiting for verification.');

        $request->forceFill(['status' => DomainRequestStatus::Verifying, 'pending_since' => now(), 'last_error' => null])->save();
        $this->history->record($request, 'verification_requested', actor: $actor);

        VerifyGoogleDomainJob::dispatch($request->getKey(), $actor->getKey());
    }

    /** Re-run the step that failed. */
    public function retry(DomainRequest $request, User $actor): void
    {
        $this->requireStatus($request, [DomainRequestStatus::Failed], 'Only a failed request can be retried.');

        [$status, $job] = match ($request->failed_step) {
            'token' => [DomainRequestStatus::Approved, ProcessDomainRequestJob::class],
            'verify' => [DomainRequestStatus::Verifying, VerifyGoogleDomainJob::class],
            'activate' => [DomainRequestStatus::Verified, ActivateGoogleDomainJob::class],
            'remove' => [DomainRequestStatus::Approved, RemoveGoogleDomainJob::class],
            default => throw ValidationException::withMessages(['request' => 'This request cannot be retried.']),
        };

        $request->forceFill(['status' => $status, 'pending_since' => now(), 'last_error' => null, 'failed_step' => null])->save();
        $this->history->record($request, 'retried', actor: $actor);

        $job::dispatch($request->getKey(), $actor->getKey());
    }

    public function openRequestFor(string $domainName): ?DomainRequest
    {
        return DomainRequest::query()
            ->where('domain_name', $domainName)
            ->whereIn('status', DomainRequestStatus::openValues())
            ->first();
    }

    /** Mailboxes that still use the domain (anything not permanently deleted). */
    public function accountsOn(string $domainName): int
    {
        return GoogleAccount::query()
            ->where('primary_email', 'like', '%@'.addcslashes($domainName, '%_\\'))
            ->where('account_status', '!=', AccountStatus::PermanentlyDeleted->value)
            ->count();
    }

    private function assertNoAccounts(string $domainName): void
    {
        $count = $this->accountsOn($domainName);

        if ($count > 0) {
            throw ValidationException::withMessages([
                'domain' => "This domain still has {$count} mailbox(es). Move or permanently delete them before removing the domain.",
            ]);
        }
    }

    private function close(DomainRequest $request, DomainRequestStatus $status, string $event, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($request, $status, $event, $note, $actor) {
            $request->forceFill([
                'status' => $status,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'pending_since' => null,
            ])->save();

            $this->history->record($request, $event, ['note' => $note], $actor);
            $this->audit->record('domain.'.$event, $request, new: ['domain' => $request->domain_name, 'note' => $note],
                organisation: $request->organisation, actor: $actor);
        });
    }

    /**
     * @param  list<DomainRequestStatus>  $allowed
     */
    private function requireStatus(DomainRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['request' => $message]);
        }
    }
}
