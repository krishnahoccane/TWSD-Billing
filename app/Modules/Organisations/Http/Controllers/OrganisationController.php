<?php

namespace App\Modules\Organisations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\GoogleWorkspace\Enums\BillingStatus;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Licensing\Models\SoftwareLicense;
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

        $user = request()->user();
        $canViewAccounts = $user->hasAbility(Ability::AccountsView, $organisation);

        return view('organisations.show', [
            'organisation' => $organisation,
            // Software licenses (LabelDesk), for those who may see them in this organisation.
            'licenses' => auth()->user()->hasAbility(Ability::LicensingView, $organisation)
                ? SoftwareLicense::query()->where('organisation_id', $organisation->getKey())->with(['plan:id,name', 'product:id,name,key_prefix'])->orderByDesc('id')->limit(20)->get()
                : null,
            // Billable mailboxes (what this organisation is charged for), shown to those who may see its accounts.
            'billableAccounts' => $canViewAccounts
                ? GoogleAccount::query()->where('organisation_id', $organisation->getKey())
                    ->where('billing_status', BillingStatus::Billable)->count()
                : null,
            'billableByDomain' => $canViewAccounts
                ? GoogleAccount::query()->where('organisation_id', $organisation->getKey())
                    ->where('billing_status', BillingStatus::Billable)
                    ->selectRaw('domain_id, count(*) as total')->groupBy('domain_id')->pluck('total', 'domain_id')->all()
                : [],
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
