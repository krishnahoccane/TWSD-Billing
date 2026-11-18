<?php

namespace App\Modules\Zoho\Contracts;

use App\Modules\Zoho\Exceptions\ZohoException;

/**
 * Everything the application needs from Zoho Books (API v3).
 * Implementations: ZohoBooksClient (HTTPS) and MockZohoBooksClient.
 *
 * Amounts cross this boundary as decimal strings ("1250.00"), exactly as
 * Zoho's JSON uses them; conversion to paise happens in ZohoSync.
 *
 * @throws ZohoException
 */
interface ZohoBooksClientInterface
{
    /** "zoho" or "mock". */
    public function name(): string;

    /** @return list<array{organization_id: string, name: string}> */
    public function organizations(): array;

    /**
     * Customers in Zoho Books, one page (sorted by name).
     *
     * @return array{contacts: list<array{contact_id: string, contact_name: string, company_name: string, email: ?string, phone: ?string, status: string, outstanding: string}>, has_more: bool}
     */
    public function listContacts(int $page = 1, ?string $search = null): array;

    /**
     * One contact with its tax and address details.
     *
     * @return array{contact_id: string, contact_name: string, company_name: string, legal_name: ?string, gst_no: ?string, pan_no: ?string, email: ?string, phone: ?string, billing_address: array<string, ?string>, primary_contact: array{name: ?string, email: ?string, phone: ?string}}
     */
    public function getContact(string $contactId): array;

    /** @return array{contact_id: string, contact_name: string}|null exact contact_name match */
    public function findContactByName(string $contactName): ?array;

    /**
     * @param  array<string, mixed>  $contact
     * @return array{contact_id: string}
     */
    public function createContact(array $contact): array;

    /** @param  array<string, mixed>  $contact */
    public function updateContact(string $contactId, array $contact): void;

    /** @return array<string, mixed>|null the invoice with this exact number */
    public function findInvoiceByNumber(string $invoiceNumber): ?array;

    /**
     * Creates the invoice with OUR invoice number (ignore_auto_number_generation).
     *
     * @param  array<string, mixed>  $invoice
     * @return array{invoice_id: string, invoice_number: string, status: string, total: string, balance: string, invoice_url: ?string}
     */
    public function createInvoice(array $invoice): array;

    /** @return array{invoice_id: string, invoice_number: string, status: string, total: string, balance: string, invoice_url: ?string} */
    public function getInvoice(string $invoiceId): array;

    public function markInvoiceSent(string $invoiceId): void;

    public function voidInvoice(string $invoiceId): void;

    /** Raw PDF bytes of the invoice as rendered by Zoho Books. */
    public function invoicePdf(string $invoiceId): string;

    /**
     * @return array{payments: list<array{payment_id: string, date: string, amount: string, payment_mode: ?string, reference_number: ?string}>, has_more: bool}
     */
    public function listCustomerPayments(string $customerId, int $page = 1): array;

    /**
     * @return array{payment_id: string, customer_id: string, date: string, amount: string, payment_mode: ?string, reference_number: ?string, invoices: list<array{invoice_id: string, amount_applied: string}>}
     */
    public function getCustomerPayment(string $paymentId): array;

    /**
     * @param  array<string, mixed>  $payment
     * @return array{payment_id: string}
     */
    public function createCustomerPayment(array $payment): array;
}
