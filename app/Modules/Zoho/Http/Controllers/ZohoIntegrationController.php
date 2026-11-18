<?php

namespace App\Modules\Zoho\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\Money;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Clients\MockZohoBooksClient;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Exceptions\ZohoException;
use App\Modules\Zoho\Jobs\ReconcileZohoPaymentJob;
use App\Modules\Zoho\Models\IntegrationSyncLog;
use App\Modules\Zoho\Models\WebhookEvent;
use App\Modules\Zoho\Models\ZohoMapping;
use App\Modules\Zoho\Services\ZohoConnection;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZohoIntegrationController extends Controller
{
    public function show(ZohoConnection $connection, ZohoSync $sync): View
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $settings = $connection->settings();

        return view('integrations.zoho', [
            'mode' => $sync->mode(),
            'connected' => $connection->isConnected(),
            'settings' => $settings,
            'checks' => [
                ['ZOHO_ENABLED', (bool) config('wcc.zoho.enabled'), config('wcc.zoho.enabled') ? 'Enabled' : 'Disabled — the mock is used'],
                ['ZOHO_CLIENT_ID / ZOHO_CLIENT_SECRET', filled(config('wcc.zoho.client_id')) && filled(config('wcc.zoho.client_secret')), filled(config('wcc.zoho.client_id')) ? 'Set' : 'Not set'],
                ['ZOHO_REDIRECT_URI', filled(config('wcc.zoho.redirect_uri')), (string) config('wcc.zoho.redirect_uri')],
                ['Accounts server', filled(config('wcc.zoho.accounts_base_url')), (string) ($settings['accounts_url'] ?? config('wcc.zoho.accounts_base_url'))],
                ['Webhook secret (ZOHO_WEBHOOK_SECRET)', filled(config('wcc.zoho.webhook_secret')), filled(config('wcc.zoho.webhook_secret')) ? 'Set' : 'Not set — webhooks are refused'],
                ['Connected to Zoho', $connection->isConnected(), $connection->isConnected() ? 'Since '.($settings['connected_at'] ?? '—') : 'Not connected'],
                ['Organisation id', $connection->organisationId() !== '', $connection->organisationId() ?: 'Not set'],
                ['GST 18% tax id', filled($settings['tax_id'] ?? null), filled($settings['tax_id'] ?? null) ? 'Set' : 'Not set'],
            ],
            'problems' => ZohoMapping::query()->whereIn('sync_status', [ZohoMapping::FAILED, ZohoMapping::MISMATCH, ZohoMapping::NEEDS_ATTENTION])
                ->where('mappable_type', (new Invoice)->getMorphClass())->latest('updated_at')->limit(50)->get()
                ->map(fn (ZohoMapping $mapping) => ['mapping' => $mapping, 'invoice' => Invoice::query()->with('organisation:id,name')->find($mapping->mappable_id)]),
            'webhooks' => WebhookEvent::query()->latest('id')->limit(10)->get(),
            'webhookUrl' => url('/api/v1/webhooks/zoho'),
            'logs' => IntegrationSyncLog::query()->latest('id')->limit(15)->get(),
        ]);
    }

    public function saveSettings(Request $request, ZohoConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $data = $request->validate([
            'organization_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'item_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'tax_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'deposit_account_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
        ], ['*.regex' => 'Zoho ids are numbers only.']);

        $connection->saveSettings($data, $request->user());

        return back()->with('success', 'Zoho Books settings saved.');
    }

    public function connect(Request $request, ZohoConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $state = Str::random(40);
        $request->session()->put('zoho_oauth_state', $state);

        try {
            return redirect()->away($connection->authorizationUrl($state));
        } catch (ZohoException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Zoho redirects here after consent. Protected by the same login, 2FA and permission as the page. */
    public function callback(Request $request, ZohoConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $expected = $request->session()->pull('zoho_oauth_state');

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('integrations.zoho')->with('error', 'The Zoho connection could not be verified (state mismatch). Please try again.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('integrations.zoho')->with('error', 'Zoho did not grant access: '.Str::limit((string) $request->query('error', 'no code returned'), 100));
        }

        try {
            $connection->connect((string) $request->query('code'), $request->query('accounts-server'), $request->user());
        } catch (Throwable $e) {
            return redirect()->route('integrations.zoho')->with('error', 'Connecting to Zoho Books failed: '.$e->getMessage());
        }

        return redirect()->route('integrations.zoho')->with('success', 'Zoho Books connected. Now set the organisation id and the GST tax id, then test the connection.');
    }

    public function disconnect(Request $request, ZohoConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $connection->disconnect($request->user());

        return back()->with('success', 'Zoho Books disconnected.');
    }

    public function test(Request $request, ZohoBooksClientInterface $client, ZohoConnection $connection, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        try {
            $organisations = $client->organizations();
            $configured = $connection->organisationId();
            $found = collect($organisations)->firstWhere('organization_id', $configured);

            $ok = $client->name() === 'mock' || $found !== null;
            $message = match (true) {
                $client->name() === 'mock' => 'The mock responded. This does not test Zoho Books.',
                $found !== null => 'Connected to Zoho Books organisation "'.$found['name'].'".',
                default => 'Connected, but organisation id '.($configured ?: '(not set)').' was not found. Available: '
                    .collect($organisations)->map(fn ($org) => $org['name'].' = '.$org['organization_id'])->implode(', '),
            };
        } catch (Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        $row = $connection->settingRow();
        $row->forceFill(['last_tested_at' => now(), 'last_test_status' => $ok ? 'success' : 'failure',
            'last_test_message' => Str::limit($message, 495), 'updated_by' => $request->user()->getKey()])->save();
        $audit->record('integration.zoho.tested', new: ['result' => $ok ? 'success' : 'failure'],
            status: $ok ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE, actor: $request->user());

        return back()->with($ok ? 'success' : 'error', $message);
    }

    public function sync(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        ReconcileZohoPaymentJob::dispatch($request->user()->getKey());
        $audit->record('zoho.sync_requested', actor: $request->user());

        return back()->with('success', 'Zoho Books synchronisation started. Refresh to see the result.');
    }

    /** Customers already in Zoho Books, to link to organisations here instead of creating duplicates. */
    public function customers(Request $request, ZohoSync $sync): View
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $page = max(1, min(500, $request->integer('page', 1)));
        $search = Str::limit(trim($request->string('search')->toString()), 100, '');
        $error = null;
        $result = ['contacts' => [], 'has_more' => false];

        try {
            $result = $sync->listCustomers($page, $search !== '' ? $search : null);
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        $type = (new Organisation)->getMorphClass();

        return view('integrations.zoho-customers', [
            'mode' => $sync->mode(),
            'contacts' => $result['contacts'],
            'hasMore' => $result['has_more'],
            'page' => $page,
            'search' => $search,
            'error' => $error,
            // Organisations that may be linked (and updated) by this user, with their current link.
            'organisations' => Organisation::query()->visibleTo($request->user(), Ability::OrganisationsUpdate)->orderBy('name')->get(['id', 'uuid', 'name', 'code'])
                ->map(fn (Organisation $organisation) => [
                    'organisation' => $organisation,
                    'zoho_id' => ZohoMapping::query()->where('mappable_type', $type)->where('mappable_id', $organisation->id)->value('zoho_id'),
                ]),
        ]);
    }

    public function linkCustomer(Request $request, string $zohoContactId, ZohoSync $sync): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        $data = $request->validate([
            'organisation' => ['required', 'uuid'],
            'import_details' => ['nullable', 'boolean'],
        ]);

        // Resolved inside the caller's own scope; anything else does not exist.
        $organisation = Organisation::query()->visibleTo($request->user(), Ability::OrganisationsUpdate)
            ->where('uuid', $data['organisation'])->firstOrFail();
        Gate::authorize('update', $organisation);

        try {
            $mapping = $sync->linkCustomer($organisation, $zohoContactId, $request->user(), (bool) ($data['import_details'] ?? false));
        } catch (ZohoException $e) {
            return back()->with('error', 'Zoho Books: '.$e->getMessage());
        }

        return back()->with('success', "{$organisation->name} is now linked to the Zoho customer \"{$mapping->zoho_number}\". Its invoices will be sent to that customer"
            .(($data['import_details'] ?? false) ? ', and its GST, PAN, address and contact details were copied from Zoho.' : '.'));
    }

    public function importCustomer(Request $request, Organisation $organisation, ZohoSync $sync): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);
        Gate::authorize('update', $organisation);

        try {
            $changed = $sync->importCustomerDetails($organisation, $request->user());
        } catch (ZohoException $e) {
            return back()->with('error', 'Zoho Books: '.$e->getMessage());
        }

        return back()->with('success', $changed === []
            ? "{$organisation->name} already matches Zoho Books."
            : "{$organisation->name} updated from Zoho Books: ".implode(', ', array_map(fn (string $field) => str_replace('_', ' ', $field), $changed)).'.');
    }

    /**
     * Mock mode only: pretend the customer paid in Zoho, so the payment
     * flow can be tried end to end. Unavailable when Zoho is live.
     */
    public function simulatePayment(Request $request, Invoice $invoice, ZohoSync $sync, MockZohoBooksClient $mock): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);
        abort_unless($sync->mode() === 'mock' && ! app()->isProduction(), 404);

        $mapping = $sync->mapping($invoice);
        abort_if($mapping?->zoho_id === null || $invoice->balance_minor <= 0, 422, 'The invoice is not in (mock) Zoho Books or is already paid.');

        $mock->simulatePayment($mapping->zoho_id, Money::decimal($invoice->balance_minor), now()->toDateString(), 'online', 'MOCK-ONLINE');
        $sync->reconcileOrganisation($invoice->organisation);

        return back()->with('success', 'Simulated an online payment in the mock Zoho Books and reconciled it.');
    }

    /** The invoice PDF as rendered by Zoho Books (the accounting document). */
    public function pdf(Invoice $invoice, ZohoSync $sync, ZohoBooksClientInterface $client): Response
    {
        Gate::authorize('invoice.view', $invoice);

        $zohoId = $sync->mapping($invoice)?->zoho_id;
        abort_if($zohoId === null, 404, 'The PDF is available once the invoice has been sent to Zoho Books.');

        return response($client->invoicePdf($zohoId), 200, [
            'Content-Type' => 'application/pdf',
            // "26/TWSD-001" is not a valid file name.
            'Content-Disposition' => 'inline; filename="'.str_replace(['/', '\\'], '-', (string) $invoice->invoice_number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
