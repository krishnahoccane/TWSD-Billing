<?php

namespace App\Modules\Zoho\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Support\Money;
use App\Modules\Notifications\NotificationEvents;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Exceptions\ZohoNotConnectedException;
use App\Modules\Zoho\Models\WebhookEvent;
use App\Modules\Zoho\Models\ZohoMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps Zoho Books in step with this application.
 *
 * Laravel calculates and issues the invoice; Zoho Books holds the
 * accounting invoice and the payments. Payments only ever flow INTO this
 * application after being read from Zoho's API — a webhook is a trigger,
 * never a source of payment data, and a "payment success" page is never
 * trusted.
 *
 * Duplicates are prevented by zoho_mappings (one per local record and one
 * per Zoho record), by looking up an existing Zoho record before creating
 * one, and by recording Zoho payments under their Zoho payment id.
 */
class ZohoSync
{
    private const MOCK_TAX_ID = 'MOCK-GST18';

    public function __construct(
        private readonly ZohoBooksClientInterface $client,
        private readonly ZohoConnection $connection,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function mode(): string
    {
        return $this->client->name() === 'mock' ? 'mock' : 'live';
    }

    public function mapping(Model $model): ?ZohoMapping
    {
        return ZohoMapping::query()->where('mappable_type', $model->getMorphClass())->where('mappable_id', $model->getKey())->first();
    }

    // ---- Customers --------------------------------------------------------

    /** Returns the Zoho contact id, creating or adopting the contact once. */
    public function syncCustomer(Organisation $organisation): string
    {
        $mapping = $this->mappingFor($organisation, 'contact');
        $payload = $this->contactPayload($organisation);

        if ($mapping->zoho_id !== null) {
            $this->client->updateContact($mapping->zoho_id, $payload);
            $this->markSynced($mapping);

            return $mapping->zoho_id;
        }

        $existing = $this->client->findContactByName($payload['contact_name']);
        $contactId = $existing['contact_id'] ?? $this->client->createContact($payload)['contact_id'];

        $mapping->forceFill(['zoho_id' => $contactId, 'zoho_number' => $payload['contact_name']]);
        $this->markSynced($mapping);

        $this->audit->record('zoho.customer_synced', $organisation,
            new: ['zoho_contact_id' => $contactId, 'adopted_existing' => $existing !== null], organisation: $organisation);

        return $contactId;
    }

    // ---- Invoices ---------------------------------------------------------

    /**
     * Send an issued invoice to Zoho Books with our invoice number, mark it
     * sent there and record its Zoho id, link, total and balance.
     */
    public function syncInvoice(Invoice $invoice): ZohoMapping
    {
        if (! in_array($invoice->status, [Invoice::ISSUED, Invoice::PARTIALLY_PAID, Invoice::PAID], true)) {
            throw new \LogicException('Only issued invoices are sent to Zoho Books.');
        }

        $mapping = $this->mappingFor($invoice, 'invoice');

        if ($mapping->zoho_id !== null) {
            return $this->refreshInvoice($invoice, $mapping);
        }

        $customerId = $this->syncCustomer($invoice->organisation);

        // Adopt an invoice an earlier attempt created before failing locally.
        $remote = $this->client->findInvoiceByNumber($invoice->invoice_number)
            ?? $this->client->createInvoice($this->invoicePayload($invoice, $customerId));

        if ($remote['status'] === 'draft') {
            $this->client->markInvoiceSent($remote['invoice_id']);
            $remote = $this->client->getInvoice($remote['invoice_id']);
        }

        $mapping->forceFill(['zoho_id' => $remote['invoice_id'], 'zoho_number' => $remote['invoice_number']]);
        $this->applyRemoteInvoice($invoice, $mapping, $remote);

        $this->audit->record('invoice.sent_to_zoho', $invoice, new: [
            'zoho_invoice_id' => $remote['invoice_id'], 'zoho_total' => $remote['total'], 'status' => $mapping->sync_status,
        ], organisation: $invoice->organisation);

        return $mapping;
    }

    /** Re-read the invoice from Zoho and compare it with ours. */
    public function refreshInvoice(Invoice $invoice, ?ZohoMapping $mapping = null): ZohoMapping
    {
        $mapping ??= $this->mapping($invoice);

        if ($mapping === null || $mapping->zoho_id === null) {
            return $this->syncInvoice($invoice);
        }

        $this->applyRemoteInvoice($invoice, $mapping, $this->client->getInvoice($mapping->zoho_id));

        return $mapping;
    }

    public function voidInvoice(Invoice $invoice): void
    {
        $mapping = $this->mapping($invoice);

        if ($mapping?->zoho_id === null) {
            return;
        }

        $this->client->voidInvoice($mapping->zoho_id);
        $this->applyRemoteInvoice($invoice, $mapping, $this->client->getInvoice($mapping->zoho_id));
        $this->audit->record('invoice.voided_in_zoho', $invoice, new: ['zoho_invoice_id' => $mapping->zoho_id], organisation: $invoice->organisation);
    }

    /**
     * Credit notes are not pushed to Zoho yet: flag the invoice so the
     * matching credit note is created in Zoho Books by hand.
     */
    public function creditNoteIssued(CreditNote $note): void
    {
        $invoice = $note->invoice ?? Invoice::query()->find($note->invoice_id);
        $mapping = $invoice ? $this->mapping($invoice) : null;

        $mapping?->forceFill([
            'sync_status' => ZohoMapping::NEEDS_ATTENTION,
            'last_error' => "Credit note {$note->credit_note_number} for ".Money::format($note->amount_minor, $note->currency)
                .' was issued here. Create the matching credit note in Zoho Books.',
        ])->save();
    }

    // ---- Payments ---------------------------------------------------------

    /**
     * Push an offline payment a Super Admin verified (bank transfer etc.)
     * to Zoho, against the invoices it was allocated to. Returns false when
     * none of those invoices is in Zoho yet (it is retried later).
     */
    public function pushPayment(Payment $payment): bool
    {
        if ($payment->external_id !== null) {
            return true;
        }

        $applied = [];

        foreach ($payment->allocations()->with('invoice')->get() as $allocation) {
            $zohoId = $this->mapping($allocation->invoice)?->zoho_id;

            if ($zohoId !== null) {
                $applied[] = ['invoice_id' => $zohoId, 'amount_applied' => Money::decimal($allocation->amount_minor)];
            }
        }

        if ($applied === []) {
            return false;
        }

        $amount = array_sum(array_map(fn (array $row) => Money::parse($row['amount_applied']), $applied));
        $deposit = $this->connection->settings()['deposit_account_id'] ?? null;

        $remote = $this->client->createCustomerPayment(array_filter([
            'customer_id' => $this->syncCustomer($payment->organisation),
            'payment_mode' => $payment->method ?: 'banktransfer',
            'amount' => Money::decimal($amount),
            'date' => $payment->paid_on->toDateString(),
            // Our marker lets reconciliation recognise this payment.
            'reference_number' => Str::limit($this->marker($payment).' '.$payment->reference, 50, ''),
            'invoices' => $applied,
            'account_id' => $deposit,
        ], fn ($value) => $value !== null));

        DB::transaction(function () use ($payment, $remote) {
            $payment->forceFill(['external_id' => 'zoho:'.$remote['payment_id']])->save();
            $mapping = $this->mappingFor($payment, 'payment');
            $mapping->forceFill(['zoho_id' => $remote['payment_id']]);
            $this->markSynced($mapping);
        });

        $this->audit->record('payment.sent_to_zoho', $payment, new: ['zoho_payment_id' => $remote['payment_id']], organisation: $payment->organisation);

        foreach ($payment->allocations()->with('invoice')->get() as $allocation) {
            if ($mapping = $this->mapping($allocation->invoice)) {
                $this->refreshInvoice($allocation->invoice, $mapping);
            }
        }

        return true;
    }

    /**
     * Read the organisation's payments from Zoho and record any that are
     * not here yet, then compare every open invoice with Zoho.
     *
     * @return array{recorded: int, mismatches: int}
     */
    public function reconcileOrganisation(Organisation $organisation): array
    {
        $contactId = $this->mapping($organisation)?->zoho_id;
        $summary = ['recorded' => 0, 'mismatches' => 0];

        if ($contactId === null) {
            return $summary;
        }

        $page = 1;

        do {
            $list = $this->client->listCustomerPayments($contactId, $page);

            foreach ($list['payments'] as $row) {
                if ($this->recordZohoPayment($organisation, $row['payment_id'], $row['reference_number'] ?? null)) {
                    $summary['recorded']++;
                }
            }

            $page++;
        } while ($list['has_more'] && $page <= 50);

        Invoice::query()->where('organisation_id', $organisation->getKey())
            ->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID, Invoice::PAID])
            ->whereIn('id', ZohoMapping::query()->where('mappable_type', (new Invoice)->getMorphClass())->whereNotNull('zoho_id')->select('mappable_id'))
            ->orderBy('id')->get()
            ->each(function (Invoice $invoice) use (&$summary) {
                if ($this->refreshInvoice($invoice)->sync_status === ZohoMapping::MISMATCH) {
                    $summary['mismatches']++;
                }
            });

