<?php

namespace App\Modules\Notifications;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\Money;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Notifications\Enums\NotificationType as T;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Support\Models\SupportRequest;
use App\Modules\Zoho\Services\ZohoSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The wording of every notification, in one place. Each method is called
 * from the service where the event happens. Messages contain addresses,
 * amounts and links — never passwords, tokens or other tenants' data.
 */
class NotificationEvents
{
    public function __construct(private readonly Notifier $notifier) {}

    // ---- Mailboxes --------------------------------------------------------

    public function accountCreated(GoogleAccount $account): void
    {
        $this->notifier->send(T::AccountCreated, 'Email account created', "{$account->primary_email} is ready.",
            route('accounts.show', $account), $account->organisation);
    }

    public function accountCreationFailed(GoogleAccount $account, string $error): void
    {
        $this->notifier->send(T::AccountCreationFailed, 'Email account could not be created',
            "{$account->primary_email} could not be created in Google Workspace. It can be retried from the account page.",
            route('accounts.show', $account), $account->organisation);
    }

    public function accountOperationFailed(GoogleAccount $account, string $operation, string $error): void
    {
        $this->notifier->send(T::GoogleIntegrationError, 'Google Workspace operation failed',
            str_replace('_', ' ', $operation)." of {$account->primary_email} failed: ".Str::limit($error, 300),
            route('accounts.show', $account), throttleKey: 'account-op:'.$operation);
    }

    public function directorySyncFailed(string $type, string $error): void
    {
        $this->notifier->send(T::GoogleIntegrationError, 'Google Workspace synchronisation failed',
            str_replace('_', ' ', $type).' synchronisation failed: '.Str::limit($error, 300),
            route('workspace.sync'), throttleKey: 'sync:'.$type);
    }

    public function accountSuspended(GoogleAccount $account): void
    {
        $this->notifier->send(T::AccountSuspended, 'Email account suspended',
            "{$account->primary_email} was suspended. Reason: ".($account->suspension_reason ?? '—').'. Suspended mailboxes remain billable.',
            route('accounts.show', $account), $account->organisation);
    }

    public function accountReactivated(GoogleAccount $account): void
    {
        $this->notifier->send(T::AccountReactivated, 'Email account reactivated', "{$account->primary_email} can sign in again.",
            route('accounts.show', $account), $account->organisation);
    }

    public function accountSoftDeleted(GoogleAccount $account): void
    {
        $this->notifier->send(T::AccountDeletionRequested, 'Email account deleted',
            "{$account->primary_email} was deleted (suspended in Google, not removed). Billing stops from "
                .($account->deletion_effective_date?->format('d M Y') ?? 'today').'. Reason: '.($account->deletion_reason ?? '—').'.',
            route('accounts.show', $account), $account->organisation);
    }

    public function permanentDeletionRequested(GoogleAccount $account, AccountDeletionRequest $request): void
    {
        $this->notifier->send(T::PermanentDeletionRequested, 'Permanent deletion awaiting approval',
            ($request->requester?->name ?? 'A Super Admin')." asked to permanently delete {$account->primary_email}. A different Super Admin must approve it.",
            route('accounts.show', $account), $account->organisation);
    }

    // ---- Domains ----------------------------------------------------------

    public function domainRequestSubmitted(DomainRequest $request): void
    {
        $this->notifier->send(T::DomainRequestSubmitted, 'Domain request to review',
            ($request->organisation?->name ?? 'An organisation')." requested {$request->domain_name}.",
            route('domains.requests.show', $request), $request->organisation);
    }

    public function domainRequestApproved(DomainRequest $request): void
    {
        $this->notifier->send(T::DomainRequestApproved, 'Domain request approved',
            "{$request->domain_name} was approved. Add the DNS TXT record shown on the request page so Google can verify it.",
            route('domains.requests.show', $request), $request->organisation, array_filter([$request->requester]));
    }

    public function domainRequestRejected(DomainRequest $request): void
    {
        $this->notifier->send(T::DomainRequestRejected, 'Domain request rejected',
            "{$request->domain_name} was rejected. Reason: ".($request->decision_note ?? '—').'.',
            route('domains.requests.show', $request), $request->organisation, array_filter([$request->requester]));
    }

    public function domainActivated(DomainRequest $request): void
    {
        $this->notifier->send(T::DomainVerified, 'Domain verified and active',
            "{$request->domain_name} is verified and can now be used for new email accounts.",
            route('domains.requests.show', $request), $request->organisation, array_filter([$request->requester]));
    }

    public function domainStepFailed(DomainRequest $request, string $step, string $error): void
    {
        $this->notifier->send(T::GoogleIntegrationError, 'Domain workflow step failed',
            "The {$step} step for {$request->domain_name} failed: ".Str::limit($error, 300),
            route('domains.requests.show', $request), throttleKey: 'domain:'.$request->getKey().':'.$step);
    }

    // ---- Billing ----------------------------------------------------------

    public function invoiceIssued(Invoice $invoice): void
    {
        $this->notifier->send(T::InvoiceIssued, 'New invoice '.$invoice->invoice_number,
            'Invoice '.$invoice->invoice_number.' for '.$invoice->period_start->format('F Y').' is '
                .Money::format($invoice->total_minor, $invoice->currency).', due '.$invoice->due_date?->format('d M Y').'.',
            route('invoices.show', $invoice), $invoice->organisation);
    }

