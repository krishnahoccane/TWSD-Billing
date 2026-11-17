<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\BillingAdjustment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly run and the read models behind the billing dashboards.
 */
class BillingRunner
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** The previous calendar month in the billing timezone (billing is in arrears). */
    public static function previousMonth(): array
    {
        $start = CarbonImmutable::now(config('wcc.billing.timezone'))->startOfMonth()->subMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * Active and suspended organisations are billed (a suspended
     * organisation keeps its mailboxes); inactive ones are not.
     *
     * @return Collection<int, Organisation>
     */
    public function billableOrganisations(): Collection
    {
        return Organisation::query()
            ->whereIn('status', [OrganisationStatus::Active, OrganisationStatus::Suspended])
            ->orderBy('id')->get();
    }

    /**
     * One organisation failing (for example a missing price) never stops
     * the others; its cycle is marked failed with the reason.
     *
     * @return array{generated: int, skipped: int, failed: int}
     */
    public function run(CarbonImmutable $start, CarbonImmutable $end, ?User $actor = null): array
    {
        $summary = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->billableOrganisations() as $organisation) {
            try {
                $invoice = $this->invoices->generate($organisation, $start, $end, $actor);
                $summary[$invoice === null ? 'skipped' : 'generated']++;
            } catch (BillingException) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Figures for the billing dashboard, limited to the organisations in
     * which the user may view billing.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $scope = $user->organisationScope(Ability::BillingView);
        $accounts = fn () => $scope->apply(GoogleAccount::query());
        $invoices = fn () => $scope->apply(Invoice::query())->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID]);
        $today = CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString();

        // Monthly recurring revenue: today's price of every billable mailbox.
        $mrr = 0;
        $byOrganisation = [];
        $unpriced = 0;

        foreach ($scope->apply(Organisation::query(), 'id')->orderBy('name')->get() as $organisation) {
            $prices = PriceResolver::for($organisation);
            $total = 0;
            $count = 0;

            foreach (GoogleAccount::query()->where('organisation_id', $organisation->getKey())
                ->where('billing_status', BillingStatus::Billable)->pluck('id') as $accountId) {
                $price = $prices->resolve((int) $accountId, $today);
                $price === null ? $unpriced++ : $total += $price['price'];
                $count++;
            }

            if ($count > 0) {
                $byOrganisation[] = ['organisation' => $organisation, 'accounts' => $count, 'mrr_minor' => $total];
            }

            $mrr += $total;
        }

        return [
            'mrr_minor' => $mrr,
            'unpriced_accounts' => $unpriced,
            'billable_accounts' => $accounts()->where('billing_status', BillingStatus::Billable)->count(),
            'suspended_billable' => $accounts()->where('billing_status', BillingStatus::Billable)->where('account_status', AccountStatus::Suspended)->count(),
            'soft_deleted' => $accounts()->where('account_status', AccountStatus::SoftDeleted)->count(),
            'invoiced_minor' => (int) $invoices()->sum('total_minor'),
            'received_minor' => (int) $invoices()->sum('amount_paid_minor'),
            'outstanding_minor' => (int) $invoices()->sum('balance_minor'),
            'overdue_minor' => (int) $invoices()->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])->whereDate('due_date', '<', $today)->sum('balance_minor'),
            'pending_adjustments' => $scope->apply(BillingAdjustment::query())->where('status', 'approved')->whereNull('invoice_id')->count(),
            'by_organisation' => $byOrganisation,
            'history' => $invoices()->selectRaw('period_start, count(*) as invoices, sum(total_minor) as total_minor, sum(amount_paid_minor) as paid_minor')
                ->groupBy('period_start')->orderByDesc('period_start')->limit(12)->get(),
        ];
    }
}