        return $summary;
    }

    /**
     * Everything at once (scheduled): send issued invoices that are not in
     * Zoho yet, push offline payments, then reconcile every organisation.
     *
     * @return array{invoices_sent: int, payments_pushed: int, payments_recorded: int, mismatches: int, failures: int}
     */
    public function reconcileAll(): array
    {
        $summary = ['invoices_sent' => 0, 'payments_pushed' => 0, 'payments_recorded' => 0, 'mismatches' => 0, 'failures' => 0];
        $invoiceType = (new Invoice)->getMorphClass();

        Invoice::query()->whereIn('status', [Invoice::ISSUED, Invoice::PARTIALLY_PAID, Invoice::PAID])
            ->whereNotIn('id', ZohoMapping::query()->where('mappable_type', $invoiceType)->whereNotNull('zoho_id')->select('mappable_id'))
            ->orderBy('id')->get()
            ->each(function (Invoice $invoice) use (&$summary) {
                try {
                    $this->syncInvoice($invoice);
                    $summary['invoices_sent']++;
                } catch (ZohoNotConnectedException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $this->markFailed($invoice, 'invoice', $e);
                    $summary['failures']++;
                }
            });

        Payment::query()->whereNull('external_id')->whereIn('source', ['offline', 'manual'])->orderBy('id')->get()
            ->each(function (Payment $payment) use (&$summary) {
                try {
                    if ($this->pushPayment($payment)) {
                        $summary['payments_pushed']++;
                    }
                } catch (Throwable $e) {
                    $this->markFailed($payment, 'payment', $e);
                    $summary['failures']++;
                }
            });

        $organisationIds = ZohoMapping::query()->where('mappable_type', (new Organisation)->getMorphClass())->whereNotNull('zoho_id')->pluck('mappable_id');

        foreach (Organisation::query()->whereIn('id', $organisationIds)->orderBy('id')->get() as $organisation) {
            try {
                $result = $this->reconcileOrganisation($organisation);
                $summary['payments_recorded'] += $result['recorded'];
                $summary['mismatches'] += $result['mismatches'];
            } catch (Throwable $e) {
                report($e);
                $summary['failures']++;
            }
        }

        return $summary;
    }

    /**
     * A webhook only tells us "look at payment X". The payment itself is
     * read from Zoho's API before anything is recorded.
     */
    public function processWebhook(WebhookEvent $event): string
    {
        $payload = $event->payload ?? [];
        $paymentId = $payload['payment']['payment_id'] ?? $payload['customerpayment']['payment_id'] ?? $payload['payment_id'] ?? null;

        if ($paymentId === null) {
            return 'ignored: no payment id in the payload';
        }

        $payment = $this->client->getCustomerPayment((string) $paymentId);
        $organisationId = ZohoMapping::query()
            ->where('mappable_type', (new Organisation)->getMorphClass())
            ->where('zoho_id', $payment['customer_id'])
            ->value('mappable_id');

        if ($organisationId === null) {
            return 'ignored: the Zoho customer is not linked to an organisation';
        }

        $organisation = Organisation::query()->findOrFail($organisationId);

        return $this->recordZohoPayment($organisation, $payment['payment_id'], $payment['reference_number'], $payment)
            ? 'processed: payment recorded'
            : 'processed: payment already recorded';
    }

    public function markFailed(Model $model, string $entity, Throwable $error): void
    {
        app(NotificationEvents::class)->zohoError($entity.' sync', $error->getMessage());

        $mapping = $this->mappingFor($model, $entity);
        $mapping->forceFill([
            'sync_status' => ZohoMapping::FAILED,
            'last_error' => Str::limit(class_basename($error).': '.$error->getMessage(), 1000),
            'attempts' => $mapping->attempts + 1,
        ])->save();
    }

    // ---- Internals --------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $detail  already-fetched payment, if any
     */
    private function recordZohoPayment(Organisation $organisation, string $zohoPaymentId, ?string $reference, ?array $detail = null): bool
    {
        if (Payment::query()->where('external_id', 'zoho:'.$zohoPaymentId)->exists()) {
            return false;
        }

        // An offline payment we pushed ourselves: link it, do not record it twice.
        if ($reference !== null && preg_match('/WCC-PAY-([0-9a-f\-]{36})/i', $reference, $match)) {
            $local = Payment::query()->where('uuid', strtolower($match[1]))->first();

            if ($local !== null) {
                if ($local->external_id === null) {
                    $local->forceFill(['external_id' => 'zoho:'.$zohoPaymentId])->save();
                }

                return false;
            }
        }

        $detail ??= $this->client->getCustomerPayment($zohoPaymentId);
        $allocations = [];

        foreach ($detail['invoices'] as $row) {
            $invoiceId = ZohoMapping::query()
                ->where('mappable_type', (new Invoice)->getMorphClass())
                ->where('zoho_id', $row['invoice_id'])
                ->value('mappable_id');

            $invoice = $invoiceId ? Invoice::query()->where('organisation_id', $organisation->getKey())->find($invoiceId) : null;

            if ($invoice !== null && Money::parse($row['amount_applied']) > 0) {
                $allocations[] = [$invoice, Money::parse($row['amount_applied'])];
            }
        }

        if ($allocations === []) {
            // Paid against something we do not hold (for example an invoice made directly in Zoho).
            return false;
        }

        $this->payments->recordAllocated($organisation, $detail['date'], 'zoho', 'zoho:'.$zohoPaymentId, [
            'method' => $detail['payment_mode'] ?? null,
            'reference' => $detail['reference_number'] ?? null,
        ], $allocations);

        return true;
    }

    /**
     * @param  array{invoice_id: string, status: string, total: string, balance: string, invoice_url: ?string}  $remote
     */
    private function applyRemoteInvoice(Invoice $invoice, ZohoMapping $mapping, array $remote): void
    {
        $invoice->refresh();
        $total = Money::parse($remote['total']);
        $balance = Money::parse($remote['balance']);
        $problems = [];

        if ($total !== $invoice->total_minor) {
            $problems[] = 'Zoho total '.Money::format($total).' differs from '.Money::format($invoice->total_minor).' here (check the GST tax and rounding settings in Zoho Books).';
        }

        $expectedBalance = $invoice->status === Invoice::VOID ? 0 : $invoice->balance_minor;

        if ($remote['status'] !== 'void' && $balance !== $expectedBalance) {
            $problems[] = 'Zoho balance '.Money::format($balance).' differs from '.Money::format($expectedBalance).' here.';
        }

        if (($remote['status'] === 'void') !== ($invoice->status === Invoice::VOID)) {
            $problems[] = 'The invoice is '.($remote['status'] === 'void' ? '' : 'not ').'void in Zoho Books.';
        }

        $keepAttention = $mapping->sync_status === ZohoMapping::NEEDS_ATTENTION && $problems !== [];

        if ($problems !== [] && ! $keepAttention && $mapping->sync_status !== ZohoMapping::MISMATCH) {
            app(NotificationEvents::class)->zohoMismatch($invoice, implode(' ', $problems));
        }

        $mapping->forceFill([
            'zoho_status' => $remote['status'],
            'zoho_url' => $remote['invoice_url'] ?? $mapping->zoho_url,
            'zoho_total_minor' => $total,
            'zoho_balance_minor' => $balance,
            'zoho_organisation_id' => $this->connection->organisationId() ?: $mapping->zoho_organisation_id,
            'sync_status' => $keepAttention ? ZohoMapping::NEEDS_ATTENTION : ($problems === [] ? ZohoMapping::SYNCED : ZohoMapping::MISMATCH),
            'last_error' => $keepAttention ? $mapping->last_error : ($problems === [] ? null : implode(' ', $problems)),
            'last_synced_at' => now(),
            'attempts' => $mapping->attempts + 1,
        ])->save();
    }

    private function mappingFor(Model $model, string $entity): ZohoMapping
    {
        return ZohoMapping::query()->firstOrCreate(
            ['mappable_type' => $model->getMorphClass(), 'mappable_id' => $model->getKey()],
            ['zoho_entity' => $entity, 'sync_status' => ZohoMapping::PENDING],
        );
    }

    private function markSynced(ZohoMapping $mapping): void
    {
        $mapping->forceFill([
            'zoho_organisation_id' => $this->connection->organisationId() ?: $mapping->zoho_organisation_id,
            'sync_status' => ZohoMapping::SYNCED,
            'last_error' => null,
            'last_synced_at' => now(),
            'attempts' => $mapping->attempts + 1,
        ])->save();
    }

    private function marker(Payment $payment): string
    {
        return 'WCC-PAY-'.$payment->uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(Organisation $organisation): array
    {
        $email = $organisation->billing_email ?: $organisation->primary_contact_email;

        return array_filter([
            // Name + code: unique, so an existing contact can be found again.
            'contact_name' => Str::limit($organisation->name.' ('.$organisation->code.')', 200, ''),
            'company_name' => $organisation->legal_name ?: $organisation->name,
            'contact_type' => 'customer',
            'gst_no' => $organisation->gstin ?: null,
            'gst_treatment' => $organisation->gstin ? 'business_gst' : 'business_none',
            'billing_address' => array_filter([
                'address' => $organisation->billing_address ?: $organisation->registered_address,
                'city' => $organisation->city,
                'state' => $organisation->state,
                'zip' => $organisation->postal_code,
                'country' => $organisation->country === 'IN' ? 'India' : $organisation->country,
            ]),
            'contact_persons' => $email ? [[
                'first_name' => $organisation->primary_contact_name ?: $organisation->name,
                'email' => $email,
                'is_primary_contact' => true,
            ]] : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Invoice $invoice, string $customerId): array
    {
        $settings = $this->connection->settings();
        $taxId = $settings['tax_id'] ?? null;
        $itemId = $settings['item_id'] ?? null;

        if ($taxId === null) {
            if ($this->mode() === 'live') {
                throw new ZohoNotConnectedException('The Zoho Books GST 18% tax id is not set. Save it under Integrations → Zoho Books.');
            }

            $taxId = self::MOCK_TAX_ID;
        }

        $lines = [];

        foreach ($invoice->items()->get() as $index => $item) {
            $lines[] = array_filter([
                'item_id' => $itemId,
                'name' => $item->type === 'mailbox' ? 'Google Workspace mailbox' : Str::limit($item->description, 100, ''),
                'description' => $item->type === 'mailbox'
                    ? $item->account_email.' · '.$item->period_start?->format('d M Y').' – '.$item->period_end?->format('d M Y')
                        .' ('.$item->days.'/'.$item->days_in_period.' days at '.Money::format((int) $item->unit_price_minor).' per month)'
                    : $item->description,
                'rate' => Money::decimal($item->amount_minor),
                'quantity' => 1,
                'tax_id' => $item->tax_minor !== 0 ? $taxId : null,
                'item_order' => $index + 1,
            ], fn ($value) => $value !== null);
        }

        return [
            'customer_id' => $customerId,
            'invoice_number' => $invoice->invoice_number,
            'reference_number' => $invoice->uuid,
            'date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'is_inclusive_tax' => false,
            'line_items' => $lines,
            'notes' => 'Google Workspace mailboxes for '.$invoice->period_start->format('d M Y').' – '.$invoice->period_end->format('d M Y').'.',
        ];
    }
}