    /**
     * Payment reminder schedule (PaymentReminderService). Every reminder says
     * that the organisation's mailboxes are suspended automatically if the
     * invoice stays unpaid.
     */
    public function paymentReminder(Invoice $invoice, string $stage): void
    {
        $tz = config('wcc.billing.timezone');
        $due = CarbonImmutable::parse($invoice->due_date->toDateString(), $tz);
        $suspendOn = $due->addDays((int) config('wcc.billing.suspend_after_days', 2) + 1);
        $amount = Money::format($invoice->balance_minor, $invoice->currency);
        $number = $invoice->invoice_number;
        $autoSuspend = (bool) config('wcc.billing.auto_suspend', true);
        $warning = $autoSuspend
            ? ' If it is not paid by '.$suspendOn->subDay()->format('d M Y').', all email accounts of '.$invoice->organisation?->name
                .' will be suspended automatically on '.$suspendOn->format('d M Y').'.'
            : '';

        [$title, $lead] = match ($stage) {
            'reminder_1' => ["Payment reminder: invoice {$number}", "Invoice {$number} for {$amount} is due on {$due->format('d M Y')}."],
            'reminder_2' => ["Second reminder: invoice {$number} is due tomorrow", "Invoice {$number} for {$amount} is due tomorrow, {$due->format('d M Y')}."],
            'final_1' => ["Final reminder: invoice {$number} is due today", "Invoice {$number} for {$amount} is due today."],
            'final_2' => ["Final reminder: invoice {$number} is overdue", "Invoice {$number} for {$amount} was due on {$due->format('d M Y')} and is still unpaid."],
            default => ['Final reminder: email accounts due for suspension', "Invoice {$number} for {$amount} was due on {$due->format('d M Y')} and is still unpaid."
                .($autoSuspend ? ' Your email accounts are due for automatic suspension.' : '')],
        };

        $payLink = app(ZohoSync::class)->mode() === 'live' ? app(ZohoSync::class)->mapping($invoice)?->zoho_url : null;

        $this->notifier->send($stage === 'reminder_1' || $stage === 'reminder_2' ? T::PaymentReminder : T::InvoiceOverdue, $title,
            $lead.$warning.($payLink ? ' Pay online: '.$payLink : ' Please pay now.').' Ignore this message if you have already paid.',
            route('invoices.show', $invoice), $invoice->organisation);
    }

    /** @param  iterable<Invoice>  $invoices */
    public function accountsSuspendedForNonPayment(Organisation $organisation, iterable $invoices, int $count): void
    {
        $numbers = collect($invoices)->pluck('invoice_number')->filter()->implode(', ');

        $this->notifier->send(T::AccountsSuspendedForNonPayment, 'Email accounts suspended for non-payment',
            "{$count} email account(s) of {$organisation->name} were suspended automatically because invoice {$numbers} is unpaid. "
                .'They are reactivated automatically as soon as the payment is received. Accounts that were already suspended before stay suspended.',
            route('invoices.index'), $organisation);
    }

    public function accountsReactivatedAfterPayment(Organisation $organisation, int $count): void
    {
        $this->notifier->send(T::AccountsReactivatedAfterPayment, 'Email accounts reactivated',
            "Payment received — thank you. {$count} email account(s) of {$organisation->name} suspended for non-payment are being reactivated. "
                .'Accounts that were suspended by hand before stay suspended.',
            route('accounts.index'), $organisation);
    }

    public function paymentReceived(Payment $payment): void
    {
        $this->notifier->send(T::PaymentReceived, 'Payment received',
            Money::format($payment->amount_minor, $payment->currency).' received on '.$payment->paid_on->format('d M Y')
                .($payment->reference ? ' (ref '.$payment->reference.')' : '').'. Thank you.',
            route('payments.index'), $payment->organisation);
    }

    public function zohoMismatch(Invoice $invoice, string $detail): void
    {
        $this->notifier->send(T::PaymentReconciliationFailed, 'Invoice '.$invoice->invoice_number.' differs from Zoho Books',
            Str::limit($detail, 400), route('invoices.show', $invoice), throttleKey: 'mismatch:'.$invoice->getKey(), throttleSeconds: 86400);
    }

    public function zohoError(string $context, string $error): void
    {
        $this->notifier->send(T::ZohoIntegrationError, 'Zoho Books integration error',
            Str::limit($context.': '.$error, 400), route('integrations.zoho'), throttleKey: 'zoho:'.Str::before($context, ' '));
    }

    // ---- Support ----------------------------------------------------------

    public function supportCreated(SupportRequest $request): void
    {
        $this->notifier->send(T::SupportRequestCreated, 'New support request',
            ($request->organisation?->name ?? '').': '.$request->subject,
            route('support.show', $request), $request->organisation);
    }

    public function supportUpdated(SupportRequest $request): void
    {
        $this->notifier->send(T::SupportRequestUpdated, 'Support request updated',
            '"'.$request->subject.'" is now '.str_replace('_', ' ', $request->status).'.',
            route('support.show', $request), extra: array_filter([$request->user]));
    }
}
