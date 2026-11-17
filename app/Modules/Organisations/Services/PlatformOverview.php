<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\BillingRunner;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Models\ZohoMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the platform dashboard (Super Admins and Ops Admins).
 * Every figure is limited to what the user may see; a section the user
 * has no ability for is null and not shown.
 */
class PlatformOverview
{
    public function __construct(private readonly BillingRunner $billing) {}

    /**
     * @return array<string, mixed>
     */
    public function stats(User $user): array
    {
        $organisations = Organisation::query()->visibleTo($user);
        $canAccounts = $user->hasAbilityAnywhere(Ability::AccountsView);
        $canBilling = $user->hasAbilityAnywhere(Ability::BillingView);
        $accounts = fn () => GoogleAccount::query()->visibleTo($user, Ability::AccountsView)->where('account_status', '!=', AccountStatus::PermanentlyDeleted);
        $monthStart = CarbonImmutable::now(config('wcc.billing.timezone'))->startOfMonth()->toDateString();

        $billing = $canBilling ? $this->billing->overview($user) : null;
        $invoiceScope = $user->organisationScope(Ability::BillingView);

        return [
            'organisations' => (clone $organisations)->count(),
            'active_organisations' => (clone $organisations)->where('status', OrganisationStatus::Active)->count(),
            'suspended_organisations' => (clone $organisations)->where('status', OrganisationStatus::Suspended)->count(),
            'users' => $user->hasAbility(Ability::UsersManage) ? User::query()->count() : null,
            'accounts' => $canAccounts ? $accounts()->count() : null,
            'active_accounts' => $canAccounts ? $accounts()->where('account_status', AccountStatus::Active)->count() : null,
            'suspended_accounts' => $canAccounts ? $accounts()->where('account_status', AccountStatus::Suspended)->count() : null,
            'unassigned_accounts' => $user->hasGlobalAccess() && $canAccounts ? GoogleAccount::query()->whereNull('organisation_id')->count() : null,
            'domains' => $user->hasAbilityAnywhere(Ability::DomainsView) ? OrganisationDomain::query()->visibleTo($user, Ability::DomainsView)->where('status', 'active')->count() : null,
            'pending_domain_requests' => $user->hasAbilityAnywhere(Ability::DomainsView)
                ? DomainRequest::query()->visibleTo($user, Ability::DomainsView)->where('status', DomainRequestStatus::Pending)->count() : null,
            'mrr_minor' => $billing['mrr_minor'] ?? null,
            'outstanding_minor' => $billing['outstanding_minor'] ?? null,
            'month_invoiced_minor' => $canBilling ? (int) $invoiceScope->apply(Invoice::query())->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
                ->whereDate('issue_date', '>=', $monthStart)->sum('total_minor') : null,
            'month_invoices' => $canBilling ? $invoiceScope->apply(Invoice::query())->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
                ->whereDate('issue_date', '>=', $monthStart)->count() : null,
            'month_received_minor' => $canBilling ? (int) $user->organisationScope(Ability::BillingView)->apply(Payment::query())
                ->whereDate('paid_on', '>=', $monthStart)->sum('amount_minor') : null,
            'failed_google_jobs' => $user->hasAbility(Ability::QueuesMonitor) ? DB::table('failed_jobs')->where('queue', 'google')->count() : null,
            'failed_zoho_syncs' => $user->hasAbility(Ability::IntegrationsManageZoho)
                ? ZohoMapping::query()->whereIn('sync_status', [ZohoMapping::FAILED, ZohoMapping::MISMATCH, ZohoMapping::NEEDS_ATTENTION])->count() : null,
        ];
    }

