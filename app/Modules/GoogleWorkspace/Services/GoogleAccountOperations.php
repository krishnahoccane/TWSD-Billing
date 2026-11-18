<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\BillingPeriodTracker;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\NewWorkspaceUser;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes a requested operation against the Workspace provider and then
 * records the result locally. Called by the queued jobs.
 *
 * Every method is idempotent: it first checks that the account is still
 * waiting for exactly this operation, so a duplicated or retried job does
 * nothing the second time. The provider call always comes BEFORE the local
 * transaction; if the local write then fails, the retry finds the work
 * already done in Google and only repeats the local part.
 */
class GoogleAccountOperations
{
    public function __construct(
        private readonly WorkspaceProviderInterface $provider,
        private readonly AccountHistoryRecorder $history,
        private readonly InitialPasswordVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    public function create(GoogleAccount $account, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::Create)) {
            return;
        }

        $password = $this->vault->generate();

        // Reconciliation: an earlier attempt may have created the user in
        // Google and then failed to save locally. Never create a second one.
        $existing = $this->provider->getUser($account->google_user_id ?? $account->primary_email);

        if ($existing !== null) {
            $this->provider->updateUser($existing->id, ['password' => $password, 'change_password_at_next_login' => true]);
            $user = $existing;
        } else {
            $user = $this->provider->createUser(new NewWorkspaceUser(
                primaryEmail: $account->primary_email,
                givenName: $account->first_name,
                familyName: $account->last_name,
                password: $password,
                orgUnitPath: $account->organisational_unit_path,
                recoveryEmail: $account->recovery_email,
                recoveryPhone: $account->recovery_phone,
            ));
        }

