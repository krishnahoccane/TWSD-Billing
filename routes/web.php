<?php

use App\Http\Controllers\DashboardController;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Http\Controllers\RoleController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Audit\Http\Controllers\OrganisationActivityController;
use App\Modules\Authentication\Http\Controllers\SecurityController;
use App\Modules\Authentication\Http\Controllers\UserController;
use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Domains\Http\Controllers\DomainController;
use App\Modules\Domains\Http\Controllers\DomainMappingController;
use App\Modules\GoogleWorkspace\Http\Controllers\AccountController;
use App\Modules\GoogleWorkspace\Http\Controllers\WorkspaceController;
use App\Modules\Integrations\Http\Controllers\IntegrationController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Organisations\Http\Controllers\OrganisationContactController;
use App\Modules\Organisations\Http\Controllers\OrganisationController;
use App\Modules\Organisations\Http\Controllers\OrganisationMemberController;
use App\Modules\Support\Http\Controllers\SupportController;
use App\Modules\System\Http\Controllers\SystemController;
use App\Modules\Zoho\Http\Controllers\ZohoIntegrationController;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes (login, logout, password reset, two-factor) are
| registered by Laravel Fortify — see app/Providers/FortifyServiceProvider.
|
| {organisation} is a uuid resolved inside the signed-in user's own scope
| (OrganisationsServiceProvider); every action is then authorised by
| OrganisationPolicy.
*/

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    // Reachable before 2FA enrolment so privileged users can enrol.
    Route::get('/account/security', SecurityController::class)->name('account.security');

    Route::middleware('two-factor.enrolled')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Organisations
        Route::get('/organisations', [OrganisationController::class, 'index'])->name('organisations.index');
        Route::get('/organisations/create', [OrganisationController::class, 'create'])->name('organisations.create');
        Route::get('/organisations/export', [OrganisationController::class, 'export'])->name('organisations.export');
        Route::get('/organisations/activity', OrganisationActivityController::class)->name('organisations.activity');
        Route::post('/organisations', [OrganisationController::class, 'store'])->name('organisations.store');
        Route::get('/organisations/{organisation}', [OrganisationController::class, 'show'])->name('organisations.show');
        Route::get('/organisations/{organisation}/edit', [OrganisationController::class, 'edit'])->name('organisations.edit');
        Route::put('/organisations/{organisation}', [OrganisationController::class, 'update'])->name('organisations.update');
        Route::post('/organisations/{organisation}/status', [OrganisationController::class, 'changeStatus'])->name('organisations.status');

        Route::post('/organisations/{organisation}/members', [OrganisationMemberController::class, 'store'])->name('organisations.members.store');
        Route::delete('/organisations/{organisation}/members/{user}', [OrganisationMemberController::class, 'destroy'])->name('organisations.members.destroy');
        Route::post('/organisations/{organisation}/contacts', [OrganisationContactController::class, 'store'])->name('organisations.contacts.store');
        Route::delete('/organisations/{organisation}/contacts/{contact}', [OrganisationContactController::class, 'destroy'])->name('organisations.contacts.destroy');
        Route::post('/organisations/{organisation}/domains', [DomainMappingController::class, 'store'])->name('organisations.domains.store');

        // Google Workspace. {account} is a uuid resolved inside the user's
        // own scope (GoogleWorkspaceServiceProvider) and then authorised by
        // GoogleAccountPolicy.
        Route::get('/workspace', [WorkspaceController::class, 'overview'])->name('workspace.overview');
        Route::get('/workspace/sync', [WorkspaceController::class, 'sync'])->name('workspace.sync');
        Route::post('/workspace/sync', [WorkspaceController::class, 'run'])->middleware('throttle:10,1')->name('workspace.sync.run');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/suspended', [AccountController::class, 'index'])->defaults('preset', 'suspended')->name('accounts.suspended');
        Route::get('/accounts/deleted', [AccountController::class, 'index'])->defaults('preset', 'deleted')->name('accounts.deleted');
        Route::get('/accounts/unassigned', [AccountController::class, 'index'])->defaults('preset', 'unassigned')->name('accounts.unassigned');
        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('/accounts/{account}/reactivate', [AccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{account}/soft-delete', [AccountController::class, 'softDelete'])->name('accounts.soft-delete');
        Route::post('/accounts/{account}/restore', [AccountController::class, 'restore'])->name('accounts.restore');
        Route::post('/accounts/{account}/retry', [AccountController::class, 'retry'])->name('accounts.retry');
        Route::post('/accounts/{account}/reveal-password', [AccountController::class, 'reveal'])->middleware('throttle:10,1')->name('accounts.reveal');
        Route::post('/accounts/{account}/assign', [AccountController::class, 'assign'])->name('accounts.assign');
        Route::post('/accounts/{account}/billing-start', [AccountController::class, 'changeBillingStart'])->name('accounts.billing-start');
        Route::post('/accounts/{account}/deletion-requests', [AccountController::class, 'requestPermanentDeletion'])->name('accounts.deletion-requests.store');
        Route::post('/accounts/{account}/deletion-requests/{deletionRequest}/approve', [AccountController::class, 'approvePermanentDeletion'])->name('accounts.deletion-requests.approve');
        Route::post('/accounts/{account}/deletion-requests/{deletionRequest}/reject', [AccountController::class, 'rejectPermanentDeletion'])->name('accounts.deletion-requests.reject');

        // Domains. {domain} and {domainRequest} are uuids resolved inside the
        // user's own scope (DomainsServiceProvider), then authorised by policy.
        Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('/domains/unassigned', [DomainController::class, 'unassigned'])->name('domains.unassigned');
        Route::post('/domains/unassigned/{googleDomain}/assign', [DomainController::class, 'assignGoogleDomain'])->name('domains.unassigned.assign');
        Route::get('/domains/requests', [DomainController::class, 'requests'])->name('domains.requests.index');
        Route::get('/domains/requests/pending', [DomainController::class, 'requests'])->defaults('filter', 'pending')->name('domains.requests.pending');
        Route::get('/domains/verification', [DomainController::class, 'requests'])->defaults('filter', 'verification')->name('domains.verification');
        Route::get('/domains/requests/create', [DomainController::class, 'create'])->name('domains.requests.create');
        Route::post('/domains/requests', [DomainController::class, 'store'])->name('domains.requests.store');
        Route::get('/domains/requests/{domainRequest}', [DomainController::class, 'showRequest'])->name('domains.requests.show');
        Route::post('/domains/requests/{domainRequest}/approve', [DomainController::class, 'approve'])->name('domains.requests.approve');
        Route::post('/domains/requests/{domainRequest}/reject', [DomainController::class, 'reject'])->name('domains.requests.reject');
        Route::post('/domains/requests/{domainRequest}/cancel', [DomainController::class, 'cancel'])->name('domains.requests.cancel');
        Route::post('/domains/requests/{domainRequest}/check-dns', [DomainController::class, 'checkDns'])->middleware('throttle:20,1')->name('domains.requests.check-dns');
        Route::post('/domains/requests/{domainRequest}/verify', [DomainController::class, 'verify'])->middleware('throttle:20,1')->name('domains.requests.verify');
        Route::post('/domains/requests/{domainRequest}/retry', [DomainController::class, 'retry'])->name('domains.requests.retry');
        Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
        Route::post('/domains/{domain}/removal', [DomainController::class, 'requestRemoval'])->name('domains.removal');

        // Billing & finance. {invoice} is tenant-scoped (BillingServiceProvider);
        // {organisation} is tenant-scoped and then checked for pricing.manage.
        Route::get('/billing', [BillingController::class, 'overview'])->name('billing.overview');
        Route::get('/billing/cycles', [BillingController::class, 'cycles'])->name('billing.cycles');
        Route::post('/billing/cycles', [BillingController::class, 'runCycle'])->middleware('throttle:10,1')->name('billing.cycles.run');
        Route::get('/pricing', [BillingController::class, 'pricing'])->name('pricing.plans');
        Route::get('/pricing/organisations', [BillingController::class, 'pricing'])->name('pricing.organisations');
        Route::post('/pricing/platform', [BillingController::class, 'setPlatformPrice'])->name('pricing.platform.store');
        Route::get('/pricing/organisations/{organisation}', [BillingController::class, 'organisationPricing'])->name('pricing.organisations.show');
        Route::post('/pricing/organisations/{organisation}/price', [BillingController::class, 'setOrganisationPrice'])->name('pricing.organisations.price');
        Route::post('/pricing/organisations/{organisation}/overrides', [BillingController::class, 'setOverride'])->name('pricing.organisations.overrides.store');
        Route::post('/pricing/organisations/{organisation}/overrides/{override}/end', [BillingController::class, 'endOverride'])->name('pricing.organisations.overrides.end');
        Route::post('/pricing/organisations/{organisation}/adjustments', [BillingController::class, 'storeAdjustment'])->name('pricing.organisations.adjustments.store');
        Route::post('/pricing/organisations/{organisation}/adjustments/{adjustment}/cancel', [BillingController::class, 'cancelAdjustment'])->name('pricing.organisations.adjustments.cancel');
        Route::get('/invoices', [BillingController::class, 'invoices'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->name('invoices.show');
        Route::post('/invoices/{invoice}/recalculate', [BillingController::class, 'recalculate'])->name('invoices.recalculate');
        Route::post('/invoices/{invoice}/issue', [BillingController::class, 'issue'])->name('invoices.issue');
        Route::post('/invoices/{invoice}/void', [BillingController::class, 'void'])->name('invoices.void');
        Route::post('/invoices/{invoice}/credit-notes', [BillingController::class, 'creditNote'])->name('invoices.credit-notes.store');
        Route::post('/invoices/{invoice}/payments', [BillingController::class, 'recordPayment'])->name('invoices.payments.store');
        Route::get('/payments', [BillingController::class, 'payments'])->name('payments.index');
        Route::get('/payments/outstanding', [BillingController::class, 'payments'])->defaults('filter', 'outstanding')->name('payments.outstanding');
        Route::get('/payments/history', [BillingController::class, 'payments'])->name('payments.history');

        // Integrations
        Route::get('/integrations/google', [IntegrationController::class, 'google'])->name('integrations.google');
        Route::post('/integrations/google/test', [IntegrationController::class, 'testGoogle'])->middleware('throttle:10,1')->name('integrations.google.test');
        Route::get('/integrations/logs', [IntegrationController::class, 'logs'])->name('integrations.logs');
        Route::get('/integrations/zoho', [ZohoIntegrationController::class, 'show'])->name('integrations.zoho');
        Route::post('/integrations/zoho/settings', [ZohoIntegrationController::class, 'saveSettings'])->name('integrations.zoho.settings');
        Route::get('/integrations/zoho/connect', [ZohoIntegrationController::class, 'connect'])->name('integrations.zoho.connect');
        Route::get('/integrations/zoho/callback', [ZohoIntegrationController::class, 'callback'])->name('integrations.zoho.callback');
        Route::post('/integrations/zoho/disconnect', [ZohoIntegrationController::class, 'disconnect'])->name('integrations.zoho.disconnect');
        Route::post('/integrations/zoho/test', [ZohoIntegrationController::class, 'test'])->middleware('throttle:10,1')->name('integrations.zoho.test');
        Route::post('/integrations/zoho/sync', [ZohoIntegrationController::class, 'sync'])->middleware('throttle:10,1')->name('integrations.zoho.sync');
        Route::post('/invoices/{invoice}/simulate-zoho-payment', [ZohoIntegrationController::class, 'simulatePayment'])->name('invoices.simulate-zoho-payment');
        Route::get('/invoices/{invoice}/pdf', [ZohoIntegrationController::class, 'pdf'])->name('invoices.pdf');

        // Organisation-side pages for the organisation currently selected.
        Route::get('/organisation/profile', [OrganisationController::class, 'current'])->name('organisation.profile');
        Route::get('/organisation/managers', [OrganisationController::class, 'current'])->name('organisation.managers');

        // Platform administration
        Route::get('/managers', [OrganisationMemberController::class, 'index'])->name('managers.index');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/active', [UserController::class, 'setActive'])->name('users.active');
        Route::post('/users/{user}/password-link', [UserController::class, 'sendPasswordLink'])
            ->middleware('throttle:6,1')->name('users.password-link');

        // Notifications (always the signed-in user's own).
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');
        Route::get('/account/notifications', [NotificationController::class, 'preferences'])->name('account.notifications');
        Route::post('/account/notifications', [NotificationController::class, 'savePreferences'])->name('account.notifications.save');

        // Support requests
        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('/support/{support}', [SupportController::class, 'show'])->name('support.show');
        Route::put('/support/{support}', [SupportController::class, 'update'])->name('support.update');

        // System
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditLogController::class, 'export'])->middleware('throttle:10,1')->name('audit.export');
        Route::get('/system/queues', [SystemController::class, 'queues'])->name('system.queues');
        Route::post('/system/queues/{uuid}/retry', [SystemController::class, 'retry'])->name('system.queues.retry');
        Route::delete('/system/queues/{uuid}', [SystemController::class, 'forget'])->name('system.queues.forget');
        Route::get('/system/health', [SystemController::class, 'health'])->name('system.health');
        Route::get('/settings', [RoleController::class, 'index'])->name('settings.index');
        Route::put('/settings/roles/{role}', [RoleController::class, 'update'])->name('settings.roles.update');

        // Living reference of the Blade design system. Never in production.
        if (! app()->isProduction()) {
            Route::view('/design-system', 'styleguide')
                ->middleware('can:'.Ability::SettingsManage->value)
                ->name('styleguide');
        }
    });
});
