<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Jobs\EnforcePaymentSuspensionsJob;
use App\Modules\Billing\Models\BillingAdjustment;
use App\Modules\Billing\Models\BillingCycle;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\PaymentSuspension;
use App\Modules\Billing\Support\Money;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Jobs\SyncInvoiceToZohoJob;
use App\Modules\Zoho\Jobs\VoidZohoInvoiceJob;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invoice lifecycle: draft → issued → partially paid / paid, or void.
 * Callers authorise first. Every write runs in a database transaction.
 */
class InvoiceService
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create the draft invoice for one organisation and period.
     *
     * Idempotent: the billing cycle row is unique per organisation and
     * period and is locked for the whole operation, so running this twice
     * (a retry, two workers, a double click) returns the same invoice.
     * Returns null when there is nothing to charge.
     */
    public function generate(Organisation $organisation, CarbonImmutable $start, CarbonImmutable $end, ?User $actor = null): ?Invoice
    {
        if ($end->lt($start)) {
            throw new BillingException('The billing period ends before it starts.');
        }

        $cycle = $this->cycle($organisation, $start, $end);

        try {
            return DB::transaction(function () use ($organisation, $cycle, $start, $end, $actor) {
                $cycle = BillingCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

                if ($existing = Invoice::query()->where('billing_cycle_id', $cycle->getKey())->first()) {
                    return $existing;
                }

                $result = $this->calculator->calculate($organisation, $start, $end);

                if ($result['lines'] === []) {
                    $cycle->forceFill(['status' => 'skipped', 'error' => null, 'generated_at' => now(), 'generated_by' => $actor?->getKey()])->save();

                    return null;
                }

                $invoice = Invoice::query()->create($result['totals'] + [
                    'organisation_id' => $organisation->getKey(),
                    'billing_cycle_id' => $cycle->getKey(),
                    'status' => Invoice::DRAFT,
                    'currency' => config('wcc.billing.currency'),
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'balance_minor' => $result['totals']['total_minor'],
                    'organisation_snapshot' => $this->snapshot($organisation),
                    'created_by' => $actor?->getKey(),
                ]);

                $this->writeLines($invoice, $result);

                $cycle->forceFill(['status' => 'invoiced', 'error' => null, 'generated_at' => now(), 'generated_by' => $actor?->getKey()])->save();

                $this->audit->record('invoice.generated', $invoice, new: [
                    'period' => $start->toDateString().' to '.$end->toDateString(),
                    'lines' => count($result['lines']), 'total_minor' => $invoice->total_minor,
                ], organisation: $organisation, actor: $actor);

                return $invoice;
            });
        } catch (BillingException $e) {
            // Recorded on the cycle so the operator sees why, then surfaced.
            $cycle->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'generated_at' => now()])->save();

            throw $e;
        }
    }

    /** Recalculate a DRAFT from current data (for example after fixing a price). Issued invoices cannot be recalculated. */
    public function recalculate(Invoice $invoice, User $actor): Invoice
    {
        $this->requireStatus($invoice, [Invoice::DRAFT], 'Only a draft invoice can be recalculated.');

        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $organisation = $invoice->organisation;

            BillingAdjustment::query()->where('invoice_id', $invoice->getKey())->update(['invoice_id' => null, 'status' => 'approved']);
            $invoice->items()->get()->each->delete();

            $result = $this->calculator->calculate($organisation, CarbonImmutable::parse($invoice->period_start), CarbonImmutable::parse($invoice->period_end));

            $invoice->forceFill($result['totals'] + [
                'balance_minor' => $result['totals']['total_minor'],
                'organisation_snapshot' => $this->snapshot($organisation),
            ])->save();

            $this->writeLines($invoice, $result);
            $this->audit->record('invoice.recalculated', $invoice, new: ['total_minor' => $invoice->total_minor], organisation: $organisation, actor: $actor);

            return $invoice;
        });
    }

    /** Assign the next number from the gap-free sequence and freeze the invoice. */
    public function issue(Invoice $invoice, ?User $actor = null): Invoice
    {
        $this->requireStatus($invoice, [Invoice::DRAFT], 'Only a draft invoice can be issued.');

        $issued = DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $this->requireStatus($invoice, [Invoice::DRAFT], 'Only a draft invoice can be issued.');

            $today = CarbonImmutable::now(config('wcc.billing.timezone'))->startOfDay();

            $invoice->forceFill([
                'invoice_number' => $this->nextInvoiceNumber($today),
                'status' => $invoice->total_minor === 0 ? Invoice::PAID : Invoice::ISSUED,
                'issue_date' => $today->toDateString(),
                'due_date' => $today->addDays((int) config('wcc.billing.default_due_days'))->toDateString(),
                'issued_at' => now(),
                'issued_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record('invoice.issued', $invoice,
                new: ['invoice_number' => $invoice->invoice_number, 'total_minor' => $invoice->total_minor, 'due_date' => $invoice->due_date->toDateString()],
                organisation: $invoice->organisation, actor: $actor);

            return $invoice;
        });

        // Zoho Books holds the accounting invoice: send it there. A Zoho
        // problem never undoes or blocks the issue — it is recorded on the
        // invoice and retried by the hourly synchronisation.
        $this->afterwards(fn () => SyncInvoiceToZohoJob::dispatch($issued->getKey()));
        app(NotificationEvents::class)->invoiceIssued($issued);

        return $issued;
    }

    /** An unpaid invoice can be voided (number kept). Paid amounts must be handled with a credit note instead. */
    public function void(Invoice $invoice, string $reason, User $actor): Invoice
    {
        $this->requireStatus($invoice, [Invoice::DRAFT, Invoice::ISSUED], 'Only a draft or unpaid issued invoice can be voided.');

        if ($invoice->amount_paid_minor > 0 || $invoice->credited_minor > 0) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has payments or credits. Issue a credit note instead.']);
        }

        $voided = DB::transaction(function () use ($invoice, $reason, $actor) {
            $previous = $invoice->status;

            $invoice->forceFill([
                'status' => Invoice::VOID, 'balance_minor' => 0, 'voided_at' => now(),
                'voided_by' => $actor->getKey(), 'void_reason' => $reason,
            ])->save();

            // Adjustments on a void invoice become available again.
            BillingAdjustment::query()->where('invoice_id', $invoice->getKey())->update(['invoice_id' => null, 'status' => 'approved']);

            $this->audit->record('invoice.voided', $invoice, previous: ['status' => $previous], new: ['status' => Invoice::VOID, 'reason' => $reason],
                organisation: $invoice->organisation, actor: $actor);

            return $invoice;
        });

        $this->afterwards(fn () => VoidZohoInvoiceJob::dispatch($voided->getKey()));

        return $voided;
    }

    /** Reduce what is owed on an issued invoice without altering the invoice itself. */
    public function creditNote(Invoice $invoice, int $amountMinor, string $reason, User $actor): CreditNote
    {
        $this->requireStatus($invoice, [Invoice::ISSUED, Invoice::PARTIALLY_PAID], 'A credit note can only be issued against an open invoice.');

        return DB::transaction(function () use ($invoice, $amountMinor, $reason, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($amountMinor <= 0 || $amountMinor > $invoice->balance_minor) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount up to the outstanding balance of '.Money::format($invoice->balance_minor, $invoice->currency).'.']);
            }

            $note = CreditNote::query()->create([
                'organisation_id' => $invoice->organisation_id,
                'invoice_id' => $invoice->getKey(),
                'credit_note_number' => $this->nextNumber('credit_note', (string) config('wcc.billing.credit_note_prefix', 'CN-')),
                'amount_minor' => $amountMinor,
                'currency' => $invoice->currency,
                'reason' => $reason,
                'issued_by' => $actor->getKey(),
                'issued_at' => now(),
            ]);

            $invoice->credited_minor += $amountMinor;
            $this->settle($invoice);

            $this->audit->record('invoice.credit_note_issued', $invoice,
                new: ['credit_note' => $note->credit_note_number, 'amount_minor' => $amountMinor, 'reason' => $reason],
                organisation: $invoice->organisation, actor: $actor);

            app(ZohoSync::class)->creditNoteIssued($note);

            return $note;
        });
    }

    /**
     * Zoho Books shows the invoice fully paid, but our total was a few paise
     * higher because the two systems rounded GST differently. Close the
     * remainder with a system credit note so both agree. Not pushed to Zoho
     * (it is already settled there) and not flagged for attention. Only
     * ZohoSync calls this, after checking the difference is within
     * BILLING_ROUNDING_TOLERANCE_PAISE.
     */
    public function writeOffRounding(Invoice $invoice, int $amountMinor, int $zohoTotalMinor): ?CreditNote
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $zohoTotalMinor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->isOpen() || $amountMinor <= 0 || $amountMinor > $invoice->balance_minor
                || $amountMinor > (int) config('wcc.billing.rounding_tolerance_minor', 100)) {
                return null;
            }

            $note = CreditNote::query()->create([
                'organisation_id' => $invoice->organisation_id,
                'invoice_id' => $invoice->getKey(),
                'credit_note_number' => $this->nextNumber('credit_note', (string) config('wcc.billing.credit_note_prefix', 'CN-')),
                'amount_minor' => $amountMinor,
                'currency' => $invoice->currency,
                'reason' => 'GST rounding difference: Zoho Books total '.Money::format($zohoTotalMinor, $invoice->currency).' is paid in full.',
                'issued_by' => null,
                'issued_at' => now(),
            ]);

            $invoice->credited_minor += $amountMinor;
            $this->settle($invoice);

            $this->audit->record('invoice.rounding_written_off', $invoice,
                new: ['credit_note' => $note->credit_note_number, 'amount_minor' => $amountMinor, 'zoho_total_minor' => $zohoTotalMinor],
                organisation: $invoice->organisation);

            return $note;
        });
    }

    /**
     * Run follow-up work (sending to Zoho) without letting its failure
     * escape. With a synchronous queue the job runs inline; its own failed()
     * handler has already recorded the error on the record.
     */
    private function afterwards(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Recompute balance and payment status from the amounts. Does not touch frozen fields. */
    public function settle(Invoice $invoice): void
    {
        $balance = $invoice->total_minor - $invoice->amount_paid_minor - $invoice->credited_minor;

        $invoice->forceFill([
            'balance_minor' => max(0, $balance),
            'status' => match (true) {
                $balance <= 0 => Invoice::PAID,
                $invoice->amount_paid_minor > 0 || $invoice->credited_minor > 0 => Invoice::PARTIALLY_PAID,
                default => Invoice::ISSUED,
            },
        ])->save();

        // Paid: reactivate mailboxes suspended for non-payment (after commit).
        if ($balance <= (int) config('wcc.billing.rounding_tolerance_minor', 100)
            && PaymentSuspension::query()->where('organisation_id', $invoice->organisation_id)->where('status', PaymentSuspension::ACTIVE)->exists()) {
            EnforcePaymentSuspensionsJob::dispatch((int) $invoice->organisation_id)->afterCommit();
        }
    }

    private function cycle(Organisation $organisation, CarbonImmutable $start, CarbonImmutable $end): BillingCycle
    {
        $find = fn () => BillingCycle::query()
            ->where('organisation_id', $organisation->getKey())
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return BillingCycle::query()->create([
                'organisation_id' => $organisation->getKey(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => 'open',
            ]);
        } catch (QueryException $e) {
            // Lost a race on the unique index: another worker created it.
            return $find() ?? throw $e;
        }
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, adjustments: list<BillingAdjustment>}  $result
     */
    private function writeLines(Invoice $invoice, array $result): void
    {
        foreach ($result['lines'] as $index => $line) {
            InvoiceItem::query()->create($line + ['invoice_id' => $invoice->getKey(), 'sort' => $index]);
        }

        foreach ($result['adjustments'] as $adjustment) {
            $adjustment->forceFill(['invoice_id' => $invoice->getKey(), 'status' => 'applied'])->save();
        }
    }

    /**
     * Who was billed, as it was on the day — later edits to the
     * organisation do not rewrite the invoice.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Organisation $organisation): array
    {
        return $organisation->only([
            'name', 'legal_name', 'code', 'gstin', 'pan', 'billing_email', 'billing_address',
            'registered_address', 'city', 'state', 'postal_code', 'country',
        ]);
    }

    /**
     * Invoice number from BILLING_INVOICE_NUMBER_FORMAT, default "{YY}/TWSD-{SEQ}"
     * (26/TWSD-001). {YY}/{YYYY} is the calendar year of the issue date; the
     * sequence restarts at 1 whenever the text before {SEQ} changes, i.e.
     * every new year. Numbers already issued are never renumbered.
     */
    private function nextInvoiceNumber(CarbonImmutable $issueDate): string
    {
        $format = (string) config('wcc.billing.invoice_number_format', '{YY}/TWSD-{SEQ}');
        $format = str_contains($format, '{SEQ}') ? $format : $format.'{SEQ}';
        $expand = fn (string $text) => str_replace(['{YYYY}', '{YY}'], [$issueDate->format('Y'), $issueDate->format('y')], $text);

        $prefix = $expand(Str::before($format, '{SEQ}'));
        $suffix = $expand(Str::after($format, '{SEQ}'));
        $padding = max(1, (int) config('wcc.billing.invoice_number_padding', 3));

        return $this->nextNumber(Str::limit('invoice:'.$prefix, 50, ''), $prefix, $padding).$suffix;
    }

    private function nextNumber(string $sequence, string $prefix, int $padding = 6): string
    {
        // Row lock: numbers are consecutive and never reused, even under concurrency.
        $row = DB::table('number_sequences')->where('name', $sequence)->lockForUpdate()->first();

        if ($row === null) {
            DB::table('number_sequences')->insert(['name' => $sequence, 'prefix' => Str::limit($prefix, 20, ''), 'padding' => $padding, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('number_sequences')->where('name', $sequence)->lockForUpdate()->first();
        }

        DB::table('number_sequences')->where('name', $sequence)->update(['next_number' => $row->next_number + 1, 'updated_at' => now()]);

        return $prefix.str_pad((string) $row->next_number, (int) $row->padding, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function requireStatus(Invoice $invoice, array $allowed, string $message): void
    {
        if (! in_array($invoice->status, $allowed, true)) {
            throw ValidationException::withMessages(['invoice' => $message]);
        }
    }
}
