<?php

namespace App\Modules\Billing;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Invoice abilities, evaluated in the invoice's own organisation.
        Gate::define('invoice.view', fn (User $user, Invoice $invoice) => $user->hasAbility(Ability::InvoicesView, $invoice->organisation)
            && ($invoice->status !== Invoice::DRAFT || $user->hasAbility(Ability::InvoicesManage, $invoice->organisation)));

        Gate::define('invoice.manage', fn (User $user, Invoice $invoice) => $user->hasAbility(Ability::InvoicesManage, $invoice->organisation));

        // Tenant-scoped binding. Another organisation's invoice — and a
        // draft the user may not see — is a 404, like one that does not exist.
        Route::bind('invoice', function (string $uuid) {
            $user = request()->user() ?? abort(401);

            $invoice = Invoice::query()->visibleTo($user, Ability::InvoicesView)->where('uuid', $uuid)->firstOrFail();

            abort_unless($user->can('invoice.view', $invoice), 404);

            return $invoice;
        });
    }
}
