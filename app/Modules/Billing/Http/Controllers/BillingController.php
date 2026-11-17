<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Jobs\GenerateBillingCycleJob;
use App\Modules\Billing\Models\AccountPriceOverride;
use App\Modules\Billing\Models\BillingAdjustment;
use App\Modules\Billing\Models\BillingCycle;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\OrganisationPrice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PricingPlan;
use App\Modules\Billing\Services\BillingRunner;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\Billing\Support\Money;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use App\Modules\Zoho\Jobs\PushPaymentToZohoJob;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class BillingController extends Controller
{
    /** Zoho Books payment_mode values for offline payments. */
    public const OFFLINE_METHODS = [
        'banktransfer' => 'Bank transfer (NEFT / RTGS / IMPS)',
        'upi' => 'UPI',
        'cheque' => 'Cheque',
        'cash' => 'Cash',
    ];

    // ---- Dashboards -------------------------------------------------------

    public function overview(Request $request, BillingRunner $runner): View
    {
        abort_unless($request->user()->hasAbilityAnywhere(Ability::BillingView), 403);

        return view('billing.overview', ['summary' => $runner->overview($request->user())]);
    }

    // ---- Pricing (pricing.manage) -----------------------------------------

    public function pricing(Request $request): View
    {
        Gate::authorize(Ability::PricingManage->value);

        $today = $this->today()->toDateString();
        $organisations = Organisation::query()->visibleTo($request->user(), Ability::PricingManage)->orderBy('name')->get();

        return view('billing.pricing', [
            'plans' => PricingPlan::query()->orderByDesc('effective_from')->limit(20)->get(),
            'rows' => $organisations->map(fn (Organisation $organisation) => [
                'organisation' => $organisation,
                'price' => OrganisationPrice::query()->where('organisation_id', $organisation->getKey())->where('status', 'active')
                    ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
                    ->orderByDesc('effective_from')->first(),
            ]),
            'today' => $today,
        ]);
    }

    public function setPlatformPrice(Request $request, PricingService $pricing): RedirectResponse
    {
        Gate::authorize(Ability::PricingManage->value);

        [$price, $from] = $this->priceInput($request, 'platform');
        $pricing->setPlatformPrice($price, $from, $request->user());

        return back()->with('success', 'Platform default price saved.');
    }

    public function organisationPricing(Request $request, Organisation $organisation, InvoiceCalculator $calculator): View
    {
        $this->authorizePricing($request, $organisation);

        return view('billing.organisation-pricing', [
            'organisation' => $organisation,
            'prices' => OrganisationPrice::query()->where('organisation_id', $organisation->getKey())->orderByDesc('effective_from')->get(),
            'overrides' => AccountPriceOverride::query()->where('organisation_id', $organisation->getKey())
                ->with('account:id,uuid,primary_email')->orderByDesc('effective_from')->get(),
            'adjustments' => BillingAdjustment::query()->where('organisation_id', $organisation->getKey())->latest('id')->limit(30)->get(),
            'taxRate' => Money::decimal($calculator->taxRate($organisation)),
            'canAdjust' => $request->user()->hasAbility(Ability::BillingManageAdjustments, $organisation),
            'today' => $this->today()->toDateString(),
        ]);
    }

    public function setOrganisationPrice(Request $request, Organisation $organisation, PricingService $pricing): RedirectResponse
    {
        $this->authorizePricing($request, $organisation);

        [$price, $from] = $this->priceInput($request, 'price');
        $pricing->setOrganisationPrice($organisation, $price, $from, $request->user());

        return back()->with('success', 'Organisation price saved.');
    }

    public function setOverride(Request $request, Organisation $organisation, PricingService $pricing): RedirectResponse
    {
        $this->authorizePricing($request, $organisation);

        $data = $request->validateWithBag('override', [
            'email' => ['required', 'email'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // The mailbox must belong to THIS organisation.
        $account = GoogleAccount::query()->where('organisation_id', $organisation->getKey())
            ->where('primary_email', strtolower($data['email']))->first()
            ?? throw ValidationException::withMessages(['email' => 'No mailbox with that address belongs to this organisation.'])->errorBag('override');

        [$price, $from] = $this->priceInput($request, 'override');
        $pricing->setAccountOverride($account, $price, $from, $data['reason'] ?? null, $request->user());

        return back()->with('success', "Price override saved for {$account->primary_email}.");
    }

    public function endOverride(Request $request, Organisation $organisation, string $override, PricingService $pricing): RedirectResponse
    {
        $this->authorizePricing($request, $organisation);

        $row = AccountPriceOverride::query()->where('organisation_id', $organisation->getKey())->where('uuid', $override)->firstOrFail();
        $data = $request->validate(['effective_from' => ['required', 'date']]);

        $pricing->endAccountOverride($row, $data['effective_from'], $request->user());

        return back()->with('success', 'Override ended.');
    }

    public function storeAdjustment(Request $request, Organisation $organisation, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->hasAbility(Ability::BillingManageAdjustments, $organisation), 403);

        $data = $request->validateWithBag('adjustment', [
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string'],
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'taxable' => ['nullable', 'boolean'],
        ]);

        $amount = $this->money($data['amount'], 'amount', 'adjustment');

        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.'])->errorBag('adjustment');
        }

        $adjustment = BillingAdjustment::query()->create([
            'organisation_id' => $organisation->getKey(),
            'type' => $data['type'],
            'amount_minor' => $amount,
            'currency' => config('wcc.billing.currency'),
            'description' => $data['description'],
            'taxable' => (bool) ($data['taxable'] ?? false),
            'status' => 'approved',
            'created_by' => $request->user()->getKey(),
            'approved_by' => $request->user()->getKey(),
            'approved_at' => now(),
        ]);

        $audit->record('billing.adjustment_created', $adjustment,
            new: ['type' => $data['type'], 'amount_minor' => $amount, 'description' => $data['description']],
            organisation: $organisation, actor: $request->user());

        return back()->with('success', 'Adjustment saved. It will be applied to the next invoice generated for this organisation.');
    }

    public function cancelAdjustment(Request $request, Organisation $organisation, string $adjustment, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->hasAbility(Ability::BillingManageAdjustments, $organisation), 403);

        $row = BillingAdjustment::query()->where('organisation_id', $organisation->getKey())->where('uuid', $adjustment)->firstOrFail();

        if ($row->status !== 'approved' || $row->invoice_id !== null) {
            return back()->with('error', 'This adjustment has already been applied to an invoice.');
        }

        $row->forceFill(['status' => 'cancelled'])->save();
        $audit->record('billing.adjustment_cancelled', $row, organisation: $organisation, actor: $request->user());

        return back()->with('success', 'Adjustment cancelled.');
    }

    // ---- Billing cycles (billing.cycles.run) ------------------------------

    public function cycles(): View
    {
        Gate::authorize(Ability::BillingRunCycles->value);

        [$start] = BillingRunner::previousMonth();

        return view('billing.cycles', [
            'cycles' => BillingCycle::query()->with(['organisation:id,uuid,name', 'invoice:id,uuid,billing_cycle_id,invoice_number,status,total_minor,currency'])
                ->orderByDesc('period_start')->orderByDesc('id')->paginate(30),
            'defaultMonth' => $start->format('Y-m'),
        ]);
    }

    public function runCycle(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::BillingRunCycles->value);

        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = CarbonImmutable::createFromFormat('!Y-m', $data['month'], config('wcc.billing.timezone'))->startOfMonth();

        // Billing is in arrears: only a month that has finished can be billed.
        if ($start->endOfMonth()->gte($this->today())) {
            throw ValidationException::withMessages(['month' => 'Only a completed month can be billed.']);
        }

        GenerateBillingCycleJob::dispatch($start->toDateString(), $start->endOfMonth()->toDateString(), $request->user()->getKey());
        $audit->record('billing.cycle_requested', new: ['month' => $data['month']], actor: $request->user());

        return back()->with('success', 'Billing run for '.$start->format('F Y').' queued. Draft invoices appear below when it finishes.');
    }

    // ---- Invoices ---------------------------------------------------------

    public function invoices(Request $request, OrganisationContext $context): View
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::InvoicesView), 403);

        $current = $context->current($user);
        $status = (string) $request->query('status', '');
        $manageScope = $user->organisationScope(Ability::InvoicesManage);

        $invoices = Invoice::query()
            ->visibleTo($user, Ability::InvoicesView)
            ->with('organisation:id,uuid,name')
            // Drafts are internal: only where the user may manage invoices.
            ->where(fn (Builder $query) => $query->where('status', '!=', Invoice::DRAFT)
                ->orWhere(fn (Builder $draft) => $manageScope->apply($draft->where('status', Invoice::DRAFT))))
            ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
            ->when($status === 'overdue', fn (Builder $query) => $query->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
                ->whereDate('due_date', '<', $this->today()->toDateString()))
            ->when(in_array($status, [Invoice::DRAFT, Invoice::ISSUED, Invoice::PARTIALLY_PAID, Invoice::PAID, Invoice::VOID], true),
                fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('billing.invoices', ['invoices' => $invoices, 'status' => $status, 'showOrganisation' => $current === null]);
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        Gate::authorize('invoice.view', $invoice);

        return view('billing.invoice', [
            'invoice' => $invoice->load(['items', 'organisation:id,uuid,name', 'allocations.payment', 'creditNotes']),
            'canManage' => $request->user()->can('invoice.manage', $invoice),
            'canRecordPayment' => (bool) config('wcc.billing.allow_manual_payments') && $invoice->isOpen()
                && $request->user()->hasAbility(Ability::PaymentsReconcile, $invoice->organisation),
            'zoho' => app(ZohoSync::class)->mapping($invoice),
            'zohoMode' => app(ZohoSync::class)->mode(),
            'offlineMethods' => self::OFFLINE_METHODS,
            'print' => $request->boolean('print'),
        ]);
    }

    public function recalculate(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        Gate::authorize('invoice.manage', $invoice);

        $invoices->recalculate($invoice, $request->user());

        return back()->with('success', 'Draft recalculated from current mailboxes and prices.');
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('invoice.manage', $invoice);

        $issued = $invoices->issue($invoice, $request->user());
        $payments->applyCredit($issued->organisation);

        return back()->with('success', "Invoice {$issued->invoice_number} issued.");
    }

    public function void(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        Gate::authorize('invoice.manage', $invoice);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $invoices->void($invoice, $data['reason'], $request->user());

        return back()->with('success', 'Invoice voided.');
    }

    public function creditNote(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        Gate::authorize('invoice.manage', $invoice);

        $data = $request->validateWithBag('credit', [
            'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $note = $invoices->creditNote($invoice, $this->money($data['amount'], 'amount', 'credit'), $data['reason'], $request->user());

        return back()->with('success', "Credit note {$note->credit_note_number} issued.");
    }

    // ---- Payments ---------------------------------------------------------

    public function payments(Request $request, OrganisationContext $context): View
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::PaymentsView), 403);

        $current = $context->current($user);
        $outstanding = ($request->route()->defaults['filter'] ?? 'all') === 'outstanding';

        return view('billing.payments', [
            'outstanding' => $outstanding,
            'showOrganisation' => $current === null,
            'payments' => $outstanding ? null : Payment::query()->visibleTo($user, Ability::PaymentsView)
                ->with(['organisation:id,uuid,name', 'allocations.invoice:id,uuid,invoice_number'])
                ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
                ->orderByDesc('paid_on')->orderByDesc('id')->paginate(25),
            'invoices' => ! $outstanding ? null : Invoice::query()->visibleTo($user, Ability::PaymentsView)
                ->with('organisation:id,uuid,name')
                ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])->where('balance_minor', '>', 0)
                ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
                ->orderBy('due_date')->paginate(25),
            'manualAllowed' => (bool) config('wcc.billing.allow_manual_payments') && $user->hasAbility(Ability::PaymentsReconcile),
        ]);
    }

    /** A Super Admin records an offline payment they have verified (bank transfer etc.). It is then pushed to Zoho Books. */
    public function recordPayment(Request $request, Invoice $invoice, PaymentService $payments): RedirectResponse
    {
        abort_unless($request->user()->hasAbility(Ability::PaymentsReconcile, $invoice->organisation), 403);
        abort_unless((bool) config('wcc.billing.allow_manual_payments'), 404);

        $data = $request->validateWithBag('payment', [
            'amount' => ['required', 'string'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'min:3', 'max:100'],
            'method' => ['required', Rule::in(array_keys(self::OFFLINE_METHODS))],
        ]);

        $amount = $this->money($data['amount'], 'amount', 'payment');

        if ($amount > $invoice->balance_minor) {
            throw ValidationException::withMessages(['amount' => 'The amount is more than the balance due of '.Money::format($invoice->balance_minor, $invoice->currency).'.'])->errorBag('payment');
        }

        $payment = $payments->recordVerified($invoice->organisation, $amount, $data['paid_on'], 'offline',
            details: ['reference' => $data['reference'], 'method' => $data['method']], invoice: $invoice, actor: $request->user());

        try {
            PushPaymentToZohoJob::dispatch($payment->getKey());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('warning', 'Payment recorded here. Sending it to Zoho Books failed and will be retried: '.$e->getMessage());
        }

        return back()->with('success', 'Offline payment recorded and sent to Zoho Books.');
    }

    // ---- Helpers ----------------------------------------------------------

    private function authorizePricing(Request $request, Organisation $organisation): void
    {
        abort_unless($request->user()->hasAbility(Ability::PricingManage, $organisation), 403);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function priceInput(Request $request, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'price' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
        ]);

        return [$this->money($data['price'], 'price', $bag), CarbonImmutable::parse($data['effective_from'])->toDateString()];
    }

    private function money(string $value, string $field, string $bag): int
    {
        try {
            return Money::parse($value);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()])->errorBag($bag);
        }
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay();
    }
}
