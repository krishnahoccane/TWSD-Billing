<?php

use App\Modules\Authentication\Http\Controllers\Api\V1\LoginController;
use App\Modules\Authentication\Http\Controllers\Api\V1\LogoutController;
use App\Modules\Authentication\Http\Controllers\Api\V1\ProfileController;
use App\Modules\Billing\Http\Controllers\Api\V1\BillingApiController;
use App\Modules\Domains\Http\Controllers\Api\V1\DomainController;
use App\Modules\GoogleWorkspace\Http\Controllers\Api\V1\AccountController;
use App\Modules\Licensing\Http\Controllers\Storefront\StorefrontApiController;
use App\Modules\Licensing\Http\Middleware\AuthenticateStorefront;
use App\Modules\Licensing\Http\Middleware\StorefrontIdempotency;
use App\Modules\Organisations\Http\Controllers\Api\V1\OrganisationController;
use App\Modules\Zoho\Http\Controllers\Api\V1\ZohoApiController;
use App\Modules\Zoho\Http\Controllers\ZohoIntegrationController;
use App\Modules\Zoho\Http\Controllers\ZohoWebhookController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are served under /api/v1 (see bootstrap/app.php).
| Resources are addressed by uuid; numeric ids are never exposed.
*/

// Zoho Books webhooks: not token-authenticated; verified by shared secret / signature.
Route::post('/webhooks/zoho', ZohoWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('api.v1.webhooks.zoho');

Route::post('/login', LoginController::class)
    ->middleware('throttle:api-login')
    ->name('api.v1.login');

Route::middleware(['auth:sanctum', 'active', 'two-factor.enrolled'])->group(function () {
    Route::post('/logout', LogoutController::class)->name('api.v1.logout');
    Route::get('/profile', ProfileController::class)->name('api.v1.profile');

    // {organisation} is a uuid resolved inside the caller's own scope (404 otherwise).
    Route::get('/organisations', [OrganisationController::class, 'index'])->name('api.v1.organisations.index');
    Route::post('/organisations', [OrganisationController::class, 'store'])->name('api.v1.organisations.store');
    Route::get('/organisations/{organisation}', [OrganisationController::class, 'show'])->name('api.v1.organisations.show');
    Route::put('/organisations/{organisation}', [OrganisationController::class, 'update'])->name('api.v1.organisations.update');
    Route::get('/organisations/{organisation}/managers', [OrganisationController::class, 'managers'])->name('api.v1.organisations.managers.index');
    Route::post('/organisations/{organisation}/managers', [OrganisationController::class, 'assignManager'])->name('api.v1.organisations.managers.store');

    // {account} is a uuid resolved inside the caller's own scope (404 otherwise).
    Route::get('/accounts', [AccountController::class, 'index'])->name('api.v1.accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('api.v1.accounts.store');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('api.v1.accounts.show');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('api.v1.accounts.update');
    Route::post('/accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('api.v1.accounts.suspend');
    Route::post('/accounts/{account}/reactivate', [AccountController::class, 'reactivate'])->name('api.v1.accounts.reactivate');
    Route::post('/accounts/{account}/soft-delete', [AccountController::class, 'softDelete'])->name('api.v1.accounts.soft-delete');
    Route::post('/accounts/{account}/restore', [AccountController::class, 'restore'])->name('api.v1.accounts.restore');
    Route::post('/accounts/{account}/permanent-delete', [AccountController::class, 'permanentDelete'])->name('api.v1.accounts.permanent-delete');
    Route::get('/accounts/{account}/history', [AccountController::class, 'history'])->name('api.v1.accounts.history');

    // {domainRequest} is a uuid resolved inside the caller's own scope (404 otherwise).
    Route::get('/domains', [DomainController::class, 'index'])->name('api.v1.domains.index');
    Route::get('/domains/requests', [DomainController::class, 'requests'])->name('api.v1.domains.requests.index');
    Route::post('/domains/requests', [DomainController::class, 'store'])->name('api.v1.domains.requests.store');
    Route::post('/domains/requests/{domainRequest}/approve', [DomainController::class, 'approve'])->name('api.v1.domains.requests.approve');
    Route::post('/domains/requests/{domainRequest}/reject', [DomainController::class, 'reject'])->name('api.v1.domains.requests.reject');
    Route::post('/domains/requests/{domainRequest}/verify', [DomainController::class, 'verify'])->name('api.v1.domains.requests.verify');

    // Billing (read-only). {invoice} is a uuid resolved inside the caller's own scope.
    Route::get('/billing/summary', [BillingApiController::class, 'summary'])->name('api.v1.billing.summary');
    Route::get('/billing/pricing', [BillingApiController::class, 'pricing'])->name('api.v1.billing.pricing');
    Route::get('/invoices', [BillingApiController::class, 'invoices'])->name('api.v1.invoices.index');
    Route::get('/invoices/{invoice}', [BillingApiController::class, 'invoice'])->name('api.v1.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [ZohoIntegrationController::class, 'pdf'])->name('api.v1.invoices.pdf');
    Route::get('/payments', [BillingApiController::class, 'payments'])->name('api.v1.payments.index');

    Route::get('/integrations/status', [AccountController::class, 'integrationStatus'])->name('api.v1.integrations.status');
    Route::post('/integrations/google/test', [AccountController::class, 'testGoogle'])->name('api.v1.integrations.google.test');
    Route::post('/integrations/zoho/test', [ZohoApiController::class, 'test'])->name('api.v1.integrations.zoho.test');
    Route::post('/integrations/zoho/sync', [ZohoApiController::class, 'sync'])->name('api.v1.integrations.zoho.sync');
});

/*
| Storefront API (musicdesk.tech): server-to-server, "Authorization: Bearer
| sk_live_…". Error envelope { error: { code, message, field } }.
| See docs/LICENSING.md.
*/
Route::prefix('storefront')->middleware([AuthenticateStorefront::class, 'throttle:storefront'])->group(function () {
    Route::get('/catalog/{product}', [StorefrontApiController::class, 'catalog'])->name('api.storefront.catalog');
    Route::get('/products/{product}/releases', [StorefrontApiController::class, 'releases'])->name('api.storefront.releases');

    Route::post('/customers', [StorefrontApiController::class, 'storeCustomer'])->middleware(StorefrontIdempotency::class)->name('api.storefront.customers.store');
    Route::get('/customers/{customer}', [StorefrontApiController::class, 'customer'])->name('api.storefront.customers.show');
    Route::patch('/customers/{customer}', [StorefrontApiController::class, 'updateCustomer'])->name('api.storefront.customers.update');

    Route::post('/orders', [StorefrontApiController::class, 'storeOrder'])->middleware(StorefrontIdempotency::class)->name('api.storefront.orders.store');
    Route::post('/orders/{order}/confirm-payment', [StorefrontApiController::class, 'confirmPayment'])->name('api.storefront.orders.confirm-payment');
    Route::post('/orders/{order}/refund', [StorefrontApiController::class, 'refundOrder'])->name('api.storefront.orders.refund');

    Route::get('/licenses', [StorefrontApiController::class, 'licenses'])->name('api.storefront.licenses.index');
    Route::get('/licenses/{license}', [StorefrontApiController::class, 'license'])->name('api.storefront.licenses.show');
    Route::patch('/licenses/{license}', [StorefrontApiController::class, 'updateLicense'])->name('api.storefront.licenses.update');
    Route::post('/licenses/{license}/reveal-key', [StorefrontApiController::class, 'revealKey'])->name('api.storefront.licenses.reveal-key');
    Route::get('/licenses/{license}/events', [StorefrontApiController::class, 'events'])->name('api.storefront.licenses.events');
    Route::post('/licenses/{license}/downloads', [StorefrontApiController::class, 'download'])->name('api.storefront.licenses.downloads');
    Route::get('/licenses/{license}/activations', [StorefrontApiController::class, 'activations'])->name('api.storefront.activations.index');
    Route::post('/licenses/{license}/activations', [StorefrontApiController::class, 'addActivation'])->middleware(StorefrontIdempotency::class)->name('api.storefront.activations.store');
    Route::post('/licenses/{license}/activations/{activation}/move', [StorefrontApiController::class, 'moveActivation'])->middleware(StorefrontIdempotency::class)->name('api.storefront.activations.move');
    Route::delete('/licenses/{license}/activations/{activation}', [StorefrontApiController::class, 'deactivateActivation'])->name('api.storefront.activations.destroy');
    Route::post('/licenses/{license}/activations/{activation}/check-in-request', [StorefrontApiController::class, 'requestCheckIn'])->name('api.storefront.activations.check-in');
});
