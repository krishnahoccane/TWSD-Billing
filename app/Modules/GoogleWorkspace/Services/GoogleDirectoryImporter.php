<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Billing\Services\BillingPeriodTracker;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\GoogleWorkspace\Data\WorkspaceUser;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleOrganisationalUnit;
use App\Modules\GoogleWorkspace\Models\GoogleSyncLog;
use App\Modules\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports and reconciles the Workspace directory.
 *
 * Ownership rules (business-critical):
 *  - a NEW account is assigned to an organisation only when its domain is
 *    mapped to one; otherwise it stays unassigned for Super Admin review;
 *  - an EXISTING account's organisation is NEVER changed here, even if its
 *    address moved to another organisation's domain.
 */
class GoogleDirectoryImporter
{
    public const TYPE_FULL = 'users_full';

    public const TYPE_SYNC = 'users_sync';

    public const TYPE_DOMAINS = 'domains';

    private const MAX_PAGES = 2000;

    public function __construct(
        private readonly WorkspaceProviderInterface $provider,
        private readonly AccountHistoryRecorder $history,
    ) {}

    /**
     * Fetches EVERY page of users. With $flagMissing (full import) local
     * accounts that Google no longer returns are flagged, never deleted.
     */
    public function importUsers(bool $flagMissing = false, ?User $triggeredBy = null): GoogleSyncLog
    {
        $log = $this->start($flagMissing ? self::TYPE_FULL : self::TYPE_SYNC, $triggeredBy);

        try {
            $domains = OrganisationDomain::query()
                ->where('status', 'active')
                ->get(['id', 'organisation_id', 'domain_name'])
                ->keyBy('domain_name');

            $seen = [];
            $notes = [];
            $pageToken = null;

            do {
                $page = $this->provider->listUsers($pageToken);
                $log->pages++;

                foreach ($page->users as $remote) {
                    $log->fetched++;
                    $seen[$remote->id] = true;

                    try {
                        $this->importUser($remote, $domains, $log, $notes);
                    } catch (Throwable $e) {
                        $log->errors++;
                        $notes['errors'][] = Str::limit($remote->primaryEmail.': '.$e->getMessage(), 300);
                        report($e);
                    }
                }

                $pageToken = $page->nextPageToken;

                if ($log->pages >= self::MAX_PAGES) {
                    throw new RuntimeException('Aborted: the user listing did not end after '.self::MAX_PAGES.' pages.');
                }
            } while ($pageToken !== null);

            if ($flagMissing) {
                $this->flagMissing($seen, $log, $notes);
            }

            return $this->finish($log, $notes);
        } catch (Throwable $e) {
            $this->fail($log, $e);

            throw $e;
        }
    }

    /**
     * Compares Google's domain list with the mapped domains. Domains that
     * exist in Google but are not mapped to an organisation are reported,
     * not assigned.
     */
    public function importDomains(?User $triggeredBy = null): GoogleSyncLog
    {
        $log = $this->start(self::TYPE_DOMAINS, $triggeredBy);

        try {
            $remote = collect($this->provider->listDomains())->keyBy('name');
            $notes = [];

            foreach (OrganisationDomain::query()->where('status', '!=', 'removed')->get() as $domain) {
                $match = $remote->get($domain->domain_name);

                $domain->forceFill([
                    'verification_status' => $match === null ? 'not_found' : ($match->verified ? 'verified' : 'pending'),
                    'is_primary' => $match?->isPrimary ?? false,
                    'google_domain_id' => $match?->name,
                ]);

                if ($domain->isDirty()) {
                    $domain->save();
                    $log->updated++;
                }
            }

            $log->fetched = $remote->count();
            $mapped = OrganisationDomain::query()->where('status', '!=', 'removed')->pluck('domain_name')->all();
            $notes['unmapped_google_domains'] = $remote->keys()->diff($mapped)->values()->all();
            $log->unassigned = count($notes['unmapped_google_domains']);

            foreach ($this->provider->listOrgUnits() as $unit) {
                GoogleOrganisationalUnit::query()->updateOrCreate(
                    ['path' => $unit->path],
                    ['google_org_unit_id' => $unit->id ?: null, 'name' => $unit->name, 'parent_path' => $unit->parentPath, 'last_synced_at' => now()],
                );
            }

            return $this->finish($log, $notes);
        } catch (Throwable $e) {
            $this->fail($log, $e);

            throw $e;
        }
    }

