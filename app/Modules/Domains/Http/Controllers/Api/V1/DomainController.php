<?php

namespace App\Modules\Domains\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Domains\Services\DomainMappingService;
use App\Modules\Domains\Services\DomainRequestService;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DomainRequest::class);

        $domains = OrganisationDomain::query()
            ->visibleTo($request->user(), Ability::DomainsView)
            ->with('organisation:id,uuid')
            ->orderBy('domain_name')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $domains->getCollection()->map(fn (OrganisationDomain $domain) => [
                'id' => $domain->uuid,
                'organisation_id' => $domain->organisation?->uuid,
                'domain_name' => $domain->domain_name,
                'domain_type' => $domain->domain_type->value,
                'verification_status' => $domain->verification_status,
                'is_primary' => $domain->is_primary,
                'status' => $domain->status,
            ]),
            'meta' => ['current_page' => $domains->currentPage(), 'last_page' => $domains->lastPage(), 'total' => $domains->total()],
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DomainRequest::class);

        $filters = $request->validate(['status' => ['nullable', Rule::enum(DomainRequestStatus::class)]]);

        $requests = DomainRequest::query()
            ->visibleTo($request->user(), Ability::DomainsView)
            ->with('organisation:id,uuid')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $requests->getCollection()->map(fn (DomainRequest $item) => $this->resource($item)),
            'meta' => ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()],
        ]);
    }

    public function store(Request $request, DomainRequestService $requests): JsonResponse
    {
        $organisation = Organisation::query()
            ->visibleTo($request->user(), Ability::DomainsRequest)
            ->where('uuid', (string) $request->input('organisation'))
            ->firstOrFail();

        Gate::authorize('create', [DomainRequest::class, $organisation]);

        $request->merge(['domain_name' => DomainMappingService::normalise((string) $request->input('domain_name'))]);

        $data = $request->validate([
            'organisation' => ['required', 'uuid'],
            'domain_name' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $created = $requests->submit($organisation, $data['domain_name'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->resource($created->load('organisation:id,uuid'))], 201);
    }

    public function approve(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): JsonResponse
    {
        Gate::authorize('decide', $domainRequest);

        $rules = ['note' => ['nullable', 'string', 'max:500']];

        if ($domainRequest->isRemoval()) {
            $rules['confirm_domain'] = ['required', 'string', 'in:'.$domainRequest->domain_name];
        }

        $data = $request->validate($rules);
        $requests->approve($domainRequest, $data['note'] ?? null, $request->user());

        return $this->fresh($domainRequest);
    }

    public function reject(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): JsonResponse
    {
        Gate::authorize('decide', $domainRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $requests->reject($domainRequest, $data['reason'], $request->user());

        return $this->fresh($domainRequest);
    }

    public function verify(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): JsonResponse
    {
        Gate::authorize('process', $domainRequest);

        $requests->requestVerification($domainRequest, $request->user());

        return $this->fresh($domainRequest, 202);
    }

    private function fresh(DomainRequest $domainRequest, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->resource($domainRequest->fresh()->load('organisation:id,uuid'))], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(DomainRequest $item): array
    {
        return [
            'id' => $item->uuid,
            'organisation_id' => $item->organisation?->uuid,
            'type' => $item->type,
            'domain_name' => $item->domain_name,
            'status' => $item->status->value,
            'verification' => $item->verification_token === null ? null : [
                'method' => 'dns_txt',
                'record_type' => 'TXT',
                'host' => $item->verificationHost(),
                'value' => $item->verification_token,
            ],
            'last_error' => $item->last_error,
            'requested_at' => $item->created_at?->toIso8601String(),
            'decided_at' => $item->decided_at?->toIso8601String(),
            'verified_at' => $item->verified_at?->toIso8601String(),
            'completed_at' => $item->completed_at?->toIso8601String(),
        ];
    }
}
