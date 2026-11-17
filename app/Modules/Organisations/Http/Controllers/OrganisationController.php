<?php

namespace App\Modules\Organisations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Http\Requests\ChangeOrganisationStatusRequest;
use App\Modules\Organisations\Http\Requests\OrganisationRequest;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use App\Modules\Organisations\Services\OrganisationExporter;
use App\Modules\Organisations\Services\OrganisationMemberService;
use App\Modules\Organisations\Services\OrganisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganisationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Organisation::class);

        return view('organisations.index');
    }

    public function create(): View
    {
        Gate::authorize('create', Organisation::class);

        return view('organisations.create', ['organisation' => new Organisation]);
    }

    public function store(OrganisationRequest $request, OrganisationService $organisations): RedirectResponse
    {
        $organisation = $organisations->create($request->validated(), $request->user());

        return redirect()->route('organisations.show', $organisation)
            ->with('success', "{$organisation->name} was created.");
    }

    public function show(Organisation $organisation, OrganisationMemberService $members): View
    {
        Gate::authorize('view', $organisation);

        $organisation->load(['domains' => fn ($query) => $query->orderBy('domain_name'), 'contacts' => fn ($query) => $query->orderBy('name')]);

        return view('organisations.show', [
            'organisation' => $organisation,
            'members' => $members->members($organisation),
            'assignableRoles' => OrganisationMemberService::assignableRoles(),
        ]);
    }

    /**
     * Organisation-side "Organisation Profile" / "Managers": the same page
     * for the organisation the user is currently working in.
     */
    public function current(Request $request, OrganisationContext $context, OrganisationMemberService $members): View
    {
        $organisation = $context->current($request->user());

        abort_if($organisation === null, 404);

        return $this->show($organisation, $members);
    }

    public function edit(Organisation $organisation): View
    {
        Gate::authorize('update', $organisation);

        return view('organisations.edit', ['organisation' => $organisation]);
    }

    public function update(OrganisationRequest $request, Organisation $organisation, OrganisationService $organisations): RedirectResponse
    {
        $organisations->update($organisation, $request->validated(), $request->user());

        return redirect()->route('organisations.show', $organisation)->with('success', 'Organisation updated.');
    }

    public function changeStatus(
        ChangeOrganisationStatusRequest $request,
        Organisation $organisation,
        OrganisationService $organisations,
    ): RedirectResponse {
        $status = OrganisationStatus::from($request->validated('status'));

        $organisations->changeStatus($organisation, $status, $request->validated('reason'), $request->user());

        return redirect()->route('organisations.show', $organisation)
            ->with('success', "Organisation is now {$status->value}.");
    }

    public function export(Request $request, OrganisationExporter $exporter): StreamedResponse
    {
        Gate::authorize('viewAny', Organisation::class);

        return $exporter->download(
            $request->user(),
            $request->string('search')->toString() ?: null,
            $request->string('status')->toString() ?: null,
        );
    }
}
