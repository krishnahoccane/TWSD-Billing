<?php

namespace App\Modules\AccessControl\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case OperationsAdmin = 'operations_admin';
    case OrganisationManager = 'organisation_manager';
    case BillingManager = 'billing_manager';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::OperationsAdmin => 'Operations Admin',
            self::OrganisationManager => 'Organisation Manager',
            self::BillingManager => 'Billing Manager',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full platform access across every organisation.',
            self::OperationsAdmin => 'Internal technical administrator for assigned organisations.',
            self::OrganisationManager => 'Manages email accounts, domains and billing for assigned organisations.',
            self::BillingManager => 'Financial access only for assigned organisations.',
        };
    }

    public function scope(): RoleScope
    {
        return match ($this) {
            self::SuperAdmin => RoleScope::Platform,
            self::OperationsAdmin => RoleScope::Both,
            self::OrganisationManager, self::BillingManager => RoleScope::Organisation,
        };
    }
}
