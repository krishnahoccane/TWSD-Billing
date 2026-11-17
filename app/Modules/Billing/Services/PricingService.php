<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\AccountPriceOverride;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\OrganisationPrice;
use App\Modules\Billing\Models\PricingHistory;
use App\Modules\Billing\Models\PricingPlan;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every price change goes through here: effective-dated, never
 * overwriting an earlier row, recorded in pricing_history and audited.
 * Callers authorise first (pricing.manage).
 *
 * A new price cannot start inside a period that has already been
 * invoiced, so issued invoices and the price list can never disagree.
 */
class PricingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setPlatformPrice(int $priceMinor, string $effectiveFrom, User $actor, string $name = 'Standard mailbox'): PricingPlan
    {
        return $this->replace(
            PricingPlan::query()->where('service_type', PriceResolver::SERVICE),
            fn () => new PricingPlan(['name' => $name, 'service_type' => PriceResolver::SERVICE, 'billing_model' => 'fixed_monthly']),
            $priceMinor, $effectiveFrom, $actor, 'platform', null, null,
        );
    }

    public function setOrganisationPrice(Organisation $organisation, int $priceMinor, string $effectiveFrom, User $actor): OrganisationPrice
    {
        $this->assertNotInvoiced($organisation, $effectiveFrom);

        return $this->replace(
            OrganisationPrice::query()->where('organisation_id', $organisation->getKey())->where('service_type', PriceResolver::SERVICE),
            fn () => new OrganisationPrice(['organisation_id' => $organisation->getKey(), 'service_type' => PriceResolver::SERVICE, 'billing_model' => 'fixed_monthly']),
            $priceMinor, $effectiveFrom, $actor, 'organisation', $organisation, null,
        );
    }

    public function setAccountOverride(GoogleAccount $account, int $priceMinor, string $effectiveFrom, ?string $reason, User $actor): AccountPriceOverride
    {
        $organisation = $account->organisation;

        if ($organisation === null) {
            throw ValidationException::withMessages(['account' => 'Assign the mailbox to an organisation before setting its price.']);
        }

        $this->assertNotInvoiced($organisation, $effectiveFrom);

        return $this->replace(
            AccountPriceOverride::query()->where('google_account_id', $account->getKey()),
            fn () => new AccountPriceOverride(['google_account_id' => $account->getKey(), 'organisation_id' => $organisation->getKey(), 'reason' => $reason]),
            $priceMinor, $effectiveFrom, $actor, 'account', $organisation, $account,
        );
    }

    /** The mailbox goes back to the organisation / platform price from $effectiveFrom. */
    public function endAccountOverride(AccountPriceOverride $override, string $effectiveFrom, User $actor): void
    {
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        $this->assertNotInvoiced($override->organisation, $from->toDateString());

        if ($override->status !== 'active' || $override->effective_until !== null || $from->lte($override->effective_from)) {
            throw ValidationException::withMessages(['effective_from' => 'Choose a date after the override started.']);
        }

        DB::transaction(function () use ($override, $from, $actor) {
            $override->forceFill(['effective_until' => $from->subDay()->toDateString()])->save();

            $this->history('account', 'ended', $override->organisation, $override->account, $override->monthly_price_minor, null, $from->toDateString(), $actor);
            $this->audit->record('pricing.account_override_ended', $override->account,
                previous: ['price_minor' => $override->monthly_price_minor], new: ['ends_before' => $from->toDateString()],
                organisation: $override->organisation, actor: $actor);
        });
    }

    /**
     * @template T of Model
     *
     * @param  callable(): T  $make
     * @return T
     */
    private function replace(Builder $existing, callable $make, int $priceMinor, string $effectiveFrom, User $actor, string $scope, ?Organisation $organisation, ?GoogleAccount $account): Model
    {
        if ($priceMinor < 0) {
            throw ValidationException::withMessages(['price' => 'The price cannot be negative.']);
        }

        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return DB::transaction(function () use ($existing, $make, $priceMinor, $from, $actor, $scope, $organisation, $account) {
            $current = (clone $existing)->where('status', 'active')->whereNull('effective_until')->lockForUpdate()->orderByDesc('effective_from')->first();

            if ($current !== null) {
                if ($from->lte($current->effective_from)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'The new price must start after the current one ('.$current->effective_from->format('d M Y').').',
                    ]);
                }

                // The earlier price is closed, never edited or deleted.
                $current->forceFill(['effective_until' => $from->subDay()->toDateString()])->save();
            }

            $row = $make();
            $row->forceFill([
                'monthly_price_minor' => $priceMinor,
                'currency' => config('wcc.billing.currency'),
                'effective_from' => $from->toDateString(),
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ])->save();

            $this->history($scope, 'created', $organisation, $account, $current?->monthly_price_minor, $priceMinor, $from->toDateString(), $actor);
            $this->audit->record('pricing.'.$scope.'_price_set', $account ?? $organisation ?? $row,
                previous: ['price_minor' => $current?->monthly_price_minor],
                new: ['price_minor' => $priceMinor, 'effective_from' => $from->toDateString()],
                organisation: $organisation, actor: $actor);

            return $row;
        });
    }

    private function assertNotInvoiced(Organisation $organisation, string $effectiveFrom): void
    {
        $lastInvoiced = Invoice::query()
            ->where('organisation_id', $organisation->getKey())
            ->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
            ->max('period_end');

        if ($lastInvoiced !== null && CarbonImmutable::parse($effectiveFrom)->lte(CarbonImmutable::parse($lastInvoiced))) {
            throw ValidationException::withMessages([
                'effective_from' => 'This period has already been invoiced. Choose a date after '.CarbonImmutable::parse($lastInvoiced)->format('d M Y').', or use an adjustment.',
            ]);
        }
    }

    private function history(string $scope, string $action, ?Organisation $organisation, ?GoogleAccount $account, ?int $old, ?int $new, string $from, User $actor): void
    {
        PricingHistory::query()->create([
            'scope' => $scope,
            'organisation_id' => $organisation?->getKey(),
            'google_account_id' => $account?->getKey(),
            'action' => $action,
            'old_price_minor' => $old,
            'new_price_minor' => $new,
            'currency' => config('wcc.billing.currency'),
            'effective_from' => $from,
            'user_id' => $actor->getKey(),
        ]);
    }
}
