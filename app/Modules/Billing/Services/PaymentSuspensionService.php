<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentSuspension;
use App\Modules\Billing\Models\PaymentSuspensionAccount;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountService;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Automatic suspension for non-payment, and automatic reactivation once
 * the invoice is paid.
 *
 * Suspension: an invoice still open (balance above the rounding tolerance)
 * after its due date + BILLING_SUSPEND_AFTER_DAYS suspends every ACTIVE
 * mailbox of the organisation. Before anything is changed, every mailbox's
 * state is recorded: "suspended" (was active, suspended now) or
 * "already_suspended" (was suspended by hand — left alone).
 *
 * Reactivation: when no invoice of the organisation is overdue beyond the
 * grace period any more, only the "suspended" rows that are still suspended
 * for non-payment are reactivated. Mailboxes suspended by hand stay
 * suspended. Organisation managers and Operations Admins cannot reactivate
 * a mailbox suspended for non-payment (GoogleAccountPolicy); a Super Admin can.
 */
class PaymentSuspensionService
{
    public function __construct(
        private readonly GoogleAccountService $accounts,
        private readonly AuditLogger $audit,
        private readonly NotificationEvents $notify,
    ) {}

    /** @return array{suspended: int, reactivated: int} */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay();
        $summary = ['suspended' => 0, 'reactivated' => 0];

        // Lift first, so an organisation that has just paid is not re-suspended.
        PaymentSuspension::query()->where('status', PaymentSuspension::ACTIVE)->orderBy('id')->pluck('organisation_id')->unique()
            ->each(function ($organisationId) use (&$summary, $today) {
                $summary['reactivated'] += $this->liftIfPaid(Organisation::query()->findOrFail($organisationId), $today);
            });

        if (! config('wcc.billing.auto_suspend', true)) {
            return $summary;
        }

        $this->overdueInvoices($today)->groupBy('organisation_id')->each(function (Collection $invoices, $organisationId) use (&$summary) {
            $organisation = Organisation::query()->find($organisationId);

            if ($organisation !== null && $organisation->status !== OrganisationStatus::Inactive) {
                $summary['suspended'] += $this->suspend($organisation, $invoices);
            }
        });

