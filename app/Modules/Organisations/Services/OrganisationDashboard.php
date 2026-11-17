<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\PriceResolver;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleAccountHistory;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;

/**
 * One organisation's dashboard. Each section is filled only when the user
 * holds the matching ability IN this organisation, so a Billing Manager
 * gets the billing view and an Organisation Manager the full view.
 */
class OrganisationDashboard
{
    public function __construct(private readonly ZohoSync $zoho) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Organisation $organisation): array
    {
        $data = ['accounts' => null, 'domains' => null, 'billing' => null];

        if ($user->hasAbility(Ability::AccountsView, $organisation)) {
            $accounts = GoogleAccount::query()->where('organisation_id', $organisation->getKey())->where('account_status', '!=', AccountStatus::PermanentlyDeleted);
            $counts = (clone $accounts)->selectRaw('account_status, count(*) as total')->groupBy('account_status')->pluck('total', 'account_status');

            $data['accounts'] = [
                'total' => (int) $counts->sum(),
                'active' => (int) ($counts[AccountStatus::Active->value] ?? 0),
                'suspended' => (int) ($counts[AccountStatus::Suspended->value] ?? 0),
                'soft_deleted' => (int) ($counts[AccountStatus::SoftDeleted->value] ?? 0),
                'pending' => (int) collect(AccountStatus::cases())->filter->isPending()->sum(fn ($status) => $counts[$status->value] ?? 0),
                'recent' => GoogleAccountHistory::query()->where('organisation_id', $organisation->getKey())
                    ->with('user:id,name')->latest('id')->limit(8)->get()
                    ->map(fn ($entry) => ['entry' => $entry, 'account' => GoogleAccount::query()->find($entry->google_account_id, ['id', 'uuid', 'primary_email'])]),
            ];
        }

        if ($user->hasAbility(Ability::DomainsView, $organisation)) {
            $data['domains'] = [
                'active' => $organisation->domains()->where('status', 'active')->count(),
                'pending_requests' => DomainRequest::query()->where('organisation_id', $organisation->getKey())
                    ->whereIn('status', DomainRequestStatus::openValues())->latest('id')->limit(5)->get(),
            ];
        }

        if ($user->hasAbility(Ability::BillingView, $organisation)) {
            $today = CarbonImmutable::now(config('wcc.billing.timezone'));
            $prices = PriceResolver::for($organisation);
            $subscription = 0;

            foreach (GoogleAccount::query()->where('organisation_id', $organisation->getKey())->where('billing_status', BillingStatus::Billable)->pluck('id') as $id) {
                $subscription += $prices->resolve((int) $id, $today->toDateString())['price'] ?? 0;
            }

            $invoices = Invoice::query()->where('organisation_id', $organisation->getKey())->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID]);
            $latest = (clone $invoices)->orderByDesc('period_start')->first();

            $data['billing'] = [
                'period' => $today->startOfMonth()->format('d M').' – '.$today->endOfMonth()->format('d M Y'),
                'subscription_minor' => $subscription,
                'outstanding_minor' => (int) (clone $invoices)->sum('balance_minor'),
                'overdue_minor' => (int) (clone $invoices)->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
                    ->whereDate('due_date', '<', $today->toDateString())->sum('balance_minor'),
                'current' => $latest,
                'pay_url' => $latest && $latest->isOpen() && $latest->balance_minor > 0 ? $this->zoho->mapping($latest)?->zoho_url : null,
                'invoices' => (clone $invoices)->orderByDesc('period_start')->limit(6)->get(),
                'last_payment' => Payment::query()->where('organisation_id', $organisation->getKey())->latest('paid_on')->latest('id')->first(),
                'payments' => Payment::query()->where('organisation_id', $organisation->getKey())->latest('paid_on')->latest('id')->limit(5)->get(),
            ];
        }

        return $data;
    }
}
