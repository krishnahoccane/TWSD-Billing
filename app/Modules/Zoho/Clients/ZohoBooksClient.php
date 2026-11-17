<?php

namespace App\Modules\Zoho\Clients;

use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Exceptions\ZohoException;
use App\Modules\Zoho\Exceptions\ZohoNotConnectedException;
use App\Modules\Zoho\Exceptions\ZohoRateLimitException;
use App\Modules\Zoho\Exceptions\ZohoTransientException;
use App\Modules\Zoho\Services\ZohoConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Zoho Books API v3 over HTTPS. Reference: https://www.zoho.com/books/api/v3/
 *
 * STATUS: written from the published reference and tested against a faked
 * HTTP layer. It has NOT been run against a live Zoho Books organisation.
 * Confirm in the first live test: list filters (contact_name,
 * invoice_number, customer_id), the invoice PDF (accept=pdf) and whether
 * line items need item_id.
 */
class ZohoBooksClient implements ZohoBooksClientInterface
{
    public function __construct(
        private readonly Http $http,
        private readonly ZohoConnection $connection,
    ) {}

    public function name(): string
    {
        return 'zoho';
    }

    public function organizations(): array
    {
        $data = $this->send('GET', '/organizations', withOrganisation: false);

        return array_map(fn (array $org) => [
            'organization_id' => (string) $org['organization_id'],
            'name' => (string) ($org['name'] ?? ''),
        ], $data['organizations'] ?? []);
    }

    public function findContactByName(string $contactName): ?array
    {
        $data = $this->send('GET', '/contacts', query: ['contact_name' => $contactName, 'contact_type' => 'customer']);

        foreach ($data['contacts'] ?? [] as $contact) {
            if (($contact['contact_name'] ?? null) === $contactName) {
                return ['contact_id' => (string) $contact['contact_id'], 'contact_name' => $contactName];
            }
        }

        return null;
    }

    public function createContact(array $contact): array
    {
        $data = $this->send('POST', '/contacts', $contact);

        return ['contact_id' => (string) $data['contact']['contact_id']];
    }

    public function updateContact(string $contactId, array $contact): void
    {
        $this->send('PUT', '/contacts/'.rawurlencode($contactId), $contact);
    }

    public function findInvoiceByNumber(string $invoiceNumber): ?array
    {
        $data = $this->send('GET', '/invoices', query: ['invoice_number' => $invoiceNumber]);

        foreach ($data['invoices'] ?? [] as $invoice) {
            if (($invoice['invoice_number'] ?? null) === $invoiceNumber) {
                return $this->invoice($invoice);
            }
        }

        return null;
    }

    public function createInvoice(array $invoice): array
    {
        $data = $this->send('POST', '/invoices', $invoice, ['ignore_auto_number_generation' => 'true']);

        return $this->invoice($data['invoice']);
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->invoice($this->send('GET', '/invoices/'.rawurlencode($invoiceId))['invoice']);
    }

    public function markInvoiceSent(string $invoiceId): void
    {
        $this->send('POST', '/invoices/'.rawurlencode($invoiceId).'/status/sent');
    }

    public function voidInvoice(string $invoiceId): void
    {
        $this->send('POST', '/invoices/'.rawurlencode($invoiceId).'/status/void');
    }

    public function invoicePdf(string $invoiceId): string
    {
        $response = $this->request('GET', '/invoices/'.rawurlencode($invoiceId), query: ['accept' => 'pdf'], json: false);

        return $response->body();
    }

    public function listCustomerPayments(string $customerId, int $page = 1): array
    {
        $data = $this->send('GET', '/customerpayments', query: ['customer_id' => $customerId, 'page' => $page, 'per_page' => 200]);
        $payments = array_map(fn (array $payment) => [
            'payment_id' => (string) $payment['payment_id'],
            'date' => (string) ($payment['date'] ?? ''),
            'amount' => (string) ($payment['amount'] ?? '0'),
            'payment_mode' => $payment['payment_mode'] ?? null,
            'reference_number' => $payment['reference_number'] ?? null,
        ], $data['payments'] ?? $data['customerpayments'] ?? []);

        $hasMore = $data['page_context']['has_more_page'] ?? (count($payments) >= 200);

        return ['payments' => $payments, 'has_more' => (bool) $hasMore];
    }

    public function getCustomerPayment(string $paymentId): array
    {
        $payment = $this->send('GET', '/customerpayments/'.rawurlencode($paymentId))['payment'];

        return [
            'payment_id' => (string) $payment['payment_id'],
            'customer_id' => (string) ($payment['customer_id'] ?? ''),
            'date' => (string) ($payment['date'] ?? ''),
            'amount' => (string) ($payment['amount'] ?? '0'),
            'payment_mode' => $payment['payment_mode'] ?? null,
            'reference_number' => $payment['reference_number'] ?? null,
            'invoices' => array_map(fn (array $row) => [
                'invoice_id' => (string) $row['invoice_id'],
                'amount_applied' => (string) ($row['amount_applied'] ?? '0'),
            ], $payment['invoices'] ?? []),
        ];
    }

    public function createCustomerPayment(array $payment): array
    {
        $data = $this->send('POST', '/customerpayments', $payment);

        return ['payment_id' => (string) $data['payment']['payment_id']];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null, array $query = [], bool $withOrganisation = true): array
    {
        $response = $this->request($method, $path, $body, $query, $withOrganisation);
        $data = $response->json() ?? [];

        // Zoho reports success as {"code": 0, ...}.
        if (isset($data['code']) && (int) $data['code'] !== 0) {
            throw new ZohoException('Zoho Books error '.$data['code'].' on '.$method.' '.$path.': '.($data['message'] ?? 'no message'));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [], bool $withOrganisation = true, bool $json = true): Response
    {
        if ($withOrganisation) {
            $organisation = $this->connection->organisationId();

            if ($organisation === '') {
                throw new ZohoNotConnectedException('The Zoho Books organisation id is not set. Save it under Integrations → Zoho Books.');
            }

            $query['organization_id'] = $organisation;
        }

        $url = $this->connection->apiBase().$path.'?'.http_build_query($query);

        $request = $this->http
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$this->connection->accessToken()])
            ->timeout(30)->connectTimeout(5);

        if ($json) {
            $request = $request->acceptJson();
        }

        try {
            $response = $body === null ? $request->send($method, $url) : $request->send($method, $url, ['json' => $body]);
        } catch (ConnectionException) {
            throw new ZohoTransientException("Zoho Books unreachable ({$method} {$path}).");
        }

        if ($response->successful()) {
            return $response;
        }

        $message = "Zoho Books {$response->status()} on {$method} {$path}: ".($response->json('message') ?? 'no message');

        throw match (true) {
            $response->status() === 401 => (function () use ($message) {
                $this->connection->forgetAccessToken();

                return new ZohoTransientException($message);
            })(),
            $response->status() === 429 => new ZohoRateLimitException($message, max(1, (int) ($response->header('Retry-After') ?: 60))),
            $response->serverError() => new ZohoTransientException($message),
            default => new ZohoException($message),
        };
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{invoice_id: string, invoice_number: string, status: string, total: string, balance: string, invoice_url: ?string}
     */
    private function invoice(array $invoice): array
    {
        return [
            'invoice_id' => (string) $invoice['invoice_id'],
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'status' => (string) ($invoice['status'] ?? ''),
            'total' => (string) ($invoice['total'] ?? '0'),
            'balance' => (string) ($invoice['balance'] ?? '0'),
            'invoice_url' => $invoice['invoice_url'] ?? null,
        ];
    }
}
