<?php

namespace App\Modules\Domains\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Domains\Enums\DomainRequestStatus;
use App\Modules\Domains\Models\DomainHistory;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Domains\Services\DomainMappingService;
use App\Modules\Domains\Services\DomainRequestService;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DomainController extends Controller
{
    /** All domains / My domains. */
    public function index(Request $request, OrganisationContext $context): View
    {
        Gate::authorize('viewAny', DomainRequest::class);

        $user = $request->user();
        $current = $context->current($user);
        $search = trim($request->string('search')->toString());

        $domains = OrganisationDomain::query()
            ->visibleTo($user, Ability::DomainsView)
            ->with('organisation:id,uuid,name')
            ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
            ->when($search !== '', fn (Builder $query) => $query->where('domain_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('domain_name')
            ->paginate(25)
            ->withQueryString();

        return view('domains.index', [
            'domains' => $domains,
            'search' => $search,
            'showOrganisation' => $current === null,
            'canRequest' => $user->hasAbilityAnywhere(Ability::DomainsRequest),
        ]);
    }

    public function show(Request $request, OrganisationDomain $domain, DomainRequestService $requests): View
    {
        Gate::authorize('viewDomains', $domain->organisation);

        return view('domains.show', [
            'domain' => $domain->load('organisation:id,uuid,name'),
            'accounts' => $requests->accountsOn($domain->domain_name),
            'openRequest' => $requests->openRequestFor($domain->domain_name),
            'history' => DomainHistory::query()->where('domain_name', $domain->domain_name)
                ->where('organisation_id', $domain->organisation_id)->with('user:id,name')->latest('id')->limit(50)->get(),
            'canRemove' => $request->user()->hasAbility(Ability::DomainsRemove, $domain->organisation),
        ]);
    }

    /** Requests: all, awaiting approval, or in verification — chosen by the route. */
    public function requests(Request $request, OrganisationContext $context, GoogleIntegrationStatus $integration): View
    {
        Gate::authorize('viewAny', DomainRequest::class);

        $user = $request->user();
        $filter = $request->route()->defaults['filter'] ?? 'all';

        // The two work queues are for the people who can act on them.
        $ability = match ($filter) {
            'pending' => Ability::DomainsApprove,
            'verification' => Ability::DomainsProcess,
            default => Ability::DomainsView,
        };

        abort_unless($user->hasAbilityAnywhere($ability), 403);

        $current = $context->current($user);

        $requests = DomainRequest::query()
            ->visibleTo($user, $ability)
            ->with(['organisation:id,uuid,name', 'requester:id,name'])
            ->when($current, fn (Builder $query) => $query->where('organisation_id', $current->getKey()))
            ->when($filter === 'pending', fn (Builder $query) => $query->where('status', DomainRequestStatus::Pending))
            ->when($filter === 'verification', fn (Builder $query) => $query->whereIn('status', [
                DomainRequestStatus::Approved, DomainRequestStatus::VerificationPending, DomainRequestStatus::Verifying,
                DomainRequestStatus::Verified, DomainRequestStatus::Failed,
            ]))
            ->latest('id')
            ->paginate(25);

        return view('domains.requests.index', [
            'requests' => $requests,
            'filter' => $filter,
            'showOrganisation' => $current === null,
            'canRequest' => $user->hasAbilityAnywhere(Ability::DomainsRequest),
            'mode' => $integration->mode(),
        ]);
    }

    public function create(Request $request, OrganisationContext $context): View
    {
        $organisations = Organisation::query()->visibleTo($request->user(), Ability::DomainsRequest)->orderBy('name')->get(['id', 'uuid', 'name']);

        abort_if($organisations->isEmpty(), 403);

        return view('domains.requests.create', [
            'organisations' => $organisations->pluck('name', 'uuid')->all(),
            'selected' => $context->current($request->user())?->uuid ?? $organisations->first()->uuid,
        ]);
    }

    public function store(Request $request, DomainRequestService $requests): RedirectResponse
    {
        // The organisation uuid is a selector resolved inside the caller's
        // own scope for this ability; anything else does not exist (404).
        $organisation = Organisation::query()
            ->visibleTo($request->user(), Ability::DomainsRequest)
            ->where('uuid', (string) $request->input('organisation'))
            ->firstOrFail();

        Gate::authorize('create', [DomainRequest::class, $organisation]);

        $data = $this->validateDomain($request);

        $domainRequest = $requests->submit($organisation, $data['domain_name'], $data['note'] ?? null, $request->user());

        return redirect()->route('domains.requests.show', $domainRequest)
            ->with('success', 'Request submitted. A Super Admin will review it.');
    }

    public function showRequest(Request $request, DomainRequest $domainRequest, GoogleIntegrationStatus $integration): View
    {
        Gate::authorize('view', $domainRequest);

        $user = $request->user();

        return view('domains.requests.show', [
            'domainRequest' => $domainRequest->load([
                'organisation:id,uuid,name', 'requester:id,name,email', 'decider:id,name',
                'verificationChecks.checker:id,name', 'history.user:id,name',
            ]),
            'can' => [
                'decide' => $user->can('decide', $domainRequest),
                'process' => $user->can('process', $domainRequest),
                'cancel' => $user->can('cancel', $domainRequest),
            ],
            'mode' => $integration->mode(),
        ]);
    }

    public function approve(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('decide', $domainRequest);

        $rules = ['note' => ['nullable', 'string', 'max:500']];

        if ($domainRequest->isRemoval()) {
            // Typed confirmation for the destructive path, enforced on the server.
            $rules['confirm_domain'] = ['required', 'string', 'in:'.$domainRequest->domain_name];
        }

        $data = $request->validate($rules, ['confirm_domain.in' => 'Type the domain name exactly to confirm.']);

        $requests->approve($domainRequest, $data['note'] ?? null, $request->user());

        return back()->with('success', $domainRequest->isRemoval() ? 'Removal approved.' : 'Request approved. Verification instructions are being prepared.');
    }

    public function reject(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('decide', $domainRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $requests->reject($domainRequest, $data['reason'], $request->user());

        return back()->with('success', 'Request rejected.');
    }

    public function cancel(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('cancel', $domainRequest);

        $requests->cancel($domainRequest, $request->user());

        return back()->with('success', 'Request cancelled.');
    }

    public function checkDns(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('process', $domainRequest);

        return $requests->checkDns($domainRequest, $request->user())
            ? back()->with('success', 'The TXT record is visible in public DNS. You can now ask Google to verify.')
            : back()->with('warning', 'The TXT record was not found in public DNS yet. DNS changes can take time to spread.');
    }

    public function verify(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('process', $domainRequest);

        $requests->requestVerification($domainRequest, $request->user());

        return back()->with('success', 'Verification requested.');
    }

    public function retry(Request $request, DomainRequest $domainRequest, DomainRequestService $requests): RedirectResponse
    {
        Gate::authorize('process', $domainRequest);

        $requests->retry($domainRequest, $request->user());

        return back()->with('success', 'The failed step is being retried.');
    }

    public function requestRemoval(Request $request, OrganisationDomain $domain, DomainRequestService $requests): RedirectResponse
    {
        abort_unless($request->user()->hasAbility(Ability::DomainsRemove, $domain->organisation), 403);

        $data = $request->validateWithBag('removal', [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'aliases_checked' => ['accepted'],
            'groups_checked' => ['accepted'],
        ]);

        $domainRequest = $requests->submitRemoval($domain, $data['reason'], ['aliases_checked' => true, 'groups_checked' => true], $request->user());

        return redirect()->route('domains.requests.show', $domainRequest)
            ->with('success', 'Removal requested. A different Super Admin must approve it.');
    }

    /**
     * @return array{domain_name: string, note?: ?string}
     */
    private function validateDomain(Request $request): array
    {
        $request->merge(['domain_name' => DomainMappingService::normalise((string) $request->input('domain_name'))]);

        return $request->validate([
            'organisation' => ['required', 'uuid'],
            'domain_name' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['domain_name.regex' => 'Enter a valid domain name, for example example.com.']);
    }
}
