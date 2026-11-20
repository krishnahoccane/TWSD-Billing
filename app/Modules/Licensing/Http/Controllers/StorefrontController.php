<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Models\StorefrontWebhookEvent;
use App\Modules\Licensing\Services\StorefrontService;
use App\Modules\Licensing\Support\LicenseToken;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Licensing → Storefronts (Super Admin): API keys and webhook secrets are
 * shown exactly once, in the response to the create/rotate request (not
 * cached, not put in the session); they are never stored readably or shown again.
 */
class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontService $storefronts) {}

    public function index(LicenseToken $tokens): View
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);

        return $this->page($tokens);
    }

    /** @param  array<string, string>|null  $secrets  shown once in this response, never stored readably */
    private function page(LicenseToken $tokens, ?array $secrets = null): View
    {
        return view('licensing.storefronts', [
            'secrets' => $secrets,
            'storefronts' => Storefront::query()->withCount('customers')->orderBy('name')->get(),
            'events' => StorefrontWebhookEvent::query()->with('storefront:id,name')->latest('id')->limit(25)->get(),
            'publicKey' => $tokens->publicKeyBase64(),
            'keyId' => $tokens->keyId(),
            'organisations' => Organisation::query()->orderBy('name')->pluck('name', 'uuid')->all(),
        ]);
    }

    public function store(Request $request, LicenseToken $tokens): Response
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);

        $data = $request->validateWithBag('storefront', [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('storefronts', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        [$storefront, $key] = $this->storefronts->create($data['code'], $data['name'], $data['webhook_url'] ?? null, $request->user());
        $secret = $this->storefronts->rotateWebhookSecret($storefront, $request->user());

        return $this->once($tokens, ['storefront' => $storefront->name, 'WCC_BILLING_KEY' => $key, 'WCC_BILLING_WEBHOOK_SECRET' => $secret]);
    }

    public function update(Request $request, string $storefront): RedirectResponse
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);
        $storefront = Storefront::query()->where('uuid', $storefront)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url:https', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->storefronts->update($storefront, $data['name'], $data['webhook_url'] ?? null, (bool) ($data['is_active'] ?? false), $request->user());

        return back()->with('success', 'Storefront saved.');
    }

    public function rotateKey(Request $request, string $storefront, LicenseToken $tokens): Response
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);
        $storefront = Storefront::query()->where('uuid', $storefront)->firstOrFail();

        return $this->once($tokens, ['storefront' => $storefront->name, 'WCC_BILLING_KEY' => $this->storefronts->rotateKey($storefront, $request->user())]);
    }

    public function rotateSecret(Request $request, string $storefront, LicenseToken $tokens): Response
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);
        $storefront = Storefront::query()->where('uuid', $storefront)->firstOrFail();

        return $this->once($tokens, ['storefront' => $storefront->name, 'WCC_BILLING_WEBHOOK_SECRET' => $this->storefronts->rotateWebhookSecret($storefront, $request->user())]);
    }

    /** Let a storefront act for an organisation that already exists here (e.g. an existing Workspace client). */
    public function linkCustomer(Request $request, string $storefront): RedirectResponse
    {
        Gate::authorize(Ability::LicensingStorefrontsManage->value);
        $storefront = Storefront::query()->where('uuid', $storefront)->firstOrFail();
        $data = $request->validate(['organisation' => ['required', 'uuid']]);
        $organisation = Organisation::query()->where('uuid', $data['organisation'])->firstOrFail();

        $this->storefronts->linkCustomer($storefront, $organisation, $request->user());

        return back()->with('success', "{$organisation->name} is now a customer of {$storefront->name}: its id is cus_{$organisation->uuid}.");
    }

    /** @param  array<string, string>  $secrets */
    private function once(LicenseToken $tokens, array $secrets): Response
    {
        return response($this->page($tokens, $secrets)->render())->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