        return $summary;
    }

    /**
     * Suspend the organisation's active mailboxes (idempotent: mailboxes
     * already recorded in the open suspension are skipped, new active
     * mailboxes are added). Returns how many suspensions were requested.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function suspend(Organisation $organisation, Collection $invoices): int
    {
        return $this->locked($organisation, function () use ($organisation, $invoices) {
            $numbers = $invoices->pluck('invoice_number')->filter()->implode(', ');
            $reason = 'Unpaid invoice '.$numbers.' — suspended automatically for non-payment.';

            [$suspension, $isNew] = DB::transaction(function () use ($organisation, $invoices, $reason) {
                $suspension = PaymentSuspension::query()->where('organisation_id', $organisation->getKey())
                    ->where('status', PaymentSuspension::ACTIVE)->lockForUpdate()->first();

                if ($suspension !== null) {
                    $suspension->forceFill(['invoice_ids' => array_values(array_unique([...$suspension->invoice_ids, ...$invoices->modelKeys()]))])->save();

                    return [$suspension, false];
                }

                $suspension = PaymentSuspension::query()->create([
                    'organisation_id' => $organisation->getKey(),
                    'status' => PaymentSuspension::ACTIVE,
                    'invoice_ids' => $invoices->modelKeys(),
                    'reason' => $reason,
                    'suspended_at' => now(),
                ]);

                return [$suspension, true];
            });

            $recorded = $suspension->accounts()->pluck('google_account_id')->all();
            $count = 0;

            $mailboxes = GoogleAccount::query()->where('organisation_id', $organisation->getKey())
                ->whereIn('account_status', [AccountStatus::Active->value, AccountStatus::Suspended->value])
                ->whereNotIn('id', $recorded)->with('organisation')->orderBy('id')->get();

            foreach ($mailboxes as $account) {
                // Record the state BEFORE changing anything.
                if ($account->account_status === AccountStatus::Suspended) {
                    $this->record($suspension, $account, PaymentSuspensionAccount::ALREADY_SUSPENDED);

                    continue;
                }

                if ($account->pending_operation !== null || $account->google_user_id === null) {
                    continue; // busy or not in Google yet: picked up on the next run
                }

                try {
                    $this->record($suspension, $account, PaymentSuspensionAccount::SUSPENDED);
                    $this->accounts->requestPaymentSuspension($account, $reason);
                    $count++;
                } catch (ValidationException) {
                    PaymentSuspensionAccount::query()->where('payment_suspension_id', $suspension->getKey())
                        ->where('google_account_id', $account->getKey())->delete();
                }
            }

            // A suspension that failed in Google left the mailbox active: try again.
            foreach ($suspension->accounts()->where('action', PaymentSuspensionAccount::SUSPENDED)->whereNull('reactivated_at')->with('account.organisation')->get() as $row) {
                $account = $row->account;

                if ($account !== null && $account->account_status === AccountStatus::Active && $account->pending_operation === null && ! in_array($account->getKey(), $mailboxes->modelKeys(), true)) {
                    try {
                        $this->accounts->requestPaymentSuspension($account, $reason);
                        $count++;
                    } catch (ValidationException) {
                        // picked up on the next run
                    }
                }
            }

            if ($isNew) {
                $this->audit->record('billing.accounts_suspended_for_non_payment', $suspension, new: [
                    'invoices' => $numbers, 'mailboxes_suspended' => $count,
                    'already_suspended' => $suspension->accounts()->where('action', PaymentSuspensionAccount::ALREADY_SUSPENDED)->count(),
                ], organisation: $organisation);

                $this->notify->accountsSuspendedForNonPayment($organisation, $invoices, $count);
            } elseif ($count > 0) {
                $this->audit->record('billing.accounts_suspended_for_non_payment', $suspension, new: ['mailboxes_suspended' => $count, 'added_later' => true], organisation: $organisation);
            }

            return $count;
        }) ?? 0;
    }

    /**
     * Reactivate what this system suspended once nothing is overdue beyond
     * the grace period any more. Returns how many reactivations were requested.
     */
    public function liftIfPaid(Organisation $organisation, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay();

        if ($this->overdueInvoices($today, $organisation)->isNotEmpty()) {
            return 0;
        }

        return $this->locked($organisation, function () use ($organisation) {
            $suspension = PaymentSuspension::query()->where('organisation_id', $organisation->getKey())
                ->where('status', PaymentSuspension::ACTIVE)->first();

            if ($suspension === null) {
                return 0;
            }

            $count = 0;
            $waiting = 0;

            foreach ($suspension->accounts()->where('action', PaymentSuspensionAccount::SUSPENDED)->whereNull('reactivated_at')->with('account.organisation')->get() as $row) {
                $account = $row->account;

                // Still being suspended in Google: try again on the next run.
                if ($account !== null && $account->pending_operation !== null) {
                    $waiting++;

                    continue;
                }

                if ($account !== null && $account->account_status === AccountStatus::Suspended && $account->suspension_type === 'non_payment') {
                    try {
                        $this->accounts->requestPaymentReactivation($account);
                        $count++;
                    } catch (ValidationException) {
                        $waiting++;

                        continue;
                    }
                }

                // Reactivated now, or already changed by someone else (reactivated
                // by a Super Admin, deleted…): nothing more to do for this mailbox.
                $row->forceFill(['reactivated_at' => now()])->save();
            }

            if ($waiting === 0) {
                $suspension->forceFill(['status' => PaymentSuspension::LIFTED, 'lifted_at' => now(), 'lift_reason' => 'Payment received'])->save();

                $this->audit->record('billing.accounts_reactivated_after_payment', $suspension, new: [
                    'mailboxes_reactivated' => $suspension->accounts()->where('action', PaymentSuspensionAccount::SUSPENDED)->count(),
                    'left_suspended' => $suspension->accounts()->where('action', PaymentSuspensionAccount::ALREADY_SUSPENDED)->count(),
                ], organisation: $organisation);

                $this->notify->accountsReactivatedAfterPayment($organisation, $suspension->accounts()->where('action', PaymentSuspensionAccount::SUSPENDED)->count());
            }

            return $count;
        }) ?? 0;
    }

    /** The open suspension of an organisation, if any. */
    public function activeFor(Organisation $organisation): ?PaymentSuspension
    {
        return PaymentSuspension::query()->where('organisation_id', $organisation->getKey())->where('status', PaymentSuspension::ACTIVE)->first();
    }

    /**
     * Open invoices past due date + grace days with more than a rounding
     * difference left to pay.
     *
     * @return Collection<int, Invoice>
     */
    public function overdueInvoices(CarbonImmutable $today, ?Organisation $organisation = null): Collection
    {
        $lastGraceDay = $today->subDays((int) config('wcc.billing.suspend_after_days', 2) + 1);

        return Invoice::query()
            ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
            ->where('balance_minor', '>', (int) config('wcc.billing.rounding_tolerance_minor', 100))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $lastGraceDay->toDateString())
            ->when($organisation, fn ($query) => $query->where('organisation_id', $organisation->getKey()))
            ->orderBy('due_date')->orderBy('id')
            ->get();
    }

    private function record(PaymentSuspension $suspension, GoogleAccount $account, string $action): void
    {
        PaymentSuspensionAccount::query()->create([
            'payment_suspension_id' => $suspension->getKey(),
            'google_account_id' => $account->getKey(),
            'status_before' => $account->account_status->value,
            'suspension_type_before' => $account->suspension_type,
            'action' => $action,
        ]);
    }

    /** One suspension change per organisation at a time (scheduler, payment webhook, sync). */
    private function locked(Organisation $organisation, callable $callback): mixed
    {
        try {
            return Cache::lock('payment-suspension:'.$organisation->getKey(), 120)->block(30, $callback);
        } catch (LockTimeoutException) {
            return null; // another run is handling this organisation
        }
    }
}
