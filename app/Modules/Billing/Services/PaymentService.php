<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentAllocation;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records payments that have ALREADY been verified by the authoritative
 * system and allocates them to invoices.
 *
 * This is the only code that can move an invoice to "partially paid" or
 * "paid". It is never called from a payment success page or redirect.
 * Sources: "zoho" (read from Zoho Books' API) and "offline" (a bank
 * transfer or similar that a Super Admin has verified; it is then pushed
 * to Zoho Books).
 */
class PaymentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Idempotent on $externalId: the same Zoho payment delivered twice
     * (webhook + reconciliation, or a retry) is recorded once.
     *
     * @param  array{method?: ?string, reference?: ?string, notes?: ?string}  $details
     */
    public function recordVerified(
        Organisation $organisation,
        int $amountMinor,
        string $paidOn,
        string $source,
        ?string $externalId = null,
        array $details = [],
        ?Invoice $invoice = null,
        ?User $actor = null,
    ): Payment {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The payment amount must be greater than zero.']);
        }

        if (in_array($source, ['manual', 'offline'], true) && ! config('wcc.billing.allow_manual_payments')) {
            throw ValidationException::withMessages(['payment' => 'Offline payments are disabled. Payments are recorded from Zoho Books.']);
        }

        if ($invoice !== null && $invoice->organisation_id !== $organisation->getKey()) {
            throw ValidationException::withMessages(['invoice' => 'That invoice belongs to a different organisation.']);
        }

        return DB::transaction(function () use ($organisation, $amountMinor, $paidOn, $source, $externalId, $details, $invoice, $actor) {
            if ($externalId !== null && ($existing = Payment::query()->where('external_id', $externalId)->lockForUpdate()->first())) {
                return $existing;
            }

            $payment = Payment::query()->create([
                'organisation_id' => $organisation->getKey(),
                'amount_minor' => $amountMinor,
                'unallocated_minor' => $amountMinor,
                'currency' => config('wcc.billing.currency'),
                'method' => $details['method'] ?? null,
                'reference' => $details['reference'] ?? null,
                'notes' => $details['notes'] ?? null,
                'paid_on' => $paidOn,
                'source' => $source,
                'external_id' => $externalId,
                'status' => 'verified',
                'recorded_by' => $actor?->getKey(),
            ]);

            $this->allocate($payment, $invoice);

            $this->audit->record('payment.recorded', $payment, new: [
                'amount_minor' => $amountMinor, 'source' => $source, 'reference' => $details['reference'] ?? null,
                'unallocated_minor' => $payment->unallocated_minor,
            ], organisation: $organisation, actor: $actor);

            app(NotificationEvents::class)->paymentReceived($payment);

            return $payment;
        });
    }

    /**
     * A payment read from Zoho Books, applied exactly as Zoho applied it.
     * Idempotent on $externalId. Any part that cannot be applied here (the
     * invoice is already settled locally, for example after a credit note)
     * stays unallocated and the invoice shows a mismatch with Zoho.
     *
     * @param  array{method?: ?string, reference?: ?string}  $details
     * @param  list<array{0: Invoice, 1: int}>  $allocations  invoice and amount in paise
     */
    public function recordAllocated(Organisation $organisation, string $paidOn, string $source, string $externalId, array $details, array $allocations): Payment
    {
        return DB::transaction(function () use ($organisation, $paidOn, $source, $externalId, $details, $allocations) {
            if ($existing = Payment::query()->where('external_id', $externalId)->lockForUpdate()->first()) {
                return $existing;
            }

            $total = array_sum(array_map(fn (array $row) => $row[1], $allocations));
            $tolerance = (int) config('wcc.billing.rounding_tolerance_minor', 100);
            $rounding = 0;

            $payment = Payment::query()->create([
                'organisation_id' => $organisation->getKey(),
                'amount_minor' => $total,
                'unallocated_minor' => $total,
                'currency' => config('wcc.billing.currency'),
                'method' => $details['method'] ?? null,
                'reference' => $details['reference'] ?? null,
                'paid_on' => $paidOn,
                'source' => $source,
                'external_id' => $externalId,
                'status' => 'verified',
            ]);

            foreach ($allocations as [$invoice, $amount]) {
                $invoice = Invoice::query()->whereKey($invoice->getKey())->where('organisation_id', $organisation->getKey())->lockForUpdate()->first();

                if ($invoice === null || ! in_array($invoice->status, [Invoice::ISSUED, Invoice::PARTIALLY_PAID], true)) {
                    continue;
                }

                $applied = min($amount, $invoice->balance_minor);

                if ($applied <= 0) {
                    continue;
                }

                PaymentAllocation::query()->create(['payment_id' => $payment->getKey(), 'invoice_id' => $invoice->getKey(), 'amount_minor' => $applied]);
                $invoice->amount_paid_minor += $applied;
                $this->invoices->settle($invoice);
                $payment->unallocated_minor -= $applied;

                // Zoho's total was a few paise higher (GST rounding) and Zoho
                // applied all of it to this invoice: the excess settled that
                // invoice in Zoho, so it is not left here as credit.
                $excess = $amount - $applied;

                if ($excess > 0 && $excess <= $tolerance && $invoice->status === Invoice::PAID) {
                    $payment->unallocated_minor -= $excess;
                    $rounding += $excess;
                }
            }

            $payment->save();

            $this->audit->record('payment.recorded', $payment, new: array_filter([
                'amount_minor' => $total, 'source' => $source, 'external_id' => $externalId,
                'unallocated_minor' => $payment->unallocated_minor,
                'rounding_minor' => $rounding ?: null,
            ], fn ($value) => $value !== null), organisation: $organisation);

            app(NotificationEvents::class)->paymentReceived($payment);

            return $payment;
        });
    }

    /**
     * Apply whatever is unallocated: to the named invoice first, then to
     * the organisation's open invoices, oldest due date first. Anything
     * left stays on the payment as credit for future invoices.
     */
    public function allocate(Payment $payment, ?Invoice $preferred = null): void
    {
        $open = Invoice::query()
            ->where('organisation_id', $payment->organisation_id)
            ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID])
            ->where('balance_minor', '>', 0)
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($preferred !== null) {
            $open = $open->sortBy(fn (Invoice $invoice) => $invoice->is($preferred) ? 0 : 1)->values();
        }

        foreach ($open as $invoice) {
            if ($payment->unallocated_minor <= 0) {
                break;
            }

            $amount = min($payment->unallocated_minor, $invoice->balance_minor);

            $allocation = PaymentAllocation::query()->firstOrNew(['payment_id' => $payment->getKey(), 'invoice_id' => $invoice->getKey()]);
            $allocation->amount_minor = (int) $allocation->amount_minor + $amount;
            $allocation->save();

            $invoice->amount_paid_minor += $amount;
            $this->invoices->settle($invoice);

            $payment->unallocated_minor -= $amount;
        }

        $payment->save();
    }

    /** After an invoice is issued, use any credit the organisation already holds. */
    public function applyCredit(Organisation $organisation): void
    {
        DB::transaction(function () use ($organisation) {
            Payment::query()->where('organisation_id', $organisation->getKey())->where('unallocated_minor', '>', 0)
                ->orderBy('paid_on')->orderBy('id')->lockForUpdate()->get()
                ->each(fn (Payment $payment) => $this->allocate($payment));
        });
    }
}
