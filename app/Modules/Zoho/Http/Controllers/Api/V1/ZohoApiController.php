<?php

namespace App\Modules\Zoho\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Zoho\Contracts\ZohoBooksClientInterface;
use App\Modules\Zoho\Jobs\ReconcileZohoPaymentJob;
use App\Modules\Zoho\Services\ZohoConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ZohoApiController extends Controller
{
    public function test(Request $request, ZohoBooksClientInterface $client, ZohoConnection $connection, AuditLogger $audit): JsonResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        try {
            $organisations = $client->organizations();
            $ok = $client->name() === 'mock' || collect($organisations)->contains('organization_id', $connection->organisationId());
            $message = $client->name() === 'mock' ? 'The mock responded. This does not test Zoho Books.'
                : ($ok ? 'Connected.' : 'Connected, but the configured organisation id was not found.');
        } catch (Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        $audit->record('integration.zoho.tested', new: ['result' => $ok ? 'success' : 'failure'],
            status: $ok ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE, actor: $request->user());

        return response()->json(['data' => ['ok' => $ok, 'mode' => $client->name() === 'mock' ? 'mock' : 'live', 'message' => $message]]);
    }

    public function sync(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize(Ability::IntegrationsManageZoho->value);

        ReconcileZohoPaymentJob::dispatch($request->user()->getKey());
        $audit->record('zoho.sync_requested', actor: $request->user());

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
