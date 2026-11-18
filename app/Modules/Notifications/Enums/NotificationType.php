<?php

namespace App\Modules\Notifications\Enums;

use App\Modules\AccessControl\Enums\Ability;

/**
 * Every notification the application sends, who receives it, and whether
 * it goes by e-mail unless the user turns that off. In-app copies are
 * always kept.
 *
 * Audience:
 *  - organisationAbility(): users who hold this ability IN the event's
 *    organisation (managers, billing managers, assigned Ops Admins).
 *  - platformAbility(): holders of this ability at platform level
 *    (Super Admins; Ops Admins for technical events).
 */
enum NotificationType: string
{
    case AccountCreated = 'account.created';
    case AccountCreationFailed = 'account.creation_failed';
    case AccountSuspended = 'account.suspended';
    case AccountReactivated = 'account.reactivated';
    case AccountDeletionRequested = 'account.deletion_requested';
    case PermanentDeletionRequested = 'account.permanent_deletion_requested';
    case DomainRequestSubmitted = 'domain.request_submitted';
    case DomainRequestApproved = 'domain.request_approved';
    case DomainRequestRejected = 'domain.request_rejected';
    case DomainVerified = 'domain.verified';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceOverdue = 'invoice.overdue';
    case PaymentReminder = 'invoice.payment_reminder';
    case AccountsSuspendedForNonPayment = 'billing.accounts_suspended';
    case AccountsReactivatedAfterPayment = 'billing.accounts_reactivated';
    case PaymentReceived = 'payment.received';
    case PaymentReconciliationFailed = 'payment.reconciliation_failed';
    case GoogleIntegrationError = 'integration.google_error';
    case ZohoIntegrationError = 'integration.zoho_error';
    case SupportRequestCreated = 'support.created';
    case SupportRequestUpdated = 'support.updated';

    public function label(): string
    {
        return match ($this) {
            self::AccountCreated => 'Email account created',
            self::AccountCreationFailed => 'Email account creation failed',
            self::AccountSuspended => 'Email account suspended',
            self::AccountReactivated => 'Email account reactivated',
            self::AccountDeletionRequested => 'Email account deleted (soft)',
            self::PermanentDeletionRequested => 'Permanent deletion awaiting approval',
            self::DomainRequestSubmitted => 'Domain request submitted',
            self::DomainRequestApproved => 'Domain request approved',
            self::DomainRequestRejected => 'Domain request rejected',
            self::DomainVerified => 'Domain verified and active',
            self::InvoiceIssued => 'Invoice issued',
            self::InvoiceOverdue => 'Invoice overdue',
            self::PaymentReminder => 'Payment reminder',
            self::AccountsSuspendedForNonPayment => 'Email accounts suspended for non-payment',
            self::AccountsReactivatedAfterPayment => 'Email accounts reactivated after payment',
            self::PaymentReceived => 'Payment received',
            self::PaymentReconciliationFailed => 'Payment reconciliation problem',
            self::GoogleIntegrationError => 'Google Workspace integration error',
            self::ZohoIntegrationError => 'Zoho Books integration error',
            self::SupportRequestCreated => 'New support request',
            self::SupportRequestUpdated => 'Support request updated',
        };
    }

    public function organisationAbility(): ?Ability
    {
        return match ($this) {
            self::AccountCreated, self::AccountCreationFailed, self::AccountSuspended,
            self::AccountReactivated, self::AccountDeletionRequested => Ability::AccountsView,
            self::DomainRequestApproved, self::DomainRequestRejected, self::DomainVerified => Ability::DomainsView,
            self::InvoiceIssued, self::InvoiceOverdue, self::PaymentReceived, self::PaymentReminder => Ability::BillingView,
            self::AccountsSuspendedForNonPayment, self::AccountsReactivatedAfterPayment => Ability::AccountsView,
            default => null,
        };
    }

    public function platformAbility(): ?Ability
    {
        return match ($this) {
            self::PermanentDeletionRequested => Ability::AccountsApprovePermanentDeletion,
            self::DomainRequestSubmitted => Ability::DomainsApprove,
            self::AccountCreationFailed, self::GoogleIntegrationError => Ability::IntegrationsViewLogs,
            self::PaymentReconciliationFailed, self::ZohoIntegrationError => Ability::IntegrationsManageZoho,
            self::SupportRequestCreated => Ability::SupportManage,
            self::InvoiceOverdue, self::AccountsSuspendedForNonPayment, self::AccountsReactivatedAfterPayment => Ability::PaymentsReconcile,
            default => null,
        };
    }

    /** E-mail unless the user opts out. Routine events default to in-app only. */
    public function mailByDefault(): bool
    {
        return ! in_array($this, [self::AccountCreated, self::AccountReactivated, self::DomainVerified], true);
    }

    public function level(): string
    {
        return match ($this) {
            self::AccountCreationFailed, self::GoogleIntegrationError, self::ZohoIntegrationError,
            self::PaymentReconciliationFailed, self::InvoiceOverdue, self::AccountsSuspendedForNonPayment => 'danger',
            self::PaymentReminder => 'warning',
            self::AccountSuspended, self::AccountDeletionRequested, self::PermanentDeletionRequested,
            self::DomainRequestRejected => 'warning',
            self::PaymentReceived, self::DomainVerified, self::AccountCreated, self::DomainRequestApproved,
            self::AccountsReactivatedAfterPayment => 'success',
            default => 'info',
        };
    }

    /**
     * Organisation events that may also go to WhatsApp (organisation phone
     * and the organisation's own users). Technical and platform-internal
     * events never do.
     */
    public function whatsAppEligible(): bool
    {
        return in_array($this, [
            self::AccountCreated, self::AccountSuspended, self::AccountReactivated, self::AccountDeletionRequested,
            self::DomainRequestSubmitted, self::DomainRequestApproved, self::DomainRequestRejected, self::DomainVerified,
            self::InvoiceIssued, self::InvoiceOverdue, self::PaymentReminder, self::PaymentReceived,
            self::AccountsSuspendedForNonPayment, self::AccountsReactivatedAfterPayment,
        ], true);
    }
}
