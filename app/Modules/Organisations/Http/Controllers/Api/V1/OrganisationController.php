<?php

namespace App\Modules\Organisations\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\Organisations\Http\Requests\AssignMemberRequest;
use App\Modules\Organisations\Http\Requests\OrganisationRequest;
use App\Modules\Organisations\Http\Resources\MemberResource;
use App\Modules\Organisations\Http\Resources\OrganisationResource;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationMemberService;
use App\Modules\Organisations\Services\OrganisationQuery;
use App\Modules\Organisations\Services\OrganisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganisationController extends Controller
{
    public function index(Request $request, OrganisationQuery $query): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organisation::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return OrganisationResource::collection(
            $query->filtered($request->user(), $filters['search'] ?? null, $filters['status'] ?? null)
                ->paginate($filters['per_page'] ?? 25),
        );
    }

    public function store(OrganisationRequest $request, OrganisationService $organisations): JsonResponse
    {
        $organisation = $organisations->create($request->validated(), $request->user());

        return (new OrganisationResource($organisation))->response()->setStatusCode(201);
    }

    public function show(Organisation $organisation): OrganisationResource
    {
        Gate::authorize('view', $organisation);

        return new OrganisationResource($organisation);
    }

    public function update(OrganisationRequest $request, Organisation $organisation, OrganisationService $organisations): OrganisationResource
    {
        return new OrganisationResource(
            $organisations->update($organisation, $request->validated(), $request->user()),
        );
    }

    public function managers(Organisation $organisation, OrganisationMemberService $members): AnonymousResourceCollection
    {
        Gate::authorize('view', $organisation);

        return MemberResource::collection($members->members($organisation));
    }

    public function assignManager(AssignMemberRequest $request, Organisation $organisation, OrganisationMemberService $members): JsonResponse
    {
        $assignment = $members->assignByEmail(
            $organisation,
            $request->validated('email'),
            RoleName::from($request->validated('role')),
            $request->user(),
            $request->validated('name'),
        );

        $assignment->load(['user:id,uuid,name,email,is_active', 'role:id,name,label']);

        return (new MemberResource($assignment))->response()
            ->setStatusCode($assignment->wasRecentlyCreated ? 201 : 200);
    }
}
