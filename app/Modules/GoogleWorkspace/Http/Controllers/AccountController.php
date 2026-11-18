<?php

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Http\Requests\StoreAccountRequest;
use App\Modules\GoogleWorkspace\Http\Requests\UpdateAccountRequest;
use App\Modules\GoogleWorkspace\Models\AccountDeletionRequest;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleOrganisationalUnit;
use App\Modules\GoogleWorkspace\Services\GoogleAccountService;
use App\Modules\GoogleWorkspace\Services\InitialPasswordVault;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GoogleAccount::class);

        $preset = $request->route()->defaults['preset'] ?? 'all';

        if ($preset === 'unassigned') {
            abort_unless($request->user()->hasGlobalAccess() && $request->user()->hasAbility(Ability::AccountsReassignOrganisation), 403);
        }

        return view('accounts.index', ['preset' => $preset]);
    }

    public function create(Request $request, OrganisationContext $context): View
    {
        $organisations = Organisation::query()
            ->visibleTo($request->user(), Ability::AccountsCreate)
            ->with(['domains' => fn ($query) => $query->where('status', 'active')->orderBy('domain_name')])
            ->orderBy('name')
            ->get();

        abort_if($organisations->isEmpty(), 403);

        return view('accounts.create', [
            'organisations' => $organisations,
            'selected' => $context->current($request->user())?->uuid ?? $organisations->first()->uuid,
        ]);
    }

    public function store(StoreAccountRequest $request, GoogleAccountService $accounts): RedirectResponse
    {
        $account = $accounts->requestCreation($request->organisation(), $request->domain(), $request->validated(), $request->user());

        return redirect()->route('accounts.show', $account)->with('success', "{$account->primary_email} is being created.");
    }

    public function show(Request $request, GoogleAccount $account, InitialPasswordVault $vault, GoogleAccountService $accounts): View
    {
        Gate::authorize('view', $account);

        return $this->render($request, $account, $vault, $accounts);
    }

    public function edit(GoogleAccount $account): View
    {
        Gate::authorize('update', $account);

        return view('accounts.edit', [
            'account' => $account,
            'orgUnits' => GoogleOrganisationalUnit::query()->orderBy('path')->pluck('path', 'path')->all(),
        ]);
    }

    public function update(UpdateAccountRequest $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        $queued = $accounts->requestUpdate($account, $request->validated(), $request->user());

        return redirect()->route('accounts.show', $account)
            ->with($queued ? 'success' : 'info', $queued ? 'Changes are being applied in Google Workspace.' : 'Nothing was changed.');
    }

    public function suspend(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('suspend', $account);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $accounts->requestSuspension($account, $data['reason'], $request->user());

        return back()->with('success', 'Suspension requested.');
    }

    public function reactivate(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('reactivate', $account);

        $accounts->requestReactivation($account, $request->user());

        return back()->with('success', 'Reactivation requested.');
    }

    public function resetPassword(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('resetPassword', $account);

        $data = $request->validateWithBag('password', [
            'mode' => ['required', 'in:generate,manual'],
            // Google accepts 8–100 printable ASCII characters.
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'min:8', 'max:100', 'regex:/^[\x21-\x7E]+$/', 'confirmed'],
            'change_at_next_login' => ['nullable', 'boolean'],
        ], ['password.regex' => 'Use letters, digits and symbols only (no spaces or accented characters).']);

        $generate = $data['mode'] === 'generate';
        $accounts->requestPasswordReset($account, $generate ? null : $data['password'], (bool) ($data['change_at_next_login'] ?? false), $request->user());

        return back()->with('success', $generate
            ? 'A new password is being set in Google. Reveal it once on this page when it is ready (within 24 hours).'
            : 'The new password is being set in Google.');
    }

    public function turnOffTwoStepVerification(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('manageSecurity', $account);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $accounts->requestTwoStepVerificationOff($account, $data['reason'], $request->user());

        return back()->with('success', '2-Step Verification is being turned off in Google. The user can sign in with the password alone and enrol again.');
    }

    public function softDelete(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('softDelete', $account);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Typed confirmation is enforced on the server as well as in the dialog.
            'confirm_email' => ['required', 'string', 'in:'.$account->primary_email],
        ], ['confirm_email.in' => 'Type the mailbox address exactly to confirm.']);

        $accounts->requestSoftDeletion($account, $data['reason'], $request->user());

        return back()->with('success', 'Deletion requested. The mailbox is being suspended; it is not removed from Google.');
    }

    public function restore(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('restore', $account);

        $accounts->requestRestore($account, $request->user());

        return back()->with('success', 'Restore requested.');
    }

    public function retry(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('update', $account);

        $accounts->retryCreation($account, $request->user());

        return back()->with('success', 'Creation is being retried.');
    }

    /**
     * Shows the initial password once. It is rendered straight into this
     * response — never flashed to the session — and the stored copy is
     * destroyed by reveal().
     */
    public function reveal(Request $request, GoogleAccount $account, InitialPasswordVault $vault, GoogleAccountService $accounts): Response
    {
        Gate::authorize('view', $account);

        $password = $vault->reveal($account, $request->user());

        abort_if($password === null, 404);

        return response($this->render($request, $account, $vault, $accounts, $password))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function assign(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('reassign', $account);

        $transfer = ! $account->isUnassigned();

        // Moving a mailbox away from an organisation needs a reason (audited).
        $data = $request->validateWithBag($transfer ? 'reassign' : 'default', [
            'organisation' => ['required', 'uuid'],
            'reason' => [$transfer ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        // Resolved inside the caller's own scope for this ability; anything else is a 404.
        $organisation = Organisation::query()
            ->visibleTo($request->user(), Ability::AccountsReassignOrganisation)
            ->where('uuid', $data['organisation'])
            ->firstOrFail();

        if ($organisation->getKey() === $account->organisation_id) {
            return back()->withErrors(['organisation' => 'The mailbox already belongs to this organisation.'], $transfer ? 'reassign' : 'default');
        }

        $accounts->assignOrganisation($account, $organisation, $request->user(), $data['reason'] ?? null);

        return back()->with('success', "{$account->primary_email} now belongs to {$organisation->name}."
            .($transfer ? ' It is billed to the new organisation from today.' : ''));
    }

    public function changeBillingStart(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('changeBillingStart', $account);

        $data = $request->validateWithBag('billing', [
            'billable_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $timezone = config('wcc.billing.timezone');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['billable_from'], $timezone);
        $previous = $accounts->changeBillingStart($account, $date, $data['reason'], $request->user());

        // Completed months whose invoice is affected (billing is in arrears).
        $months = [];
        $currentMonth = CarbonImmutable::now($timezone)->startOfMonth();

        $earliest = CarbonImmutable::createFromFormat('!Y-m-d', min($date->toDateString(), $previous->toDateString()), $timezone);

        for ($month = $earliest->startOfMonth(); $month->lt($currentMonth); $month = $month->addMonth()) {
            $months[] = $month->format('F Y');
        }

        return back()->with('success', "{$account->primary_email} is now billed from ".$date->format('d M Y')
            .' (was '.$previous->format('d M Y').').'
            .($months === [] ? '' : ' Re-run billing or recalculate the draft invoice for: '.implode(', ', $months).'.'));
    }

    public function requestPermanentDeletion(Request $request, GoogleAccount $account, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('requestPermanentDeletion', $account);

        $data = $request->validateWithBag('permanent', [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'ownership_verified' => ['accepted'],
            'retention_checked' => ['accepted'],
            'vault_checked' => ['accepted'],
            'dependencies_checked' => ['accepted'],
        ]);

        $accounts->requestPermanentDeletion($account, $data['reason'], [
            'ownership_verified' => true, 'retention_checked' => true, 'vault_checked' => true, 'dependencies_checked' => true,
        ], $request->user());

        return back()->with('success', 'Permanent deletion requested. A different Super Admin must approve it.');
    }

    public function approvePermanentDeletion(Request $request, GoogleAccount $account, AccountDeletionRequest $deletionRequest, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('approvePermanentDeletion', $account);

        $request->validate(
            ['confirm_email' => ['required', 'string', 'in:'.$account->primary_email]],
            ['confirm_email.in' => 'Type the mailbox address exactly to confirm.'],
        );

        $accounts->approvePermanentDeletion($deletionRequest, $request->user());

        return back()->with('success', 'Approved. The mailbox is being permanently deleted from Google Workspace.');
    }

    public function rejectPermanentDeletion(Request $request, GoogleAccount $account, AccountDeletionRequest $deletionRequest, GoogleAccountService $accounts): RedirectResponse
    {
        Gate::authorize('approvePermanentDeletion', $account);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $accounts->rejectPermanentDeletion($deletionRequest, $data['reason'], $request->user());

        return back()->with('success', 'Permanent deletion request rejected.');
    }

    private function render(Request $request, GoogleAccount $account, InitialPasswordVault $vault, GoogleAccountService $accounts, ?string $password = null): View
    {
        $account->load(['organisation', 'domain', 'history.user:id,name,email']);

        return view('accounts.show', [
            'account' => $account,
            'deletionRequest' => $accounts->openDeletionRequest($account)?->load('requester:id,name,email'),
            'canReveal' => $password === null && $vault->isAvailableTo($account, $request->user()),
            'initialPassword' => $password,
            // What invoices are actually calculated from (the open billing period), not the display field.
            'billingStart' => AccountBillingPeriod::query()->where('google_account_id', $account->getKey())
                ->where('organisation_id', $account->organisation_id)->whereNull('ends_on')->value('starts_on'),
            // Organisations the mailbox can be assigned or moved to (not its current one).
            'assignable' => $request->user()->can('reassign', $account) && $account->account_status !== AccountStatus::PermanentlyDeleted
                ? Organisation::query()->visibleTo($request->user(), Ability::AccountsReassignOrganisation)
                    ->when($account->organisation_id, fn ($query, $id) => $query->whereKeyNot($id))
                    ->orderBy('name')->pluck('name', 'uuid')->all()
                : [],
        ]);
    }
}
