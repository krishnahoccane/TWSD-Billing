<?php

use App\Modules\AccessControl\Enums\Ability;

/*
|--------------------------------------------------------------------------
| Sidebar navigation
|--------------------------------------------------------------------------
|
| 'platform' is shown to holders of a platform-level role, 'organisation'
| to everyone else. An item is rendered only when BOTH are true:
|   - its named route is registered (so unbuilt modules never produce
|     dead links; items appear automatically as each phase lands), and
|   - the user holds its ability (platform-wide or in any organisation).
|
| Hiding a link is a convenience, not a control: every route still
| authorises server-side.
|
*/

return [

    'platform' => [
        ['items' => [
            ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard'],
        ]],
        ['heading' => 'Organisation Management', 'items' => [
            ['label' => 'All Organisations', 'icon' => 'building-2', 'route' => 'organisations.index', 'ability' => Ability::OrganisationsView],
            ['label' => 'Add Organisation', 'icon' => 'plus', 'route' => 'organisations.create', 'ability' => Ability::OrganisationsCreate],
            ['label' => 'Organisation Managers', 'icon' => 'users', 'route' => 'managers.index', 'ability' => Ability::OrganisationsManageManagers],
            ['label' => 'Dashboard Users', 'icon' => 'user-cog', 'route' => 'users.index', 'ability' => Ability::UsersManage],
            ['label' => 'Organisation Activity', 'icon' => 'activity', 'route' => 'organisations.activity', 'ability' => Ability::AuditView],
        ]],
        ['heading' => 'Google Workspace', 'items' => [
            ['label' => 'Overview', 'icon' => 'gauge', 'route' => 'workspace.overview', 'ability' => Ability::AccountsView],
            ['label' => 'All Email Accounts', 'icon' => 'mail', 'route' => 'accounts.index', 'ability' => Ability::AccountsView],
            ['label' => 'Create Email Account', 'icon' => 'mail-plus', 'route' => 'accounts.create', 'ability' => Ability::AccountsCreate],
            ['label' => 'Suspended Accounts', 'icon' => 'pause-circle', 'route' => 'accounts.suspended', 'ability' => Ability::AccountsView],
            ['label' => 'Soft Deleted Accounts', 'icon' => 'archive', 'route' => 'accounts.deleted', 'ability' => Ability::AccountsView],
            ['label' => 'Unassigned Accounts', 'icon' => 'circle-help', 'route' => 'accounts.unassigned', 'ability' => Ability::AccountsReassignOrganisation],
            ['label' => 'Synchronisation', 'icon' => 'refresh-cw', 'route' => 'workspace.sync', 'ability' => Ability::AccountsSync],
        ]],
        ['heading' => 'Domain Management', 'items' => [
            ['label' => 'All Domains', 'icon' => 'globe', 'route' => 'domains.index', 'ability' => Ability::DomainsView],
            ['label' => 'Unassigned Domains', 'icon' => 'circle-help', 'route' => 'domains.unassigned', 'ability' => Ability::DomainsManage],
            ['label' => 'Domain Requests', 'icon' => 'inbox', 'route' => 'domains.requests.index', 'ability' => Ability::DomainsView],
            ['label' => 'Pending Approvals', 'icon' => 'clock', 'route' => 'domains.requests.pending', 'ability' => Ability::DomainsApprove],
            ['label' => 'Domain Verification', 'icon' => 'shield-check', 'route' => 'domains.verification', 'ability' => Ability::DomainsProcess],
        ]],
        ['heading' => 'Billing & Finance', 'items' => [
            ['label' => 'Billing Overview', 'icon' => 'chart-column', 'route' => 'billing.overview', 'ability' => Ability::BillingView],
            ['label' => 'Pricing Plans', 'icon' => 'tags', 'route' => 'pricing.plans', 'ability' => Ability::PricingManage],
            ['label' => 'Organisation Pricing', 'icon' => 'badge-indian-rupee', 'route' => 'pricing.organisations', 'ability' => Ability::PricingManage],
            ['label' => 'Billing Cycles', 'icon' => 'calendar-range', 'route' => 'billing.cycles', 'ability' => Ability::BillingRunCycles],
            ['label' => 'Invoices', 'icon' => 'receipt-text', 'route' => 'invoices.index', 'ability' => Ability::InvoicesView],
            ['label' => 'Payments', 'icon' => 'credit-card', 'route' => 'payments.index', 'ability' => Ability::PaymentsView],
            ['label' => 'Outstanding Payments', 'icon' => 'triangle-alert', 'route' => 'payments.outstanding', 'ability' => Ability::PaymentsView],
        ]],
        ['heading' => 'Integrations', 'items' => [
            ['label' => 'Google Workspace', 'icon' => 'plug', 'route' => 'integrations.google', 'ability' => Ability::IntegrationsManageGoogle],
            ['label' => 'Zoho Books', 'icon' => 'book-open', 'route' => 'integrations.zoho', 'ability' => Ability::IntegrationsManageZoho],
            ['label' => 'Integration Logs', 'icon' => 'scroll-text', 'route' => 'integrations.logs', 'ability' => Ability::IntegrationsViewLogs],
        ]],
        ['heading' => 'System', 'items' => [
            ['label' => 'Audit Logs', 'icon' => 'history', 'route' => 'audit.index', 'ability' => Ability::AuditViewTechnical],
            ['label' => 'Support Requests', 'icon' => 'life-buoy', 'route' => 'support.index', 'ability' => Ability::SupportManage],
            ['label' => 'Notifications', 'icon' => 'bell', 'route' => 'notifications.index'],
            ['label' => 'Queue Monitoring', 'icon' => 'list-checks', 'route' => 'system.queues', 'ability' => Ability::QueuesMonitor],
            ['label' => 'System Health', 'icon' => 'heart-pulse', 'route' => 'system.health', 'ability' => Ability::SystemHealthView],
            ['label' => 'Settings', 'icon' => 'settings', 'route' => 'settings.index', 'ability' => Ability::SettingsManage],
            ['label' => 'Design System', 'icon' => 'palette', 'route' => 'styleguide', 'ability' => Ability::SettingsManage],
        ]],
    ],

    'organisation' => [
        ['items' => [
            ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard'],
        ]],
        ['heading' => 'Email Accounts', 'items' => [
            ['label' => 'All Accounts', 'icon' => 'mail', 'route' => 'accounts.index', 'ability' => Ability::AccountsView],
            ['label' => 'Create Email', 'icon' => 'mail-plus', 'route' => 'accounts.create', 'ability' => Ability::AccountsCreate],
            ['label' => 'Suspended Accounts', 'icon' => 'pause-circle', 'route' => 'accounts.suspended', 'ability' => Ability::AccountsView],
            ['label' => 'Deleted Accounts', 'icon' => 'archive', 'route' => 'accounts.deleted', 'ability' => Ability::AccountsView],
        ]],
        ['heading' => 'Domains', 'items' => [
            ['label' => 'My Domains', 'icon' => 'globe', 'route' => 'domains.index', 'ability' => Ability::DomainsView],
            ['label' => 'Request New Domain', 'icon' => 'plus', 'route' => 'domains.requests.create', 'ability' => Ability::DomainsRequest],
            ['label' => 'Domain Requests', 'icon' => 'inbox', 'route' => 'domains.requests.index', 'ability' => Ability::DomainsView],
        ]],
        ['heading' => 'Billing', 'items' => [
            ['label' => 'Subscription Overview', 'icon' => 'chart-column', 'route' => 'billing.overview', 'ability' => Ability::BillingView],
            ['label' => 'Invoices', 'icon' => 'receipt-text', 'route' => 'invoices.index', 'ability' => Ability::InvoicesView],
            ['label' => 'Payments', 'icon' => 'credit-card', 'route' => 'payments.index', 'ability' => Ability::PaymentsView],
            ['label' => 'Payment History', 'icon' => 'history', 'route' => 'payments.history', 'ability' => Ability::PaymentsView],
        ]],
        ['heading' => 'Organisation', 'items' => [
            ['label' => 'Organisation Profile', 'icon' => 'building-2', 'route' => 'organisation.profile', 'ability' => Ability::OrganisationsView],
            ['label' => 'Managers', 'icon' => 'users', 'route' => 'organisation.managers', 'ability' => Ability::OrganisationsView],
            ['label' => 'Support', 'icon' => 'life-buoy', 'route' => 'support.index', 'ability' => Ability::SupportCreate],
            ['label' => 'Notifications', 'icon' => 'bell', 'route' => 'notifications.index'],
        ]],
    ],

];