        DB::transaction(function () use ($account, $user, $password, $actor, $existing) {
            $from = $account->account_status;

            $account->forceFill([
                'google_user_id' => $user->id,
                'account_status' => AccountStatus::Active,
                'is_suspended' => false,
                'billing_status' => $account->organisation_id ? BillingStatus::Billable : BillingStatus::Pending,
                'billable_from' => $this->billingToday(),
                'google_created_at' => $user->createdAt ?? now(),
                'last_synced_at' => now(),
            ] + $this->cleared())->save();

            $recipient = $actor ?? $account->creator;

            if ($recipient !== null) {
                $this->vault->store($account, $recipient, $password);
            }

            // Billing: chargeable from the day it became active.
            app(BillingPeriodTracker::class)->open($account, $account->billable_from);

            app(NotificationEvents::class)->accountCreated($account);

            $this->history->record($account, 'created', $from, AccountStatus::Active, [
                'google_user_id' => $user->id,
                'adopted_existing_google_user' => $existing !== null,
                'billable_from' => $account->billable_from?->toDateString(),
            ], $actor);

            $this->audit->record('account.created', $account, new: ['email' => $account->primary_email, 'google_user_id' => $user->id],
                organisation: $account->organisation, actor: $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $changes  validated, already limited to editable fields
     */
    public function update(GoogleAccount $account, array $changes, ?User $actor = null): void
    {
        if ($changes === [] || $account->google_user_id === null) {
            return;
        }

        $map = [
            'first_name' => 'first_name', 'last_name' => 'last_name', 'display_name' => 'display_name',
            'recovery_email' => 'recovery_email', 'recovery_phone' => 'recovery_phone',
            'organisational_unit_path' => 'org_unit_path',
        ];

        $remote = [];

        foreach ($map as $local => $key) {
            if (array_key_exists($local, $changes)) {
                $remote[$key] = $changes[$local];
            }
        }

        $this->provider->updateUser($account->google_user_id, $remote);

        DB::transaction(function () use ($account, $changes, $actor) {
            $previous = $account->only(array_keys($changes));

            $account->forceFill($changes + ['updated_by' => $actor?->getKey(), 'last_error' => null, 'last_synced_at' => now()])->save();

            $this->history->record($account, 'updated', details: ['fields' => array_keys($changes)], actor: $actor);
            $this->audit->record('account.updated', $account, previous: $previous, new: $changes,
                organisation: $account->organisation, actor: $actor);
        });
    }

    /**
     * Set a new password in Google. A generated password ($password null)
     * is created here, never queued, and kept for the requesting user to
     * reveal once (same vault as a new mailbox's first password). A typed
     * password reaches this job only inside the encrypted job payload.
     * Neither is ever logged, audited or stored in plain text.
     */
    public function resetPassword(GoogleAccount $account, #[\SensitiveParameter] ?string $password, bool $changeAtNextLogin, ?User $actor = null): void
    {
        if ($account->google_user_id === null) {
            throw new WorkspaceException('This account has no Google user to change.');
        }

        $generated = $password === null;
        $password ??= $this->vault->generate();

        $this->provider->updateUser($account->google_user_id, ['password' => $password, 'change_password_at_next_login' => $changeAtNextLogin]);

        DB::transaction(function () use ($account, $password, $generated, $changeAtNextLogin, $actor) {
            if ($generated && $actor !== null) {
                $this->vault->store($account, $actor, $password);
            }

            $account->forceFill(['last_error' => null, 'last_synced_at' => now(), 'updated_by' => $actor?->getKey()])->save();

            $details = ['generated' => $generated, 'change_at_next_login' => $changeAtNextLogin];
            $this->history->record($account, 'password_reset', details: $details, actor: $actor);
            $this->audit->record('account.password_reset', $account, new: $details, organisation: $account->organisation, actor: $actor);
        });
    }

    public function turnOffTwoStepVerification(GoogleAccount $account, ?User $actor = null): void
    {
        if ($account->google_user_id === null) {
            throw new WorkspaceException('This account has no Google user to change.');
        }

        $this->provider->turnOffTwoStepVerification($account->google_user_id);

        DB::transaction(function () use ($account, $actor) {
            $account->forceFill(['last_error' => null, 'last_synced_at' => now(), 'updated_by' => $actor?->getKey()])->save();

            $this->history->record($account, 'two_step_verification_turned_off', actor: $actor);
            $this->audit->record('account.two_step_verification_turned_off', $account, organisation: $account->organisation, actor: $actor);
        });
    }

    /** A password reset or 2SV change failed for good: shown on the account and notified. */
    public function recordActionFailure(GoogleAccount $account, string $action, Throwable $error): void
    {
        $message = Str::limit(class_basename($error).': '.$error->getMessage(), 1000);

        DB::transaction(function () use ($account, $action, $message) {
            $account->forceFill(['last_error' => $message])->save();
            $this->history->record($account, $action.'_failed', details: ['error' => Str::limit($message, 300)]);
            $this->audit->record('account.'.$action.'_failed', $account, new: ['error' => Str::limit($message, 300)], organisation: $account->organisation);
        });

        app(NotificationEvents::class)->accountOperationFailed($account, $action, $message);
    }

    public function suspend(GoogleAccount $account, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::Suspend)) {
            return;
        }

        $this->provider->suspendUser($account->google_user_id);
        $sessionsRevoked = $this->signOutQuietly($account);

        DB::transaction(function () use ($account, $actor, $sessionsRevoked) {
            $from = $account->previous_status;

            $account->forceFill(['account_status' => AccountStatus::Suspended, 'is_suspended' => true, 'last_synced_at' => now()] + $this->cleared())->save();

            // Suspended mailboxes stay billable (business rule): billing_status is untouched.
            // A non-payment suspension sends one summary for the organisation instead.
            if ($account->suspension_type !== 'non_payment') {
                app(NotificationEvents::class)->accountSuspended($account);
            }

            $this->history->record($account, 'suspended', $from, AccountStatus::Suspended,
                ['reason' => $account->suspension_reason, 'sessions_revoked' => $sessionsRevoked], $actor);
            $this->audit->record('account.suspended', $account, previous: ['status' => $from?->value],
                new: ['status' => 'suspended', 'reason' => $account->suspension_reason],
                organisation: $account->organisation, actor: $actor);
        });
    }

    public function reactivate(GoogleAccount $account, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::Reactivate)) {
            return;
        }

        $this->provider->reactivateUser($account->google_user_id);
        $paymentReactivation = $account->suspension_type === 'non_payment';

        DB::transaction(function () use ($account, $actor, $paymentReactivation) {
            $account->forceFill([
                'account_status' => AccountStatus::Active, 'is_suspended' => false,
                'suspension_reason' => null, 'suspension_type' => null, 'last_synced_at' => now(),
            ] + $this->cleared())->save();

            // Mailboxes reactivated after payment get one summary notification instead.
            if (! $paymentReactivation) {
                app(NotificationEvents::class)->accountReactivated($account);
            }

            $this->history->record($account, 'reactivated', AccountStatus::Suspended, AccountStatus::Active, actor: $actor);
            $this->audit->record('account.reactivated', $account, previous: ['status' => 'suspended'], new: ['status' => 'active'],
                organisation: $account->organisation, actor: $actor);
        });
    }

