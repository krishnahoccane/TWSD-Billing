<?php

namespace App\Modules\AccessControl\Enums;

use Illuminate\Support\Str;

/**
 * Every permission the application understands.
 *
 * The enum is the source of truth; the `permissions` table is synchronised
 * from it by RolesAndPermissionsSeeder. Each case is also registered as a
 * Laravel Gate ability of the same name (see AccessControlServiceProvider).
 */
enum Ability: string
{
    // Platform
    case PlatformGlobalAccess = 'platform.global_access';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case SystemHealthView = 'system.health.view';
    case QueuesMonitor = 'queues.monitor';

    // Organisations
    case OrganisationsView = 'organisations.view';
    case OrganisationsCreate = 'organisations.create';
    case OrganisationsUpdate = 'organisations.update';
    case OrganisationsChangeStatus = 'organisations.change_status';
    case OrganisationsManageManagers = 'organisations.manage_managers';
    case OrganisationsManageContacts = 'organisations.manage_contacts';

    // Google Workspace accounts
    case AccountsView = 'accounts.view';
    case AccountsCreate = 'accounts.create';
    case AccountsUpdate = 'accounts.update';
    case AccountsSuspend = 'accounts.suspend';
    case AccountsReactivate = 'accounts.reactivate';
    case AccountsRequestDeletion = 'accounts.request_deletion';
    case AccountsSoftDelete = 'accounts.soft_delete';
    case AccountsRestore = 'accounts.restore';
    case AccountsApprovePermanentDeletion = 'accounts.permanent_delete.approve';
    case AccountsExecutePermanentDeletion = 'accounts.permanent_delete.execute';
    case AccountsReassignOrganisation = 'accounts.reassign_organisation';
    case AccountsSync = 'accounts.sync';
    case AccountsResetPassword = 'accounts.reset_password';
    case AccountsManageSecurity = 'accounts.manage_security';
    // Reactivate a mailbox suspended automatically for non-payment.
    case AccountsReactivatePaymentSuspended = 'accounts.reactivate_payment_suspended';

    // Domains
    case DomainsView = 'domains.view';
    case DomainsRequest = 'domains.request';
    case DomainsApprove = 'domains.approve';
    case DomainsProcess = 'domains.process';
    case DomainsRemove = 'domains.remove';
    case DomainsManage = 'domains.manage';

    // Billing & finance
    case BillingView = 'billing.view';
    case BillingManageContacts = 'billing.manage_contacts';
    case BillingManageAdjustments = 'billing.adjustments.manage';
    case BillingRunCycles = 'billing.cycles.run';
    case PricingView = 'pricing.view';
    case PricingManage = 'pricing.manage';
    case InvoicesView = 'invoices.view';
    case InvoicesDownload = 'invoices.download';
    case InvoicesManage = 'invoices.manage';
    case PaymentsView = 'payments.view';
    case PaymentsInitiate = 'payments.initiate';
    case PaymentsReconcile = 'payments.reconcile';

    // Integrations
    case IntegrationsManageGoogle = 'integrations.google.manage';
    case IntegrationsManageZoho = 'integrations.zoho.manage';
    case IntegrationsManageWhatsApp = 'integrations.whatsapp.manage';
    case IntegrationsViewLogs = 'integrations.logs.view';

    // Audit & support
    case AuditView = 'audit.view';
    case AuditViewTechnical = 'audit.view_technical';
    case SupportCreate = 'support.create';
    case SupportManage = 'support.manage';

    public function group(): string
    {
        return Str::before($this->value, '.');
    }

    public function label(): string
    {
        return Str::of($this->value)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    /**
     * Financial or destructive abilities. These are never part of the
     * Operations Admin default set and are highlighted in the role editor.
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::PlatformGlobalAccess,
            self::UsersManage,
            self::SettingsManage,
            self::OrganisationsChangeStatus,
            self::AccountsSoftDelete,
            self::AccountsApprovePermanentDeletion,
            self::AccountsExecutePermanentDeletion,
            self::AccountsReassignOrganisation,
            self::DomainsApprove,
            self::DomainsRemove,
            self::DomainsManage,
            self::BillingManageAdjustments,
            self::BillingRunCycles,
            self::PricingManage,
            self::InvoicesManage,
            self::PaymentsInitiate,
            self::PaymentsReconcile,
            self::IntegrationsManageGoogle,
            self::IntegrationsManageZoho,
            self::IntegrationsManageWhatsApp,
            self::AccountsReactivatePaymentSuspended,
        ], true);
    }

    /**
     * What an organisation-assigned user keeps while their organisation is
     * suspended: read access, invoices and payments (decision D-06).
     */
    public function allowedWhenOrganisationSuspended(): bool
    {
        return str_ends_with($this->value, '.view')
            || in_array($this, [self::InvoicesDownload, self::PaymentsInitiate, self::SupportCreate], true);
    }

    /**
     * Abilities that only make sense at platform level and are never
     * evaluated against a single organisation.
     */
    public function isPlatformOnly(): bool
    {
        return in_array($this, [
            self::PlatformGlobalAccess,
            self::UsersManage,
            self::SettingsManage,
            self::SystemHealthView,
            self::QueuesMonitor,
            self::OrganisationsCreate,
            self::AccountsSync,
            self::IntegrationsManageGoogle,
            self::IntegrationsManageZoho,
            self::IntegrationsManageWhatsApp,
            self::IntegrationsViewLogs,
            self::AuditViewTechnical,
            self::BillingRunCycles,
        ], true);
    }
}
