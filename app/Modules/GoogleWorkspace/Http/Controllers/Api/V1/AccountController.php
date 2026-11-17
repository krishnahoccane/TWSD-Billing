<?php

namespace App\Modules\GoogleWorkspace\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Http\Requests\StoreAccountRequest;
use App\Modules\GoogleWorkspace\Http\Requests\UpdateAccountRequest;
use App\Modules\GoogleWorkspace\Http\Resources\AccountResource;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountService;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Zoho\Services\ZohoConnection;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Mutating endpoints answer 202: the change is recorded and queued, and
 * `status` / `pending_operation` show its progress.
 */
class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', GoogleAccount::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'organisation' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = GoogleAccount::query()->visibleTo($user, Ability::AccountsView)->with('organisation:id,uuid');

        if (isset($filters['organisation'])) {
            // Resolved inside the caller's scope; a foreign uuid matches nothing.
            $organisation = Organisation::query()->visibleTo($user, Ability::AccountsView)->where('uuid', $filters['organisation'])->first();
            $query->where('organisation_id', $organisation?->getKey() ?? 0);
        }

        if (isset($filters['status'])) {
            $query->where('account_status', $filters['status']);
        }

        if (filled($filters['search'] ?? null)) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner->where('primary_email', 'like', $like)
                ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }

        return AccountResource::collection($query->orderBy('primary_email')->paginate($filters['per_page'] ?? 25));
    }

    public function store(StoreAccountRequest $request, GoogleAccountService $accounts): JsonResponse
    {
        $account = $accounts->requestCreation($request->organisation(), $request->domain(), $request->validated(), $request->user());

        return $this->accepted($account);
    }

    public function show(GoogleAccount $account): AccountResource
    {
        Gate::authorize('view', $account);

        return new AccountResource($account->load('organisation:id,uuid'));
    }

    public function update(UpdateAccountRequest $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        $accounts->requestUpdate($account, $request->validated(), $request->user());

        return $this->accepted($account);
    }

    public function suspend(Request $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        Gate::authorize('suspend', $account);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $accounts->requestSuspension($account, $data['reason'], $request->user());

        return $this->accepted($account);
    }

    public function reactivate(Request $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        Gate::authorize('reactivate', $account);

        $accounts->requestReactivation($account, $request->user());

        return $this->accepted($account);
    }

    public function softDelete(Request $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        Gate::authorize('softDelete', $account);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm_email' => ['required', 'string', 'in:'.$account->primary_email],
        ]);

        $accounts->requestSoftDeletion($account, $data['reason'], $request->user());

        return $this->accepted($account);
    }

    public function restore(Request $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        Gate::authorize('restore', $account);

        $accounts->requestRestore($account, $request->user());

        return $this->accepted($account);
    }

    /**
     * Two calls by two different Super Admins:
     *  1. no open request  -> records the request and its checks (nothing is deleted);
     *  2. an open request  -> approves it (`approve: true` + `confirm_email`) and queues the deletion.
     */
    public function permanentDelete(Request $request, GoogleAccount $account, GoogleAccountService $accounts): JsonResponse
    {
        $open = $accounts->openDeletionRequest($account);

        if ($open === null) {
            Gate::authorize('requestPermanentDeletion', $account);

            $data = $request->validate([
                'reason' => ['required', 'string', 'min:3', 'max:500'],
                'ownership_verified' => ['accepted'],
                'retention_checked' => ['accepted'],
                'vault_checked' => ['accepted'],
                'dependencies_checked' => ['accepted'],
            ]);

            $deletion = $accounts->requestPermanentDeletion($account, $data['reason'], [
                'ownership_verified' => true, 'retention_checked' => true, 'vault_checked' => true, 'dependencies_checked' => true,
            ], $request->user());

            return response()->json(['data' => ['deletion_request' => $deletion->uuid, 'status' => $deletion->status]], 202);
        }

        Gate::authorize('approvePermanentDeletion', $account);

        $request->validate([
            'approve' => ['accepted'],
            'confirm_email' => ['required', 'string', 'in:'.$account->primary_email],
        ]);

        $accounts->approvePermanentDeletion($open, $request->user());

        return response()->json(['data' => ['deletion_request' => $open->uuid, 'status' => $open->fresh()->status]], 202);
    }

    public function history(GoogleAccount $account): JsonResponse
    {
        Gate::authorize('view', $account);

        return response()->json([
            'data' => $account->history()->with('user:id,uuid,name')->limit(200)->get()->map(fn ($entry) => [
                'event' => $entry->event,
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'details' => $entry->details,
                'by' => $entry->user ? ['id' => $entry->user->uuid, 'name' => $entry->user->name] : null,
                'at' => $entry->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function integrationStatus(GoogleIntegrationStatus $status): JsonResponse
    {
        Gate::authorize(Ability::IntegrationsManageGoogle->value);

        $last = $status->lastTest();

        return response()->json(['data' => [
            'google_workspace' => [
                'mode' => $status->mode(),
                'configured' => collect($status->checks())->every(fn (array $check) => $check['ok']),
                'last_test_status' => $last?->last_test_status,
                'last_tested_at' => $last?->last_tested_at?->toIso8601String(),
            ],
            'zoho_books' => [
                'mode' => app(ZohoSync::class)->mode(),
                'connected' => app(ZohoConnection::class)->isConnected(),
            ],
        ]]);
    }

    public function testGoogle(Request $request, GoogleIntegrationStatus $status): JsonResponse
    {
        Gate::authorize(Ability::IntegrationsManageGoogle->value);

        return response()->json(['data' => $status->test($request->user())]);
    }

    private function accepted(GoogleAccount $account): JsonResponse
    {
        return (new AccountResource($account->fresh()->load('organisation:id,uuid')))->response()->setStatusCode(202);
    }
}
