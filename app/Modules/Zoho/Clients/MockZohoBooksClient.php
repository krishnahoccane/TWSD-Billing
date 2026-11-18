<?php

namespace App\Modules\Zoho\Clients;

use App\Modules\Billing\Support\Money;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Exceptions\ZohoException;
use Throwable;

/**
 * A stand-in Zoho Books organisation for local development and tests.
 * It makes NO network calls; nothing it does reaches Zoho. Invoice links
 * point at a "mock" page, not a real payment page.
 *
 * Totals are computed like Zoho's line-level tax: per line,
 * round(rate × quantity × tax%) — so a correctly built invoice matches.
 */
class MockZohoBooksClient implements ZohoBooksClientInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $contacts = [];

    /** @var array<string, array<string, mixed>> */
    private array $invoices = [];

    /** @var array<string, array<string, mixed>> */
    private array $payments = [];

    private int $sequence = 0;

    /** @var array<string, list<Throwable>> */
    private array $failures = [];

    /** @var list<array{operation: string, target: ?string}> */
    public array $calls = [];

    public function __construct(private readonly ?string $statePath = null)
    {
        $this->load();
    }

    public function name(): string
    {
        return 'mock';
    }

    // ---- Helpers for tests and local demos --------------------------------

    public function failNext(string $operation, Throwable $exception): void
    {
        $this->failures[$operation][] = $exception;
    }

    public function callCount(string $operation): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call['operation'] === $operation));
    }

    /**
     * A customer pays in Zoho (online or recorded by your accountant).
     *
     * @return string the Zoho payment id
     */
    public function simulatePayment(string $invoiceId, string $amount, string $date, string $mode = 'online', ?string $reference = null): string
    {
        $invoice = $this->invoices[$invoiceId] ?? throw new ZohoException("Invoice {$invoiceId} not found");

        return $this->createCustomerPayment([
            'customer_id' => $invoice['customer_id'],
            'payment_mode' => $mode,
            'amount' => $amount,
            'date' => $date,
            'reference_number' => $reference,
            'invoices' => [['invoice_id' => $invoiceId, 'amount_applied' => $amount]],
        ])['payment_id'];
    }

    /** @return array<string, mixed>|null */
    public function rawInvoice(string $invoiceId): ?array
    {
        return $this->invoices[$invoiceId] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function rawContact(string $contactId): ?array
    {
        return $this->contacts[$contactId] ?? null;
    }

    public function reset(): void
    {
        $this->contacts = $this->invoices = $this->payments = $this->failures = $this->calls = [];
        $this->sequence = 0;
        $this->save();
    }

    // ---- ZohoBooksClientInterface -----------------------------------------

    public function organizations(): array
    {
        $this->called('organizations');

        return [['organization_id' => (string) (config('wcc.zoho.organisation_id') ?: 'MOCK-ORG'), 'name' => 'Mock Zoho Books organisation']];
    }

    /**
     * A customer that already exists in (mock) Zoho Books, created there by hand.
     *
     * @param  array<string, mixed>  $contact  Zoho contact fields
     */
    public function seedContact(array $contact): string
    {
        $id = $this->id('C');
        $this->contacts[$id] = $contact + ['contact_type' => 'customer'];
        $this->save();

        return $id;
    }

    public function listContacts(int $page = 1, ?string $search = null): array
    {
        $this->called('contacts.list', 'page '.$page);

        $rows = collect($this->contacts)
            ->filter(fn (array $contact) => ($contact['contact_type'] ?? 'customer') === 'customer')
            ->filter(fn (array $contact) => blank($search) || str_contains(mb_strtolower($contact['contact_name'] ?? ''), mb_strtolower((string) $search)))
            ->sortBy(fn (array $contact) => mb_strtolower($contact['contact_name'] ?? ''))
            ->map(fn (array $contact, string $id) => [
                'contact_id' => $id,
                'contact_name' => (string) ($contact['contact_name'] ?? ''),
                'company_name' => (string) ($contact['company_name'] ?? ''),
                'email' => $contact['email'] ?? ($contact['contact_persons'][0]['email'] ?? null),
                'phone' => $contact['phone'] ?? null,
                'status' => 'active',
                'outstanding' => '0.00',
            ])->values();

        return ['contacts' => $rows->slice(($page - 1) * 50, 50)->values()->all(), 'has_more' => $rows->count() > $page * 50];
    }

    public function getContact(string $contactId): array
    {
        $this->called('contacts.get', $contactId);

        $contact = $this->contacts[$contactId] ?? throw new ZohoException("Zoho Books error 1002: contact {$contactId} does not exist.");

        return ZohoBooksClient::normaliseContact(['contact_id' => $contactId] + $contact);
    }

    public function findContactByName(string $contactName): ?array
    {
        $this->called('contacts.find', $contactName);

        foreach ($this->contacts as $id => $contact) {
            if ($contact['contact_name'] === $contactName) {
                return ['contact_id' => (string) $id, 'contact_name' => $contactName];
            }
        }

        return null;
    }

    public function createContact(array $contact): array
    {
        $this->called('contacts.create', $contact['contact_name'] ?? null);

        $id = $this->id('C');
        $this->contacts[$id] = $contact;
        $this->save();

        return ['contact_id' => $id];
    }

    public function updateContact(string $contactId, array $contact): void
    {
        $this->called('contacts.update', $contactId);

        if (! isset($this->contacts[$contactId])) {
            throw new ZohoException("Zoho Books error 1002: contact {$contactId} does not exist.");
        }

        $this->contacts[$contactId] = array_merge($this->contacts[$contactId], $contact);
        $this->save();
    }

    public function findInvoiceByNumber(string $invoiceNumber): ?array
    {
        $this->called('invoices.find', $invoiceNumber);

        foreach ($this->invoices as $id => $invoice) {
            if ($invoice['invoice_number'] === $invoiceNumber) {
                return $this->public($id);
            }
        }

        return null;
    }

    public function createInvoice(array $invoice): array
    {
        $this->called('invoices.create', $invoice['invoice_number'] ?? null);

        if (! isset($this->contacts[$invoice['customer_id'] ?? ''])) {
            throw new ZohoException('Zoho Books error 1002: customer does not exist.');
        }

        foreach ($this->invoices as $existing) {
            if ($existing['invoice_number'] === ($invoice['invoice_number'] ?? null)) {
                throw new ZohoException('Zoho Books error 1001: invoice number already exists.');
            }
        }

        $total = 0;

        foreach ($invoice['line_items'] ?? [] as $line) {
            $rate = (string) $line['rate'];
            $amount = Money::parse(ltrim($rate, '-')) * (str_starts_with($rate, '-') ? -1 : 1) * (int) ($line['quantity'] ?? 1);
            // Any tax id is treated as the GST rate configured here.
            $percent = isset($line['tax_id']) ? Money::rateToBasisPoints((string) config('wcc.billing.default_tax_rate')) : 0;
            $total += $amount + Money::divideRound($amount * $percent, 10000);
        }

        $id = $this->id('I');
        $this->invoices[$id] = $invoice + ['status' => 'draft', 'total_minor' => $total, 'paid_minor' => 0];
        $this->save();

        return $this->public($id);
    }

    public function getInvoice(string $invoiceId): array
    {
        $this->called('invoices.get', $invoiceId);
        $this->requireInvoice($invoiceId);

        return $this->public($invoiceId);
    }

    public function markInvoiceSent(string $invoiceId): void
    {
        $this->called('invoices.sent', $invoiceId);
        $this->requireInvoice($invoiceId);

        if ($this->invoices[$invoiceId]['status'] === 'draft') {
            $this->invoices[$invoiceId]['status'] = 'sent';
            $this->save();
        }
    }

    public function voidInvoice(string $invoiceId): void
    {
        $this->called('invoices.void', $invoiceId);
        $this->requireInvoice($invoiceId);

        $this->invoices[$invoiceId]['status'] = 'void';
        $this->save();
    }

    public function invoicePdf(string $invoiceId): string
    {
        $this->called('invoices.pdf', $invoiceId);
        $this->requireInvoice($invoiceId);

        $number = $this->invoices[$invoiceId]['invoice_number'];
        $text = "MOCK Zoho Books invoice {$number} - not a real document";

        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            .'4 0 obj<</Length '.(strlen($text) + 30).">>stream\nBT /F1 14 Tf 50 780 Td ({$text}) Tj ET\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public function listCustomerPayments(string $customerId, int $page = 1): array
    {
        $this->called('payments.list', $customerId);

        $rows = array_values(array_filter($this->payments, fn (array $payment) => $payment['customer_id'] === $customerId));
        $slice = array_slice($rows, ($page - 1) * 200, 200);

        return [
            'payments' => array_map(fn (array $payment) => array_intersect_key($payment, array_flip(['payment_id', 'date', 'amount', 'payment_mode', 'reference_number'])), $slice),
            'has_more' => count($rows) > $page * 200,
        ];
    }

    public function getCustomerPayment(string $paymentId): array
    {
        $this->called('payments.get', $paymentId);

        return $this->payments[$paymentId] ?? throw new ZohoException("Zoho Books error 1002: payment {$paymentId} does not exist.");
    }

    public function createCustomerPayment(array $payment): array
    {
        $this->called('payments.create', $payment['reference_number'] ?? null);

        $id = $this->id('P');
        $invoices = [];

        foreach ($payment['invoices'] ?? [] as $row) {
            $this->requireInvoice((string) $row['invoice_id']);
            $applied = Money::parse((string) $row['amount_applied']);
            $invoice = &$this->invoices[$row['invoice_id']];
            $invoice['paid_minor'] += $applied;
            $invoice['status'] = $invoice['paid_minor'] >= $invoice['total_minor'] ? 'paid' : 'partially_paid';
            unset($invoice);
            $invoices[] = ['invoice_id' => (string) $row['invoice_id'], 'amount_applied' => Money::decimal($applied)];
        }

        $this->payments[$id] = [
            'payment_id' => $id,
            'customer_id' => (string) $payment['customer_id'],
            'date' => (string) $payment['date'],
            'amount' => Money::decimal(Money::parse((string) $payment['amount'])),
            'payment_mode' => $payment['payment_mode'] ?? null,
            'reference_number' => $payment['reference_number'] ?? null,
            'invoices' => $invoices,
        ];
        $this->save();

        return ['payment_id' => $id];
    }

    // ---- Internals --------------------------------------------------------

    /** @return array{invoice_id: string, invoice_number: string, status: string, total: string, balance: string, invoice_url: ?string} */
    private function public(string $id): array
    {
        $invoice = $this->invoices[$id];

        return [
            'invoice_id' => $id,
            'invoice_number' => (string) $invoice['invoice_number'],
            'status' => (string) $invoice['status'],
            'total' => Money::decimal($invoice['total_minor']),
            'balance' => Money::decimal(max(0, $invoice['total_minor'] - $invoice['paid_minor'])),
            // Clearly fake: there is no payment page behind a mock invoice.
            'invoice_url' => 'https://books.zoho.in/mock-invoice/'.$id,
        ];
    }

    private function requireInvoice(string $invoiceId): void
    {
        if (! isset($this->invoices[$invoiceId])) {
            throw new ZohoException("Zoho Books error 1002: invoice {$invoiceId} does not exist.");
        }
    }

    private function called(string $operation, ?string $target = null): void
    {
        $this->calls[] = ['operation' => $operation, 'target' => $target];

        if (! empty($this->failures[$operation])) {
            throw array_shift($this->failures[$operation]);
        }
    }

    private function id(string $prefix): string
    {
        return 'MOCK'.$prefix.str_pad((string) ++$this->sequence, 10, '0', STR_PAD_LEFT);
    }

    private function load(): void
    {
        if ($this->statePath === null || ! is_file($this->statePath)) {
            return;
        }

        $state = json_decode((string) file_get_contents($this->statePath), true);

        if (is_array($state)) {
            $this->contacts = $state['contacts'] ?? [];
            $this->invoices = $state['invoices'] ?? [];
            $this->payments = $state['payments'] ?? [];
            $this->sequence = (int) ($state['sequence'] ?? 0);
        }
    }

    private function save(): void
    {
        if ($this->statePath === null) {
            return;
        }

        if (! is_dir(dirname($this->statePath))) {
            mkdir(dirname($this->statePath), 0775, true);
        }

        file_put_contents($this->statePath, json_encode([
            'contacts' => $this->contacts, 'invoices' => $this->invoices,
            'payments' => $this->payments, 'sequence' => $this->sequence,
        ], JSON_PRETTY_PRINT), LOCK_EX);
    }
}
