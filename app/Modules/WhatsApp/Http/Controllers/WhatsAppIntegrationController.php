<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\WhatsApp\Contracts\WhatsAppClientInterface;
use App\Modules\WhatsApp\Exceptions\WhatsAppException;
use App\Modules\WhatsApp\Services\PhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppConnection;
use App\Modules\WhatsApp\Services\WhatsAppNotifier;
use App\Modules\Zoho\Models\IntegrationSyncLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Integrations -> WhatsApp (Super Admin). The keys can be replaced or
 * removed here but are never sent back to the browser.
 */
class WhatsAppIntegrationController extends Controller
{
    public function show(WhatsAppConnection $connection, WhatsAppClientInterface $client): View
    {
        Gate::authorize(Ability::IntegrationsManageWhatsApp->value);

        $row = $connection->settingRow();

        return view('integrations.whatsapp', [
            'settings' => $connection->settings(),
            'hasKeys' => $connection->hasKeys(),
            'mode' => $client->name() === 'whatsapp' ? 'live' : 'mock',
            'lastTested' => $row->last_tested_at,
            'events' => collect(WhatsAppConnection::defaultEvents())->mapWithKeys(fn (string $value) => [$value => NotificationType::from($value)->label()]),
            'recent' => IntegrationSyncLog::query()->whereIn('provider', ['whatsapp', 'mock-whatsapp'])->latest('id')->limit(15)->get(),
        ]);
    }

    public function saveSettings(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageWhatsApp->value);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', WhatsAppConnection::defaultEvents())],
        ]);

        $connection->saveSettings((bool) ($data['enabled'] ?? false), $data['events'] ?? [], $request->user());

        return back()->with('success', 'WhatsApp settings saved.');
    }

    public function saveKeys(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageWhatsApp->value);

        $data = $request->validateWithBag('keys', [
            'appkey' => ['nullable', 'string', 'min:8', 'max:200', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'authkey' => ['nullable', 'string', 'min:8', 'max:200', 'regex:/^[A-Za-z0-9\-_.]+$/'],
        ]);

        $changed = $connection->saveKeys($data['appkey'] ?? null, $data['authkey'] ?? null, $request->user());

        return back()->with($changed ? 'success' : 'warning', $changed ? 'WhatsApp keys saved.' : 'Enter a new app key and/or auth key to replace the stored ones.');
    }

    public function clearKeys(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageWhatsApp->value);

        $connection->clearKeys($request->user());

        return back()->with('success', 'WhatsApp keys removed. No WhatsApp messages are sent until new keys are saved.');
    }

    /** Sends one message straight away (not queued) so the result is shown at once. */
    public function test(Request $request, WhatsAppConnection $connection, WhatsAppClientInterface $client, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageWhatsApp->value);

        $data = $request->validateWithBag('test', ['to' => ['required', 'string', 'max:32']]);
        $to = PhoneNumber::normalise($data['to']);

        if ($to === null) {
            return back()->withErrors(['to' => 'Enter a mobile number with country code, e.g. 919876543210.'], 'test');
        }

        if (! $connection->hasKeys()) {
            return back()->withErrors(['to' => 'Save the app key and auth key first.'], 'test');
        }

        try {
            $client->send($to, WhatsAppNotifier::text('Test message', 'WhatsApp notifications from '.config('app.name').' are working.', null, null));
        } catch (WhatsAppException $e) {
            return back()->withErrors(['to' => $e->getMessage()], 'test');
        }

        $connection->markTested();
        $audit->record('integration.whatsapp.tested', $connection->settingRow(), actor: $request->user());

        return back()->with('success', 'Test message sent to '.$to.($client->name() === 'whatsapp' ? '.' : ' (mock — nothing was really sent).'));
    }
}
