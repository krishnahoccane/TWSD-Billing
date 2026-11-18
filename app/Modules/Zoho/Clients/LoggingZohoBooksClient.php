<?php

namespace App\Modules\Zoho\Clients;

use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Models\IntegrationSyncLog;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decorator: one integration_sync_logs row per Zoho call (operation,
 * target, outcome, duration). Request bodies and tokens are never logged.
 */
class LoggingZohoBooksClient implements ZohoBooksClientInterface
{
    public function __construct(private readonly ZohoBooksClientInterface $inner) {}

    public function inner(): ZohoBooksClientInterface
    {
        return $this->inner;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function organizations(): array
    {
        return $this->log('organizations.list', null, fn () => $this->inner->organizations());
    }

    public function listContacts(int $page = 1, ?string $search = null): array
    {
        return $this->log('contacts.list', 'page '.$page, fn () => $this->inner->listContacts($page, $search));
    }

    public function getContact(string $contactId): array
    {
        return $this->log('contacts.get', $contactId, fn () => $this->inner->getContact($contactId));
    }

    public function findContactByName(string $contactName): ?array
    {
        return $this->log('contacts.find', $contactName, fn () => $this->inner->findContactByName($contactName));
    }

    public function createContact(array $contact): array
    {
        return $this->log('contacts.create', $contact['contact_name'] ?? null, fn () => $this->inner->createContact($contact));
    }

    public function updateContact(string $contactId, array $contact): void
    {
        $this->log('contacts.update', $contactId, fn () => $this->inner->updateContact($contactId, $contact));
    }

    public function findInvoiceByNumber(string $invoiceNumber): ?array
    {
        return $this->log('invoices.find', $invoiceNumber, fn () => $this->inner->findInvoiceByNumber($invoiceNumber));
    }

    public function createInvoice(array $invoice): array
    {
        return $this->log('invoices.create', $invoice['invoice_number'] ?? null, fn () => $this->inner->createInvoice($invoice));
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->log('invoices.get', $invoiceId, fn () => $this->inner->getInvoice($invoiceId));
    }

    public function markInvoiceSent(string $invoiceId): void
    {
        $this->log('invoices.mark_sent', $invoiceId, fn () => $this->inner->markInvoiceSent($invoiceId));
    }

    public function voidInvoice(string $invoiceId): void
    {
        $this->log('invoices.void', $invoiceId, fn () => $this->inner->voidInvoice($invoiceId));
    }

    public function invoicePdf(string $invoiceId): string
    {
        return $this->log('invoices.pdf', $invoiceId, fn () => $this->inner->invoicePdf($invoiceId));
    }

    public function listCustomerPayments(string $customerId, int $page = 1): array
    {
        return $this->log('payments.list', $customerId, fn () => $this->inner->listCustomerPayments($customerId, $page));
    }

    public function getCustomerPayment(string $paymentId): array
    {
        return $this->log('payments.get', $paymentId, fn () => $this->inner->getCustomerPayment($paymentId));
    }

    public function createCustomerPayment(array $payment): array
    {
        return $this->log('payments.create', $payment['customer_id'] ?? null, fn () => $this->inner->createCustomerPayment($payment));
    }

    private function log(string $operation, ?string $target, Closure $call): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->write($operation, $target, $started);

            return $result;
        } catch (Throwable $e) {
            $this->write($operation, $target, $started, $e);

            throw $e;
        }
    }

    private function write(string $operation, ?string $target, int $started, ?Throwable $error = null): void
    {
        try {
            IntegrationSyncLog::query()->create([
                'provider' => $this->inner->name(),
                'operation' => $operation,
                'target' => $target === null ? null : Str::limit($target, 115, ''),
                'status' => $error === null ? 'success' : 'failure',
                'error' => $error === null ? null : Str::limit(class_basename($error).': '.$error->getMessage(), 2000),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'request_id' => Context::get('request_id'),
            ]);
        } catch (Throwable $loggingFailure) {
            report($loggingFailure);
        }
    }
}
