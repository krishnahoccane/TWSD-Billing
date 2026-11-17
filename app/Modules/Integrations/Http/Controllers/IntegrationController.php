<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Models\GoogleApiLog;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use App\Modules\Zoho\Models\IntegrationSyncLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    /**
     * Configuration status only. Credentials are supplied through the
     * server environment and are never displayed, entered or stored here.
     */
    public function google(GoogleIntegrationStatus $status): View
    {
        Gate::authorize(Ability::IntegrationsManageGoogle->value);

        return view('integrations.google', [
            'mode' => $status->mode(),
            'checks' => $status->checks(),
            'lastTest' => $status->lastTest(),
        ]);
    }

    public function testGoogle(Request $request, GoogleIntegrationStatus $status): RedirectResponse
    {
        Gate::authorize(Ability::IntegrationsManageGoogle->value);

        $result = $status->test($request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function logs(Request $request): View
    {
        Gate::authorize(Ability::IntegrationsViewLogs->value);

        $status = in_array($request->query('status'), ['success', 'failure'], true) ? $request->query('status') : null;

        return view('integrations.logs', [
            'logs' => GoogleApiLog::query()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'status' => $status,
            'zohoLogs' => IntegrationSyncLog::query()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('id')->limit(50)->get(),
        ]);
    }
}
