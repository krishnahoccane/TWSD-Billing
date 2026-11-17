<?php

namespace App\Modules\Organisations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\OrganisationUserRole;
use App\Modules\Organisations\Http\Requests\AssignMemberRequest;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationMemberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganisationMemberController extends Controller
{
    /**
     * Platform-wide list of every organisation role assignment.
     */
    public function index(Request $request): View
    {
        Gate::authorize(Ability::OrganisationsManageManagers->value);

        $search = trim($request->string('search')->toString());
        $scope = $request->user()->organisationScope(Ability::OrganisationsManageManagers);

        $assignments = OrganisationUserRole::query()
            ->with(['user:id,uuid,name,email,is_active', 'organisation:id,uuid,name,code,status', 'role:id,name,label'])
            ->whereHas('organisation')
            ->when(! $scope->all, fn (Builder $query) => $query->whereIn('organisation_id', $scope->ids))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $inner) => $inner
                    ->whereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('organisation', fn (Builder $organisation) => $organisation->where('name', 'like', $like)));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('managers.index', ['assignments' => $assignments, 'search' => $search]);
    }

    public function store(AssignMemberRequest $request, Organisation $organisation, OrganisationMemberService $members): RedirectResponse
    {
        $assignment = $members->assignByEmail(
            $organisation,
            $request->validated('email'),
            RoleName::from($request->validated('role')),
            $request->user(),
            $request->validated('name'),
        );

        return redirect()->to(route('organisations.show', $organisation).'#members')
            ->with('success', $assignment->wasRecentlyCreated ? 'Access granted.' : 'That user already holds this role here.');
    }

    public function destroy(Request $request, Organisation $organisation, User $user, OrganisationMemberService $members): RedirectResponse
    {
        Gate::authorize('manageMembers', $organisation);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(OrganisationMemberService::assignableRoles()))],
        ]);

        $members->revoke($organisation, $user, RoleName::from($data['role']), $request->user());

        return redirect()->to(route('organisations.show', $organisation).'#members')->with('success', 'Access removed.');
    }
}