    /**
     * Data for the dashboard charts, last 12 months.
     *
     * @return array<string, list<array{label: string, value: int}>|null>
     */
    public function charts(User $user): array
    {
        $months = collect(range(11, 0))->map(fn (int $back) => CarbonImmutable::now(config('wcc.billing.timezone'))->startOfMonth()->subMonths($back));
        $label = fn (CarbonImmutable $month) => $month->format('M Y');
        $from = $months->first()->toDateString();
        $canBilling = $user->hasAbilityAnywhere(Ability::BillingView);
        $canAccounts = $user->hasAbilityAnywhere(Ability::AccountsView);
        $billingScope = $user->organisationScope(Ability::BillingView);

        $revenue = null;
        $collections = null;

        if ($canBilling) {
            $byMonth = $billingScope->apply(Invoice::query())->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
                ->whereDate('period_start', '>=', $from)->get(['period_start', 'total_minor'])
                ->groupBy(fn (Invoice $invoice) => $invoice->period_start->format('M Y'))->map->sum('total_minor');
            $revenue = $months->map(fn ($month) => ['label' => $label($month), 'value' => (int) ($byMonth[$label($month)] ?? 0)])->all();

            $paid = $billingScope->apply(Payment::query())->whereDate('paid_on', '>=', $from)->get(['paid_on', 'amount_minor'])
                ->groupBy(fn (Payment $payment) => $payment->paid_on->format('M Y'))->map->sum('amount_minor');
            $collections = $months->map(fn ($month) => ['label' => $label($month), 'value' => (int) ($paid[$label($month)] ?? 0)])->all();
        }

        $growth = null;
        $byOrganisation = null;
        $lifecycle = null;

        if ($canAccounts) {
            $accounts = GoogleAccount::query()->visibleTo($user, Ability::AccountsView);
            $created = (clone $accounts)->where('account_status', '!=', AccountStatus::PermanentlyDeleted)->get(['created_at'])->pluck('created_at');
            $growth = $months->map(fn ($month) => [
                'label' => $label($month),
                'value' => $created->filter(fn ($at) => $at !== null && $at->lt($month->endOfMonth()))->count(),
            ])->all();

            $byOrganisation = (clone $accounts)->whereNotNull('organisation_id')->where('account_status', '!=', AccountStatus::PermanentlyDeleted)
                ->select('organisation_id', DB::raw('count(*) as total'))->groupBy('organisation_id')->orderByDesc('total')->limit(10)->get()
                ->map(fn ($row) => ['label' => Organisation::query()->whereKey($row->organisation_id)->value('name') ?? '—', 'value' => (int) $row->total])->all();

            $counts = (clone $accounts)->select('account_status', DB::raw('count(*) as total'))->groupBy('account_status')->pluck('total', 'account_status');
            $lifecycle = collect(AccountStatus::cases())->map(fn (AccountStatus $status) => ['label' => $status->label(), 'value' => (int) ($counts[$status->value] ?? 0)])
                ->filter(fn ($row) => $row['value'] > 0)->values()->all();
        }

        return compact('revenue', 'collections', 'growth', 'byOrganisation', 'lifecycle');
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function recentActivity(User $user, int $limit = 8): Collection
    {
        if ($user->hasAbility(Ability::AuditView)) {
            return AuditLog::query()->latest('id')->limit($limit)->get();
        }

        if ($user->hasAbility(Ability::AuditViewTechnical)) {
            return AuditLog::query()->where(fn ($q) => $q->where('action', 'like', 'account.%')->orWhere('action', 'like', 'domain.%')
                ->orWhere('action', 'like', 'integration.%')->orWhere('action', 'like', 'zoho.%'))->latest('id')->limit($limit)->get();
        }

        return collect();
    }

    /**
     * The organisations a user is assigned to, with their role labels.
     *
     * @return Collection<int, array{organisation: Organisation, roles: list<string>}>
     */
    public function memberships(User $user): Collection
    {
        return OrganisationUserRole::query()
            ->where('user_id', $user->getKey())
            ->with(['organisation:id,uuid,name,code,status', 'role:id,label'])
            ->get()
            ->filter(fn (OrganisationUserRole $assignment) => $assignment->organisation !== null)
            ->groupBy('organisation_id')
            ->map(fn (Collection $assignments) => [
                'organisation' => $assignments->first()->organisation,
                'roles' => $assignments->pluck('role.label')->unique()->values()->all(),
            ])
            ->sortBy(fn (array $membership) => $membership['organisation']->name)
            ->values();
    }
}
