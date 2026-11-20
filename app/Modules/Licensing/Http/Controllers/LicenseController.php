<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Billing\Support\Money;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Services\ActivationService;
use App\Modules\Licensing\Services\LicenseService;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Licensing → Licenses. Organisation managers and billing managers see
 * their own organisation's licenses (read-only, can reveal the key);
 * Super Admins (licensing.manage) create and change them.
 */
class LicenseController extends Controller
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly ActivationService $activations,
    ) {}

    public function index(Request $request, OrganisationContext $context): View
    {
        $user = $request->user();
        abort_unless($user->hasAbilityAnywhere(Ability::LicensingView), 403);

        $current = $context->current($user);
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));

        $licenses = SoftwareLicense::query()->visibleTo($user, Ability::LicensingView)
            ->with(['organisation:id,uuid,name', 'plan:id,name', 'product:id,name,key_prefix'])
            ->withCount(['activations as production_used' => fn (Builder $q) => $q->where('environment', 'production')->whereIn('status', ['pending', 'active'])])
            ->when($current, fn (Builder $q) => $q->where('organisation_id', $current->getKey()))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('organisation', fn (Builder $o) => $o->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('activations', fn (Builder $a) => $a->where('domain', 'like', '%'.strtolower($search).'%'))))
            ->when(in_array($status, ['pending', 'active', 'suspended', 'revoked'], true), fn (Builder $q) => $q->where('status', $status))
            ->when($status === 'expiring', fn (Builder $q) => $q->where('status', 'active')->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(SoftwareLicense::EXPIRING_DAYS)]))
            ->when($status === 'expired', fn (Builder $q) => $q->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('licensing.index', [
            'licenses' => $licenses, 'status' => $status, 'search' => $search,
            'showOrganisation' => $current === null,
            'canManage' => $user->hasAbilityAnywhere(Ability::LicensingManage),
        ]);
    }

    public function show(Request $request, SoftwareLicense $softwareLicense): View
    {
        Gate::authorize('license.view', $softwareLicense);
        $license = $softwareLicense->load(['organisation', 'plan', 'product.plans', 'storefront']);

        return view('licensing.show', [
            'license' => $license,
            'activations' => $license->activations()->orderByRaw("case status when 'active' then 0 when 'pending' then 1 else 2 end")->orderBy('domain')->get(),
            'events' => $license->events()->limit(100)->get(),
            'orders' => $license->orders()->latest('id')->limit(20)->get(),
            'canManage' => $request->user()->can('license.manage', $license),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->hasAbilityAnywhere(Ability::LicensingManage), 403);

        return view('licensing.create', [
            'organisations' => Organisation::query()->visibleTo($request->user(), Ability::LicensingManage)->orderBy('name')->pluck('name', 'uuid')->all(),
            'plans' => SoftwarePlan::query()->with('product:id,name')->where('is_active', true)->orderBy('software_product_id')->orderBy('sort')->get()
                ->mapWithKeys(fn (SoftwarePlan $plan) => [$plan->uuid => $plan->product->name.' — '.$plan->name.' ('.$plan->included_domains.' domains)'])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAbilityAnywhere(Ability::LicensingManage), 403);

        $data = $request->validate([
            'organisation' => ['required', 'uuid'],
            'plan' => ['required', 'uuid'],
            'billing_type' => ['required', Rule::in(['annual', 'lifetime'])],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'price_paid' => ['nullable', 'string', 'max:20'],
            'domains_extra' => ['nullable', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        // Never trust the submitted organisation: resolve it inside the user's own scope.
        $organisation = Organisation::query()->visibleTo($request->user(), Ability::LicensingManage)->where('uuid', $data['organisation'])->first()
            ?? throw ValidationException::withMessages(['organisation' => 'Choose an organisation.']);
        abort_unless($request->user()->hasAbility(Ability::LicensingManage, $organisation), 403);

        $plan = SoftwarePlan::query()->where('uuid', $data['plan'])->with('product')->firstOrFail();

        try {
            $price = filled($data['price_paid'] ?? null) ? Money::parse($data['price_paid']) : 0;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['price_paid' => $e->getMessage()]);
        }

        $license = $this->guard(fn () => $this->licenses->create($organisation, $plan->product, $plan, [
            'billing_type' => $data['billing_type'],
            'currency' => strtoupper($data['currency']),
            'price_paid_minor' => $price,
            'domains_extra' => (int) ($data['domains_extra'] ?? 0),
        ], $request->user(), $request->user()->name.' ('.$data['reason'].')'));

        return redirect()->route('licensing.show', $license)->with('success', "License {$license->number} created.");
    }

    public function setStatus(Request $request, SoftwareLicense $softwareLicense): RedirectResponse
    {
        Gate::authorize('license.manage', $softwareLicense);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'revoked'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->guard(fn () => $this->licenses->setStatus($softwareLicense, $data['status'], $data['reason'], $request->user()));

        return back()->with('success', 'License '.($data['status'] === 'active' ? 'reinstated' : $data['status']).'.');
    }

    public function update(Request $request, SoftwareLicense $softwareLicense): RedirectResponse
    {
        Gate::authorize('license.manage', $softwareLicense);

        $data = $request->validateWithBag('license', [
            'plan' => ['required', 'uuid'],
            'domains_extra' => ['required', 'integer', 'min:0', 'max:500'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'support_until' => ['nullable', 'date_format:Y-m-d'],
            'auto_renew' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = $request->user();
        $label = $user->name.' ('.$data['reason'].')';
        $plan = SoftwarePlan::query()->where('software_product_id', $softwareLicense->software_product_id)->where('uuid', $data['plan'])->first()
            ?? throw ValidationException::withMessages(['plan' => 'Choose a plan of this product.'])->errorBag('license');

        $this->guard(function () use ($softwareLicense, $data, $user, $label, $plan) {
            if ($plan !== null && $plan->getKey() !== $softwareLicense->software_plan_id) {
                $this->licenses->changePlan($softwareLicense, $plan, $user, $label);
            }

            $extra = (int) $data['domains_extra'] - $softwareLicense->fresh()->domains_extra;

            if ($extra > 0) {
                $this->licenses->addDomains($softwareLicense, $extra, $user, $label);
            } elseif ($extra < 0) {
                throw new LicensingException('domains_reduce', 'Extra domains cannot be reduced here. Deactivate domains and record a refund on the storefront instead.', 422, 'domains_extra');
            }

            $tz = config('app.display_timezone') ?: 'UTC';
            $expires = filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at'], $tz)->endOfDay()->utc() : null; // stored in UTC
            $support = filled($data['support_until'] ?? null) ? CarbonImmutable::parse($data['support_until'], $tz)->endOfDay()->utc() : null;
            $fresh = $softwareLicense->fresh();

            if (($expires && ! $expires->equalTo($fresh->expires_at)) || ($support && ! $support->equalTo($fresh->support_until))) {
                $this->licenses->changeDates($fresh, $expires, $support, $data['reason'], $user);
            }

            if ((bool) ($data['auto_renew'] ?? false) !== $fresh->auto_renew) {
                $this->licenses->setAutoRenew($fresh, (bool) ($data['auto_renew'] ?? false), $user, $label);
            }
        });

        return back()->with('success', 'License updated.');
    }

    public function revealKey(Request $request, SoftwareLicense $softwareLicense): Response
    {
        Gate::authorize('license.view', $softwareLicense);

        $key = $this->licenses->revealKey($softwareLicense, $request->user(), $request->user()->name);

        return response(view('licensing.key', ['license' => $softwareLicense, 'key' => $key])->render())
            ->header('Cache-Control', 'no-store, private');
    }

    public function addActivation(Request $request, SoftwareLicense $softwareLicense): RedirectResponse
    {
        Gate::authorize('license.manage', $softwareLicense);
        $data = $request->validateWithBag('activation', ['domain' => ['required', 'string', 'max:253'], 'environment' => ['nullable', Rule::in(['production', 'staging'])]]);

        $this->guard(fn () => $this->activations->add($softwareLicense, $data['domain'], $data['environment'] ?? null, $request->user(), $request->user()->name), 'activation');

        return back()->with('success', 'Domain added. The installation on it can now activate.');
    }

    public function deactivate(Request $request, SoftwareLicense $softwareLicense, string $activation): RedirectResponse
    {
        Gate::authorize('license.manage', $softwareLicense);
        $activation = SoftwareActivation::query()->where('software_license_id', $softwareLicense->getKey())->where('uuid', $activation)->firstOrFail();

        $this->activations->deactivate($activation->setRelation('license', $softwareLicense), $request->user(), $request->user()->name);

        return back()->with('success', $activation->domain.' deactivated. The slot is free again.');
    }

    /** Turn a business-rule refusal into a form error. */
    private function guard(callable $callback, string $bag = 'default'): mixed
    {
        try {
            return $callback();
        } catch (LicensingException $e) {
            throw ValidationException::withMessages([$e->field ?? 'license' => $e->getMessage()])->errorBag($bag);
        }
    }
}