    /**
     * Stage 1 deletion. The Google user is suspended and signed out — it is
     * NOT deleted, and its immutable id is kept.
     */
    public function softDelete(GoogleAccount $account, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::SoftDelete)) {
            return;
        }

        $this->provider->suspendUser($account->google_user_id);
        $sessionsRevoked = $this->signOutQuietly($account);

        DB::transaction(function () use ($account, $actor, $sessionsRevoked) {
            $from = $account->previous_status;

            $account->forceFill([
                'account_status' => AccountStatus::SoftDeleted,
                'is_suspended' => true,
                'billing_status' => BillingStatus::NonBillable,
                'deleted_at' => now(),
                'last_synced_at' => now(),
            ] + $this->cleared())->save();

            // Billing: not charged from the effective deletion date.
            app(BillingPeriodTracker::class)->close($account, $account->deletion_effective_date);

            app(NotificationEvents::class)->accountSoftDeleted($account);

            $this->history->record($account, 'soft_deleted', $from, AccountStatus::SoftDeleted, [
                'reason' => $account->deletion_reason,
                'billing_stops_on' => $account->deletion_effective_date?->toDateString(),
                'sessions_revoked' => $sessionsRevoked,
            ], $actor);
            $this->audit->record('account.soft_deleted', $account, previous: ['status' => $from?->value],
                new: ['status' => 'soft_deleted', 'reason' => $account->deletion_reason,
                    'effective_date' => $account->deletion_effective_date?->toDateString()],
                organisation: $account->organisation, actor: $actor);
        });
    }

    /** Undo a soft deletion: the mailbox becomes active and billable again from today. */
    public function restore(GoogleAccount $account, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::Restore)) {
            return;
        }

        $this->provider->reactivateUser($account->google_user_id);

        DB::transaction(function () use ($account, $actor) {
            $account->forceFill([
                'account_status' => AccountStatus::Active,
                'is_suspended' => false,
                'billing_status' => $account->organisation_id ? BillingStatus::Billable : BillingStatus::Pending,
                'billable_from' => $this->billingToday(),
                'deleted_at' => null,
                'deletion_reason' => null,
                'deletion_effective_date' => null,
                'suspension_reason' => null,
                'last_synced_at' => now(),
            ] + $this->cleared())->save();

            app(BillingPeriodTracker::class)->open($account, $account->billable_from);

            $this->history->record($account, 'restored', AccountStatus::SoftDeleted, AccountStatus::Active,
                ['billable_from' => $account->billable_from?->toDateString()], $actor);
            $this->audit->record('account.restored', $account, previous: ['status' => 'soft_deleted'], new: ['status' => 'active'],
                organisation: $account->organisation, actor: $actor);
        });
    }

    /**
     * Stage 2 deletion. Requires an APPROVED deletion request; refuses
     * anything else, whoever calls it.
     */
    public function permanentlyDelete(GoogleAccount $account, AccountDeletionRequest $request, ?User $actor = null): void
    {
        if (! $this->awaiting($account, AccountOperation::PermanentDelete)) {
            return;
        }

        if ($request->status !== AccountDeletionRequest::APPROVED || $request->google_account_id !== $account->getKey()) {
            throw new WorkspaceException('Permanent deletion requires an approved deletion request for this account.');
        }

        $response = ['provider' => $this->provider->name(), 'google_user_id' => $account->google_user_id];

        if ($this->provider->getUser($account->google_user_id) !== null) {
            $this->provider->deleteUser($account->google_user_id);
            $response['result'] = 'deleted';
        } else {
            $response['result'] = 'already_absent';
        }

        DB::transaction(function () use ($account, $request, $actor, $response) {
            $account->forceFill([
                'account_status' => AccountStatus::PermanentlyDeleted,
                'billing_status' => BillingStatus::NonBillable,
                'permanently_deleted_at' => now(),
                // Free the address for reuse; the row and google_user_id stay for history and invoices.
                'email_unique_key' => null,
                'last_synced_at' => now(),
            ] + $this->cleared())->save();

            $request->forceFill([
                'status' => AccountDeletionRequest::EXECUTED,
                'executed_at' => now(),
                'api_response' => $response + ['at' => now()->toIso8601String()],
            ])->save();

            $this->history->record($account, 'permanently_deleted', AccountStatus::SoftDeleted, AccountStatus::PermanentlyDeleted,
                ['deletion_request' => $request->uuid, 'api' => $response], $actor);
            $this->audit->record('account.permanently_deleted', $account,
                new: ['email' => $account->primary_email, 'deletion_request' => $request->uuid, 'api' => $response],
                organisation: $account->organisation, actor: $actor);
        });
    }

    /**
     * Called when a job has finally failed (retries exhausted or a
     * non-retryable error). Nothing is hidden: the error is stored on the
     * account, in its history and in the audit log.
     */
    public function markFailed(GoogleAccount $account, AccountOperation $operation, Throwable $error): void
    {
        if ($account->pending_operation !== $operation) {
            return;
        }

        DB::transaction(function () use ($account, $operation, $error) {
            $from = $account->account_status;
            // A failed creation has no earlier state to return to.
            $to = $operation === AccountOperation::Create
                ? AccountStatus::CreationFailed
                : ($account->previous_status ?? AccountStatus::SyncFailed);

            $account->forceFill([
                'account_status' => $to,
                'pending_operation' => null,
                'previous_status' => null,
                'pending_since' => null,
                'last_error' => Str::limit(class_basename($error).': '.$error->getMessage(), 1000),
            ])->save();

            if ($operation === AccountOperation::PermanentDelete) {
                AccountDeletionRequest::query()
                    ->where('google_account_id', $account->getKey())
                    ->where('status', AccountDeletionRequest::APPROVED)
                    ->update(['status' => AccountDeletionRequest::FAILED]);
            }

            $operation === AccountOperation::Create
                ? app(NotificationEvents::class)->accountCreationFailed($account, (string) $account->last_error)
                : app(NotificationEvents::class)->accountOperationFailed($account, $operation->value, (string) $account->last_error);

            $this->history->record($account, 'operation_failed', $from, $to,
                ['operation' => $operation->value, 'error' => $account->last_error]);
            $this->audit->record('account.operation_failed', $account,
                new: ['operation' => $operation->value, 'error' => $account->last_error],
                organisation: $account->organisation, status: AuditLogger::STATUS_FAILURE);
        });
    }

    private function awaiting(GoogleAccount $account, AccountOperation $operation): bool
    {
        return $account->pending_operation === $operation;
    }

    /**
     * Signing out is best-effort: a failure here must not undo the
     * suspension that already succeeded, but it is recorded.
     */
    private function signOutQuietly(GoogleAccount $account): bool
    {
        try {
            $this->provider->signOutUser($account->google_user_id);

            return true;
        } catch (WorkspaceTransientException $e) {
            throw $e; // retry the job; suspend is idempotent
        } catch (WorkspaceException) {
            return false;
        }
    }

    /**
     * @return array<string, null>
     */
    private function cleared(): array
    {
        return ['pending_operation' => null, 'previous_status' => null, 'pending_since' => null, 'last_error' => null];
    }

    private function billingToday(): string
    {
        return CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString();
    }
}
