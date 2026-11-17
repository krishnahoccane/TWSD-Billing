<?php

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\OrganisationPrice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\BillingRunner;
use App\Modules\Billing\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only billing API. Amounts are returned as decimal strings
 * ("1250.00") with a separate currency field — never as floats.
 */
class BillingApiController extends Controller
{
    public function summary(Request $request, BillingRunner $runner): JsonResponse
    {
        abort_unless($request->user()->hasAbilityAnywhere(Ability::BillingView), 403);

        $summary = $runner->overview($request->user());

        return response()->json(['data' => [
            'currency' => config('wcc.billing.currency'),
            'monthly_recurring_revenue' => Money::decimal($summary['mrr_minor']),
            'billable_accounts' => $summary['billable_accounts'],
            'suspended_billable_accounts' => $summary['suspended_billable'],
            'soft_deleted_accounts' => $summary['soft_deleted'],
            'invoiced' => Money::decimal($summary['invoiced_minor']),
            'payments_received' => Money::decimal($summary['received_minor']),
            'outstanding' => Money::decimal($summary['outstanding_minor']),
            'overdue' => Money::decimal($summary['overdue_minor']),
        ]]);
    }

    public function pricing(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::PricingView), 403);

        $today = CarbonImmutable::now(config('wcc.billing.timezone'))->toDateString();

        $rows = OrganisationPrice::query()->visibleTo($user, Ability::PricingView)
            ->with('organisation:id,uuid,name')
            ->where('status', 'active')->whereDate('effective_from', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->get();

        return response()->json(['data' => $rows->map(fn (OrganisationPrice $price) => [
            'organisation_id' => $price->organisation?->uuid,
            'organisation' => $price->organisation?->name,
            'service_type' => $price->service_type,
            'billing_model' => $price->billing_model,
            'monthly_price' => Money::decimal($price->monthly_price_minor),
            'currency' => $price->currency,
            'effective_from' => $price->effective_from->toDateString(),
        ])->values()]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::InvoicesView), 403);

        $manage = $user->organisationScope(Ability::InvoicesManage);

        $invoices = Invoice::query()->visibleTo($user, Ability::InvoicesView)
            ->with('organisation:id,uuid')
            ->where(fn (Builder $query) => $query->where('status', '!=', Invoice::DRAFT)
                ->orWhere(fn (Builder $draft) => $manage->apply($draft->where('status', Invoice::DRAFT))))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $invoices->getCollection()->map(fn (Invoice $invoice) => $this->invoiceResource($invoice)),
            'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'total' => $invoices->total()],
        ]);
    }

    public function invoice(Invoice $invoice): JsonResponse
    {
        Gate::authorize('invoice.view', $invoice);

        $invoice->load(['items', 'organisation:id,uuid']);

        return response()->json(['data' => $this->invoiceResource($invoice) + [
            'customer' => $invoice->organisation_snapshot,
            'items' => $invoice->items->map(fn ($item) => [
                'type' => $item->type,
                'description' => $item->description,
                'account_email' => $item->account_email,
                'period_start' => $item->period_start?->toDateString(),
                'period_end' => $item->period_end?->toDateString(),
                'days' => $item->days,
                'days_in_period' => $item->days_in_period,
                'unit_price' => $item->unit_price_minor === null ? null : Money::decimal($item->unit_price_minor),
                'price_source' => $item->price_source,
                'amount' => Money::decimal($item->amount_minor),
                'tax' => Money::decimal($item->tax_minor),
                'total' => Money::decimal($item->total_minor),
            ]),
        ]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::PaymentsView), 403);

        $payments = Payment::query()->visibleTo($user, Ability::PaymentsView)->with('organisation:id,uuid')
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $payments->getCollection()->map(fn (Payment $payment) => [
                'id' => $payment->uuid,
                'organisation_id' => $payment->organisation?->uuid,
                'amount' => Money::decimal($payment->amount_minor),
                'unallocated' => Money::decimal($payment->unallocated_minor),
                'currency' => $payment->currency,
                'paid_on' => $payment->paid_on->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'source' => $payment->source,
            ]),
            'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'total' => $payments->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceResource(Invoice $invoice): array
    {
        return [
            'id' => $invoice->uuid,
            'organisation_id' => $invoice->organisation?->uuid,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->displayStatus(),
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'subtotal' => Money::decimal($invoice->subtotal_minor),
            'adjustments' => Money::decimal($invoice->adjustments_minor),
            'tax' => Money::decimal($invoice->tax_minor),
            'tax_rate_percent' => Money::decimal($invoice->tax_rate_bp),
            'tax_treatment' => $invoice->tax_treatment,
            'total' => Money::decimal($invoice->total_minor),
            'amount_paid' => Money::decimal($invoice->amount_paid_minor),
            'credited' => Money::decimal($invoice->credited_minor),
            'balance' => Money::decimal($invoice->balance_minor),
        ];
    }
}
