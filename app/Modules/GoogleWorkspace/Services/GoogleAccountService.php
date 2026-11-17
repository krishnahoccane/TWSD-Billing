<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\BillingPeriodTracker;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Jobs\UpdateGoogleUserJob;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The request side of every mailbox operation: validate the business
 * rules, record the intent locally (pending state + history + audit) and
 * queue the job that talks to Google.
 *
 * Callers authorise first (GoogleAccountPolicy). The organisation and
 * domain passed in are already resolved inside the caller's own scope.
 */
class GoogleAccountService
{
    public function __construct(
        private readonly WorkspaceProviderInterface $provider,
        private readonly AccountHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{username: string, first_name: string, last_name: string, recovery_email?: ?string, recovery_phone?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function requestCreation(Organisation $organisation, OrganisationDomain $domain, array $data, User $actor): GoogleAccount
    {
        if ($organisation->status !== OrganisationStatus::Active) {
            throw ValidationException::withMessages(['domain' => 'Mailboxes can only be created for an active organisation.']);
        }

        // Domain ownership is checked against the database, not the request.
        if ($domain->organisation_id !== $organisation->getKey() || $domain->status !== 'active') {
            throw ValidationException::withMessages(['domain' => 'Choose one of this organisation\'s active domains.']);
        }

        $email = Str::lower(trim($data['username'])).'@'.$domain->domain_name;

        if (GoogleAccount::query()->where('email_unique_key', $email)->exists()) {
            throw ValidationException::withMessages(['username' => "{$email} already exists."]);
        }

        try {
            $taken = $this->provider->getUser($email) !== null;
        } catch (WorkspaceException) {
            throw ValidationException::withMessages([
                'username' => 'The address could not be checked with Google Workspace right now. Please try again shortly.',
            ]);
        }

        if ($taken) {
            throw ValidationException::withMessages(['username' => "{$email} already exists in Google Workspace."]);
        }

        $account = DB::transaction(function () use ($organisation, $domain, $data, $email, $actor) {
            $account = new GoogleAccount([
                'primary_email' => $email,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'recovery_email' => filled($data['recovery_email'] ?? null) ? Str::lower($data['recovery_email']) : null,
                'recovery_phone' => $data['recovery_phone'] ?? null,
            ]);

            $account->forceFill([
                'organisation_id' => $organisation->getKey(),
                'domain_id' => $domain->getKey(),
                'email_unique_key' => $email,
                'account_status' => AccountStatus::PendingCreation,
                'billing_status' => BillingStatus::Pending,
                'pending_operation' => AccountOperation::Create,
                'pending_since' => now(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->history->record($account, 'creation_requested', null, AccountStatus::PendingCreation, actor: $actor);
            $this->audit->record('account.creation_requested', $account, new: ['email' => $email],
                organisation: $organisation, actor: $actor);

            return $account;
        });

        $this->dispatch($account, AccountOperation::Create, $actor);

        return $account;
    }

    public function retryCreation(GoogleAccount $account, User $actor): void
    {
        $this->requireStatus($account, [AccountStatus::CreationFailed], 'Only a failed creation can be retried.');

        $account->forceFill([
            'account_status' => AccountStatus::PendingCreation,
            'pending_operation' => AccountOperation::Create,
            'pending_since' => now(),
            'last_error' => null,
        ])->save();

        $this->history->record($account, 'creation_retried', AccountStatus::CreationFailed, AccountStatus::PendingCreation, actor: $actor);
        $this->dispatch($account, AccountOperation::Create, $actor);
    }

    /**
     * @param  array<string, mixed>  $data  validated; only editable fields
     */
    public function requestUpdate(GoogleAccount $account, array $data, User $actor): bool
    {
        $this->requireStatus($account, [AccountStatus::Active, AccountStatus::Suspended], 'This account cannot be edited in its current state.');

        $changes = [];

        foreach ($data as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            if ($account->getAttribute($field) !== $value) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return false;
        }

        UpdateGoogleUserJob::dispatch($account->getKey(), $actor->getKey(), $changes)->afterCommit();

        return true;
    }

    public function requestSuspension(GoogleAccount $account, string $reason, User $actor): void
    {
        $this->requireStatus($account, [AccountStatus::Active], 'Only an active account can be suspended.');

        $this->begin($account, AccountOperation::Suspend, ['suspension_reason' => $reason], 'suspension_requested', ['reason' => $reason], $actor);
    }

    public function requestReactivation(GoogleAccount $account, User $actor): void
    {
        $this->requireStatus($account, [AccountStatus::Suspended], 'Only a suspended account can be reactivated.');

        // Billing eligibility: until the billing engine exists (Phase E) the
        // only rule is that the owning organisation must be active.
        if ($account->organisation !== null && $account->organisation->status !== OrganisationStatus::Active) {
            throw ValidationException::withMessages(['account' => 'The organisation is not active, so this account cannot be reactivated.']);
        }

        $this->begin($account, AccountOperation::Reactivate, [], 'reactivation_requested', [], $actor);
    }

    /**
     * Stage 1 deletion. Billing stops from the effective date (today in
     * the billing timezone).
     */
    public function requestSoftDeletion(GoogleAccount $account, string $reason, User $actor): void
    {
        $this->requireStatus($account, [AccountStatus::Active, AccountStatus::Suspended], 'Only an active or suspended account can be deleted.');

        $effective = CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString();

        $this->begin($account, AccountOperation::SoftDelete,
            ['deletion_reason' => $reason, 'deletion_effective_date' => $effective],
            'deletion_requested', ['reason' => $reason, 'effective_date' => $effective], $actor);
    }

    public function requestRestore(GoogleAccount $account, User $actor): void
    {
        $this->requireStatus($account, [AccountStatus::SoftDeleted], 'Only a soft-deleted account can be restored.');

        if ($this->openDeletionRequest($account) !== null) {
            throw ValidationException::withMessages(['account' => 'Reject the open permanent-deletion request before restoring this account.']);
        }

        $this->begin($account, AccountOperation::Restore, [], 'restore_requested', [], $actor);
    }

    /**
     * Stage 2, step 1: a Super Admin records the pre-deletion checks and
     * asks for approval. Nothing is deleted here.
     *
     * @param  array{ownership_verified: bool, retention_checked: bool, vault_checked: bool, dependencies_checked: bool}  $checks
     */
    public function requestPermanentDeletion(GoogleAccount $account, string $reason, array $checks, User $actor): AccountDeletionRequest
    {
        $this->requireStatus($account, [AccountStatus::SoftDeleted], 'Only a soft-deleted account can be permanently deleted.');

        if (in_array(false, $checks, true)) {
            throw ValidationException::withMessages(['checks' => 'Every pre-deletion check must be confirmed.']);
        }

        if ($this->openDeletionRequest($account) !== null) {
            throw ValidationException::withMessages(['account' => 'A permanent-deletion request is already open for this account.']);
        }

        return DB::transaction(function () use ($account, $reason, $checks, $actor) {
            $request = AccountDeletionRequest::query()->create([
                'google_account_id' => $account->getKey(),
                'organisation_id' => $account->organisation_id,
                'requested_by' => $actor->getKey(),
                'reason' => $reason,
                'checks' => $checks,
                'status' => AccountDeletionRequest::PENDING,
            ]);

            app(NotificationEvents::class)->permanentDeletionRequested($account, $request);

            $this->history->record($account, 'permanent_deletion_requested', details: ['deletion_request' => $request->uuid, 'reason' => $reason], actor: $actor);
            $this->audit->record('account.permanent_deletion_requested', $account,
                new: ['deletion_request' => $request->uuid, 'reason' => $reason, 'checks' => $checks],
                organisation: $account->organisation, actor: $actor);

            return $request;
        });
    }

    /**
     * Stage 2, step 2: approval by a DIFFERENT Super Admin (two-person
     * rule), then the deletion job is queued.
     */
    public function approvePermanentDeletion(AccountDeletionRequest $request, User $actor): void
    {
        $account = $request->account;

        if ($request->status !== AccountDeletionRequest::PENDING) {
            throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
        }

        if (config('wcc.google.require_second_approver', true) && $request->requested_by === $actor->getKey()) {
            throw ValidationException::withMessages(['request' => 'A different Super Admin must approve a permanent deletion.']);
        }

        $this->requireStatus($account, [AccountStatus::SoftDeleted], 'The account is no longer soft-deleted.');

        DB::transaction(function () use ($request, $account, $actor) {
            $request->forceFill(['status' => AccountDeletionRequest::APPROVED, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();

            $account->forceFill([
                'previous_status' => $account->account_status,
                'account_status' => AccountStatus::DeletionPending,
                'pending_operation' => AccountOperation::PermanentDelete,
                'pending_since' => now(),
            ])->save();

            $this->history->record($account, 'permanent_deletion_approved', AccountStatus::SoftDeleted, AccountStatus::DeletionPending,
                ['deletion_request' => $request->uuid], $actor);
            $this->audit->record('account.permanent_deletion_approved', $account,
                new: ['deletion_request' => $request->uuid, 'requested_by' => $request->requester?->email],
                organisation: $account->organisation, actor: $actor);
        });

        $this->dispatch($account, AccountOperation::PermanentDelete, $actor, ['deletion_request_id' => $request->getKey()]);
    }

    public function rejectPermanentDeletion(AccountDeletionRequest $request, string $note, User $actor): void
    {
        if ($request->status !== AccountDeletionRequest::PENDING) {
            throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
        }

        DB::transaction(function () use ($request, $note, $actor) {
            $request->forceFill([
                'status' => AccountDeletionRequest::REJECTED, 'decided_by' => $actor->getKey(),
                'decided_at' => now(), 'decision_note' => $note,
            ])->save();

            $this->history->record($request->account, 'permanent_deletion_rejected', details: ['deletion_request' => $request->uuid, 'note' => $note], actor: $actor);
            $this->audit->record('account.permanent_deletion_rejected', $request->account,
                new: ['deletion_request' => $request->uuid, 'note' => $note],
                organisation: $request->account->organisation, actor: $actor);
        });
    }

    /**
     * The ONLY way an account's organisation changes. Used for the
     * Unassigned Accounts queue and for explicit transfers.
     */
    public function assignOrganisation(GoogleAccount $account, Organisation $organisation, User $actor, ?string $reason = null): void
    {
        if ($account->organisation_id === $organisation->getKey()) {
            return;
        }

        // A transfer while a Google operation is in flight would bill or audit the wrong organisation.
        if ($account->organisation_id !== null && $account->pending_operation !== null) {
            throw ValidationException::withMessages([
                'organisation' => 'A Google Workspace change is still being applied to this mailbox. Try again when it has finished.',
            ]);
        }

        DB::transaction(function () use ($account, $organisation, $actor, $reason) {
            $previous = $account->organisation;
            $domainName = Str::after($account->primary_email, '@');

            $domain = OrganisationDomain::query()
                ->where('organisation_id', $organisation->getKey())
                ->where('domain_name', $domainName)
                ->first();

            $billable = in_array($account->account_status, [AccountStatus::Active, AccountStatus::Suspended], true);

            $account->forceFill([
                'organisation_id' => $organisation->getKey(),
                'domain_id' => $domain?->getKey(),
                'billing_status' => $billable ? BillingStatus::Billable : $account->billing_status,
                'billable_from' => $billable ? CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString() : $account->billable_from,
                'updated_by' => $actor->getKey(),
            ])->save();

            // Billing: the previous organisation is charged up to yesterday, the new one from today.
            if ($billable) {
                app(BillingPeriodTracker::class)->open($account, $account->billable_from);
            }

            $details = array_filter([
                'from' => $previous?->uuid,
                'to' => $organisation->uuid,
                'billable_from' => $account->billable_from?->toDateString(),
                'reason' => $reason,
            ], fn ($value) => $value !== null);

            $event = $previous === null ? 'organisation_assigned' : 'organisation_reassigned';

            $this->history->record($account, $event, details: $details, actor: $actor);
            $this->audit->record('account.'.$event, $account,
                previous: ['organisation' => $previous?->uuid], new: array_filter(['organisation' => $organisation->uuid, 'reason' => $reason]),
                organisation: $organisation, actor: $actor);

            // The previous organisation sees the move in its own audit trail too.
            if ($previous !== null) {
                $this->audit->record('account.organisation_reassigned', $account,
                    previous: ['organisation' => $previous->uuid], new: array_filter(['organisation' => $organisation->uuid, 'reason' => $reason]),
                    organisation: $previous, actor: $actor);
            }
        });
    }

    public function openDeletionRequest(GoogleAccount $account): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()
            ->where('google_account_id', $account->getKey())
            ->whereIn('status', [AccountDeletionRequest::PENDING, AccountDeletionRequest::APPROVED])
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $details
     */
    private function begin(GoogleAccount $account, AccountOperation $operation, array $attributes, string $event, array $details, User $actor): void
    {
        DB::transaction(function () use ($account, $operation, $attributes, $event, $details, $actor) {
            $from = $account->account_status;

            $account->forceFill($attributes + [
                'previous_status' => $from,
                'account_status' => $operation->pendingStatus(),
                'pending_operation' => $operation,
                'pending_since' => now(),
                'last_error' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->history->record($account, $event, $from, $operation->pendingStatus(), $details, $actor);
            $this->audit->record('account.'.$event, $account, new: $details ?: null, organisation: $account->organisation, actor: $actor);
        });

        $this->dispatch($account, $operation, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(GoogleAccount $account, AccountOperation $operation, User $actor, array $payload = []): void
    {
        $operation->jobClass()::dispatch($account->getKey(), $actor->getKey(), $payload);
    }

    /**
     * @param  list<AccountStatus>  $allowed
     */
    private function requireStatus(GoogleAccount $account, array $allowed, string $message): void
    {
        if (! in_array($account->account_status, $allowed, true) || $account->pending_operation !== null) {
            throw ValidationException::withMessages(['account' => $message]);
        }
    }
}