    /**
     * @param  Collection<string, OrganisationDomain>  $domains
     * @param  array<string, mixed>  $notes
     */
    private function importUser(WorkspaceUser $remote, $domains, GoogleSyncLog $log, array &$notes): void
    {
        DB::transaction(function () use ($remote, $domains, $log, &$notes) {
            $account = GoogleAccount::query()->where('google_user_id', $remote->id)->lockForUpdate()->first();

            // A locally requested mailbox whose Google id was never saved.
            $account ??= GoogleAccount::query()
                ->whereNull('google_user_id')
                ->where('email_unique_key', $remote->primaryEmail)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                $this->createFromRemote($remote, $domains->get($remote->domain()), $log);

                return;
            }

            $this->refresh($account, $remote, $log, $notes);
        });
    }

    private function createFromRemote(WorkspaceUser $remote, ?OrganisationDomain $domain, GoogleSyncLog $log): void
    {
        $status = $remote->suspended ? AccountStatus::Suspended : AccountStatus::Active;

        $account = new GoogleAccount([
            'primary_email' => $remote->primaryEmail,
            'first_name' => Str::limit($remote->givenName, 60, ''),
            'last_name' => Str::limit($remote->familyName, 60, ''),
            'display_name' => $remote->displayName,
            'recovery_email' => $remote->recoveryEmail,
            'recovery_phone' => $remote->recoveryPhone,
            'organisational_unit_path' => $remote->orgUnitPath,
        ]);

        $account->forceFill([
            'organisation_id' => $domain?->organisation_id,
            'domain_id' => $domain?->id,
            'google_user_id' => $remote->id,
            'email_unique_key' => $remote->primaryEmail,
            'account_status' => $status,
            'is_suspended' => $remote->suspended,
            // Billing starts on the day the mailbox enters this system; earlier
            // periods are outside its records (see KNOWN_LIMITATIONS D-18).
            'billing_status' => $domain ? BillingStatus::Billable : BillingStatus::Pending,
            'billable_from' => $domain ? CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString() : null,
            'google_created_at' => $remote->createdAt,
            'last_synced_at' => now(),
        ])->save();

        app(BillingPeriodTracker::class)->open($account, $account->billable_from);

        $this->history->record($account, 'imported', null, $status, ['assigned' => $domain !== null]);

        $log->created++;

        if ($domain === null) {
            $log->unassigned++;
        }
    }

    /**
     * @param  array<string, mixed>  $notes
     */
    private function refresh(GoogleAccount $account, WorkspaceUser $remote, GoogleSyncLog $log, array &$notes): void
    {
        $from = $account->account_status;

        // Directory facts only. organisation_id and domain_id are not touched.
        $account->forceFill([
            'google_user_id' => $remote->id,
            'primary_email' => $remote->primaryEmail,
            'email_unique_key' => $account->account_status === AccountStatus::PermanentlyDeleted ? null : $remote->primaryEmail,
            'first_name' => Str::limit($remote->givenName, 60, ''),
            'last_name' => Str::limit($remote->familyName, 60, ''),
            'display_name' => $remote->displayName,
            'recovery_email' => $remote->recoveryEmail,
            'recovery_phone' => $remote->recoveryPhone,
            'organisational_unit_path' => $remote->orgUnitPath,
            'is_suspended' => $remote->suspended,
            'google_created_at' => $remote->createdAt ?? $account->google_created_at,
            'missing_from_google_at' => null,
        ]);

        // Status drift is reconciled only when no operation is in flight.
        if ($account->pending_operation === null) {
            if ($from === AccountStatus::Active && $remote->suspended) {
                $account->account_status = AccountStatus::Suspended;
                $account->suspension_reason ??= 'Suspended directly in Google Workspace';
            } elseif ($from === AccountStatus::Suspended && ! $remote->suspended) {
                $account->account_status = AccountStatus::Active;
                $account->suspension_reason = null;
            } elseif ($from === AccountStatus::SoftDeleted && ! $remote->suspended) {
                // Not auto-corrected: someone re-enabled a deleted mailbox in Google.
                $notes['warnings'][] = "{$remote->primaryEmail} is soft-deleted here but active in Google.";
            }
        }

        $changed = $account->isDirty();
        $account->last_synced_at = now();
        $account->save();

        if ($account->account_status !== $from) {
            $this->history->record($account, 'status_reconciled', $from, $account->account_status, ['source' => 'google']);
        }

        if ($changed) {
            $log->updated++;
        }
    }

    /**
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $notes
     */
    private function flagMissing(array $seen, GoogleSyncLog $log, array &$notes): void
    {
        GoogleAccount::query()
            ->whereNotNull('google_user_id')
            ->whereNull('missing_from_google_at')
            ->where('account_status', '!=', AccountStatus::PermanentlyDeleted->value)
            ->select(['id', 'google_user_id', 'primary_email'])
            ->chunkById(500, function ($accounts) use ($seen, $log, &$notes) {
                foreach ($accounts as $account) {
                    if (isset($seen[$account->google_user_id])) {
                        continue;
                    }

                    GoogleAccount::query()->whereKey($account->id)->update(['missing_from_google_at' => now()]);
                    $log->missing++;
                    $notes['missing'][] = $account->primary_email;
                }
            });
    }

    private function start(string $type, ?User $triggeredBy): GoogleSyncLog
    {
        return GoogleSyncLog::query()->create([
            'type' => $type,
            'status' => GoogleSyncLog::RUNNING,
            'triggered_by' => $triggeredBy?->getKey(),
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $notes
     */
    private function finish(GoogleSyncLog $log, array $notes): GoogleSyncLog
    {
        foreach (['errors', 'warnings', 'missing'] as $key) {
            if (isset($notes[$key])) {
                $notes[$key] = array_slice($notes[$key], 0, 100);
            }
        }

        $log->forceFill([
            'status' => GoogleSyncLog::COMPLETED,
            'details' => ['provider' => $this->provider->name()] + $notes,
            'finished_at' => now(),
        ])->save();

        return $log;
    }

    private function fail(GoogleSyncLog $log, Throwable $e): void
    {
        app(NotificationEvents::class)->directorySyncFailed($log->type, $e->getMessage());

        $log->forceFill([
            'status' => GoogleSyncLog::FAILED,
            'error_message' => Str::limit(class_basename($e).': '.$e->getMessage(), 1000),
            'finished_at' => now(),
        ])->save();
    }
}
