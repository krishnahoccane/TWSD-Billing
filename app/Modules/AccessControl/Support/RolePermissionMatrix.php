<?php

namespace App\Modules\AccessControl\Support;

use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;

/**
 * Default permission set of each system role.
 *
 * This seeds the role_permissions table. After seeding, the database is
 * authoritative, so a Super Admin can grant Operations Admins individual
 * financial or destructive abilities without a code change.
 * Mirrored in docs/ROLE_PERMISSION_MATRIX.md — keep both in step.
 */
final class RolePermissionMatrix
{
    /**
     * @return list<Ability>
     */
    public static function for(RoleName $role): array
    {
        return match ($role) {
            RoleName::SuperAdmin => Ability::cases(),

            // No financial or destructive abilities and no global access by
            // default (see docs/KNOWN_LIMITATIONS.md, decision D-03).
            RoleName::OperationsAdmin => [
                Ability::OrganisationsView,
                Ability::AccountsView,
                Ability::AccountsCreate,
                Ability::AccountsUpdate,
                Ability::AccountsSuspend,
                Ability::AccountsReactivate,
                Ability::AccountsRequestDeletion,
                Ability::AccountsResetPassword,
                Ability::AccountsManageSecurity,
                Ability::AccountsSync,
                Ability::DomainsView,
                Ability::DomainsProcess,
                Ability::IntegrationsViewLogs,
                Ability::AuditViewTechnical,
                Ability::SystemHealthView,
                Ability::QueuesMonitor,
            ],

            RoleName::OrganisationManager => [
                Ability::OrganisationsView,
                Ability::OrganisationsManageContacts,
                Ability::AccountsView,
                Ability::AccountsCreate,
                Ability::AccountsUpdate,
                Ability::AccountsSuspend,
                Ability::AccountsReactivate,
                Ability::AccountsRequestDeletion,
                Ability::AccountsResetPassword,
                Ability::AccountsManageSecurity,
                Ability::DomainsView,
                Ability::DomainsRequest,
                Ability::BillingView,
                Ability::PricingView,
                Ability::InvoicesView,
                Ability::InvoicesDownload,
                Ability::PaymentsView,
                Ability::PaymentsInitiate,
                Ability::SupportCreate,
                Ability::LicensingView,
            ],

            RoleName::BillingManager => [
                Ability::OrganisationsView,
                Ability::BillingView,
                Ability::BillingManageContacts,
                Ability::PricingView,
                Ability::InvoicesView,
                Ability::InvoicesDownload,
                Ability::PaymentsView,
                Ability::PaymentsInitiate,
                Ability::SupportCreate,
                Ability::LicensingView,
            ],
        };
    }
}
