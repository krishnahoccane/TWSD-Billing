<?php

namespace App\Modules\Licensing\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareLicenseEvent;
use App\Modules\Licensing\Models\SoftwareOrder;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Services\ActivationService;
use App\Modules\Licensing\Services\DownloadService;
use App\Modules\Licensing\Services\LicenseService;
use App\Modules\Licensing\Services\OrderService;
use App\Modules\Licensing\Support\ApiPresenter;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Server-to-server API for a storefront (musicdesk.tech). Authenticated by
 * the storefront key; every query is limited to the storefront's own
 * products and customers. Customer-scoped calls name the customer in
 * "X-Customer-Id: cus_…" and a license of another customer is a 404.
 * "X-Acting-User" is recorded on every change.
 */
class StorefrontApiController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly LicenseService $licenses,
        private readonly ActivationService $activations,
    ) {}

    // ---- Catalog -----------------------------------------------------------

    public function catalog(Request $request, string $product): JsonResponse
    {
        $product = $this->product($request, $product);
        $product->load(['plans.prices']);
        $currency = $request->query('currency');

        return response()->json(ApiPresenter::catalog($product, is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : null));
    }

    public function releases(Request $request, string $product): JsonResponse
    {
        $product = $this->product($request, $product);
        $channel = $request->query('channel', 'stable');

        $releases = SoftwareRelease::query()->where('software_product_id', $product->getKey())->where('is_published', true)
            ->when(is_string($channel) && $channel !== 'all', fn ($q) => $q->where('channel', $channel))
            ->orderByDesc('released_at')->limit(100)->get()->each->setRelation('product', $product);

        return response()->json(['data' => $releases->map(fn (SoftwareRelease $r) => ApiPresenter::release($r))->all()]);
    }

    // ---- Customers ---------------------------------------------------------

    /**
     * Create a customer before any order (the storefront's sign-up). Linked to this storefront;
     * later orders and calls use its cus_… id. Needs an Idempotency-Key.
     */
    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        $organisation = $this->orders->customer($this->storefront($request), $data, isset($data['currency']) ? strtoupper($data['currency']) : null);

        return response()->json(ApiPresenter::customer($organisation->fresh()), 201);
    }

    public function customer(Request $request, string $customer): JsonResponse
    {
        return response()->json(ApiPresenter::customer($this->customerFor($request, $customer)));
    }

    public function updateCustomer(Request $request, string $customer, AuditLogger $audit): JsonResponse
    {
        $organisation = $this->customerFor($request, $customer);

        $data = $request->validate([
            'company' => ['sometimes', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'email', 'max:255'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'address' => ['sometimes', 'array'],
            'address.line1' => ['nullable', 'string', 'max:1000'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.state' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:16'],
        ]);

        $changes = array_filter([
            'legal_name' => $data['company'] ?? null,
            'billing_email' => isset($data['billing_email']) ? Str::lower($data['billing_email']) : null,
            'country' => isset($data['country']) ? strtoupper($data['country']) : null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'billing_address' => $data['address']['line1'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'state' => $data['address']['state'] ?? null,
            'postal_code' => $data['address']['postal_code'] ?? null,
        ], fn ($value) => $value !== null);

        $previous = $organisation->only(array_keys($changes));
        $organisation->forceFill($changes)->save();
        $audit->record('organisation.updated', $organisation, previous: $previous, new: $changes + ['by' => $this->actor($request)], organisation: $organisation);

        return response()->json(ApiPresenter::customer($organisation->fresh()));
    }

    // ---- Orders (paid on the storefront) -----------------------------------

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(SoftwareOrder::KINDS)],
            'customer' => ['required_if:kind,new_license', 'array'],
            'customer.id' => ['nullable', 'string', 'max:60'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.company' => ['nullable', 'string', 'max:255'],
            'customer.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'customer.vat_id' => ['nullable', 'string', 'max:40'],
            'product' => ['required_if:kind,new_license', 'nullable', 'string', 'max:40'],
            'plan_code' => ['required_if:kind,new_license', 'required_if:kind,change_plan', 'nullable', 'string', 'max:40'],
            'billing_type' => ['required_if:kind,new_license', 'nullable', Rule::in(['annual', 'lifetime'])],
            'license_id' => ['required_unless:kind,new_license', 'nullable', 'string', 'max:60'],
            'extra_domains' => ['required_if:kind,extra_domains', 'nullable', 'integer', 'min:0', 'max:100'],
            'primary_domain' => ['nullable', 'string', 'max:253'],
            'auto_renew' => ['nullable', 'boolean'],
            'amount' => ['required', 'array'],
            'amount.amount' => ['required', 'integer', 'min:0'],
            'amount.currency' => ['required', 'string', 'size:3', 'alpha'],
            // Optional USD value of the same amount (the storefront's exchange rate), for revenue reports.
            'amount_usd' => ['nullable', 'integer', 'min:0'],
            'payment_provider' => ['nullable', 'string', 'max:30'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending'])],
            'invoice_number' => ['nullable', 'string', 'max:60'],
        ]);

        $storefront = $this->storefront($request);

        // An existing license must belong to the customer named in X-Customer-Id.
        if ($data['kind'] !== 'new_license') {
            $this->licenseFor($request, (string) $data['license_id']);
        }

        $order = $this->orders->apply($storefront, $data, (string) $request->attributes->get('idempotency_key'), $this->actingUser($request));
        $order->load(['organisation', 'license']);

        return response()->json(ApiPresenter::order($order) + [
            'license' => $order->license ? ApiPresenter::license($order->license->load(['plan', 'product', 'activations', 'organisation']), true) : null,
        ], 201);
    }

    /** A pending (bank-transfer) order has been paid: the order becomes paid and its pending license active. */
    public function confirmPayment(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $order = $this->orders->confirmPayment($this->orderFor($request, $order), $data['payment_reference'] ?? null,
            isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : null, $this->actingUser($request));

        return $this->orderResponse($order);
    }

    /** The storefront refunded an order: a new_license order's license is revoked. */
    public function refundOrder(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'refund_reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->orders->refund($this->orderFor($request, $order), $data['refund_reference'] ?? null, $data['reason'] ?? null, $this->actingUser($request));

        return $this->orderResponse($order);
    }

    private function orderFor(Request $request, string $order): SoftwareOrder
    {
        $customer = filled($request->header('X-Customer-Id')) ? $this->currentCustomer($request) : null;

        return $this->orders->order($this->storefront($request), $order, $customer);
    }

    private function orderResponse(SoftwareOrder $order): JsonResponse
    {
        return response()->json(ApiPresenter::order($order) + [
            'license' => $order->license ? ApiPresenter::license($order->license->load(['plan', 'product', 'activations', 'organisation']), true) : null,
        ]);
    }

    // ---- Licenses ----------------------------------------------------------

    public function licenses(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $include = in_array('activations', explode(',', (string) $request->query('include')), true);

        $query = SoftwareLicense::query()->where('organisation_id', $customer->getKey())
            ->whereIn('software_product_id', $this->storefront($request)->products()->select('id'))
            ->with(['plan', 'product', 'organisation', 'activations'])->orderByDesc('id');

        // Cursor = the last license's public id (never a numeric id).
        if (filled($cursor = $request->query('cursor'))) {
            $after = str_starts_with((string) $cursor, 'lic_') && Str::isUuid(substr((string) $cursor, 4))
                ? SoftwareLicense::query()->where('uuid', substr((string) $cursor, 4))->where('organisation_id', $customer->getKey())->value('id')
                : null;
            $query->where('id', '<', $after ?? 0);
        }

        $page = $query->limit($limit + 1)->get();
        $next = $page->count() > $limit ? $page[$limit - 1]->publicId() : null;

        return response()->json([
            'data' => $page->take($limit)->map(fn (SoftwareLicense $l) => ApiPresenter::license($l, $include))->values()->all(),
            'next_cursor' => $next,
        ]);
    }

    public function license(Request $request, string $license): JsonResponse
    {
        return response()->json(ApiPresenter::license($this->licenseFor($request, $license)->load('activations'), true));
    }

    public function revealKey(Request $request, string $license): JsonResponse
    {
        $license = $this->licenseFor($request, $license);

        return response()->json(['key' => $this->licenses->revealKey($license, null, $this->actor($request))])
            ->header('Cache-Control', 'no-store');
    }

    public function events(Request $request, string $license): JsonResponse
    {
        $license = $this->licenseFor($request, $license);

        return response()->json(['data' => $license->events()->limit(200)->get()->map(fn (SoftwareLicenseEvent $e) => [
            'event' => $e->event,
            'details' => $e->details ?? (object) [],
            'actor' => str_starts_with((string) $e->actor, 'storefront:') ? 'you' : $e->actor,
            'created_at' => ApiPresenter::date($e->created_at),
        ])->all()]);
    }

    public function updateLicense(Request $request, string $license): JsonResponse
    {
        $license = $this->licenseFor($request, $license);
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);

        $license = $this->licenses->setAutoRenew($license, (bool) $data['auto_renew'], null, $this->actor($request));

        return response()->json(ApiPresenter::license($license->load(['plan', 'product', 'organisation', 'activations']), true));
    }

    /** A single-use link (15 minutes) to a release package this license may download. */
    public function download(Request $request, string $license, DownloadService $downloads): JsonResponse
    {
        $license = $this->licenseFor($request, $license);
        $data = $request->validate(['version' => ['required', 'string', 'max:20']]);

        $issued = $downloads->issue($license, $data['version'], $this->actor($request));

        return response()->json([
            'url' => route('licensing.download', $issued['token']),
            'expires_at' => ApiPresenter::date($issued['link']->expires_at),
            'version' => $issued['release']->version,
            'file_name' => $issued['release']->package_name,
            'size_bytes' => $issued['release']->size_bytes,
            'sha256' => $issued['release']->sha256,
        ], 201)->header('Cache-Control', 'no-store');
    }

    // ---- Activations -------------------------------------------------------

    public function activations(Request $request, string $license): JsonResponse
    {
        $license = $this->licenseFor($request, $license);

        return response()->json(['data' => $license->activations()->where('status', '!=', SoftwareActivation::DEACTIVATED)->get()
            ->each->setRelation('license', $license)
            ->map(fn (SoftwareActivation $a) => ApiPresenter::activation($a))->all()]);
    }

    public function addActivation(Request $request, string $license): JsonResponse
    {
        $license = $this->licenseFor($request, $license);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'environment' => ['nullable', Rule::in(['production', 'staging'])]]);

        $activation = $this->activations->add($license, $data['domain'], $data['environment'] ?? null, null, $this->actor($request));

        return response()->json(ApiPresenter::activation($activation->setRelation('license', $license)), 201);
    }

    public function moveActivation(Request $request, string $license, string $activation): JsonResponse
    {
        [$license, $activation] = $this->activationFor($request, $license, $activation);
        $data = $request->validate(['new_domain' => ['required', 'string', 'max:253']]);

        $new = $this->activations->move($activation, $data['new_domain'], null, $this->actor($request));

        return response()->json(ApiPresenter::activation($new->setRelation('license', $license)), 201);
    }

    public function deactivateActivation(Request $request, string $license, string $activation): JsonResponse
    {
        [$license, $activation] = $this->activationFor($request, $license, $activation);
        $this->activations->deactivate($activation, null, $this->actor($request));

        return response()->json(ApiPresenter::activation($activation->setRelation('license', $license)));
    }

    public function requestCheckIn(Request $request, string $license, string $activation): JsonResponse
    {
        [$license, $activation] = $this->activationFor($request, $license, $activation);
        $this->activations->requestCheckIn($activation, null, $this->actor($request));

        return response()->json(ApiPresenter::activation($activation->setRelation('license', $license)));
    }

    // ---- Helpers -------------------------------------------------------------

    private function storefront(Request $request): Storefront
    {
        return $request->attributes->get('storefront');
    }

    private function product(Request $request, string $code): SoftwareProduct
    {
        return SoftwareProduct::query()->where('storefront_id', $this->storefront($request)->getKey())
            ->where('code', $code)->where('is_active', true)->first()
            ?? throw new LicensingException('product_not_found', 'Product not found.', 404);
    }

    /** The customer in X-Customer-Id, which must be one of this storefront's customers. */
    private function currentCustomer(Request $request): Organisation
    {
        $id = (string) $request->header('X-Customer-Id');

        if ($id === '') {
            throw new LicensingException('customer_required', 'Send X-Customer-Id with this request.', 400);
        }

        return $this->orders->ownedCustomer($this->storefront($request), $id);
    }

    /** A path customer must equal X-Customer-Id when that header is present. */
    private function customerFor(Request $request, string $customer): Organisation
    {
        if (filled($request->header('X-Customer-Id')) && $request->header('X-Customer-Id') !== $customer) {
            throw new LicensingException('customer_mismatch', 'X-Customer-Id does not match this customer.', 403);
        }

        return $this->orders->ownedCustomer($this->storefront($request), $customer);
    }

    private function licenseFor(Request $request, string $license): SoftwareLicense
    {
        return $this->orders->license($this->storefront($request), $license, $this->currentCustomer($request));
    }

    /** @return array{0: SoftwareLicense, 1: SoftwareActivation} */
    private function activationFor(Request $request, string $license, string $activation): array
    {
        $license = $this->licenseFor($request, $license);
        $uuid = str_starts_with($activation, 'act_') ? substr($activation, 4) : '';
        $found = Str::isUuid($uuid) ? $license->activations()->where('uuid', $uuid)->first() : null;

        return [$license, ($found ?? throw new LicensingException('activation_not_found', 'Activation not found.', 404))->setRelation('license', $license)];
    }

    private function actingUser(Request $request): ?string
    {
        $user = (string) $request->header('X-Acting-User');

        return $user !== '' && preg_match('/^[A-Za-z0-9_\-@.]{1,100}$/', $user) ? $user : null;
    }

    private function actor(Request $request): string
    {
        return 'storefront:'.$this->storefront($request)->code.(($user = $this->actingUser($request)) ? ' ('.$user.')' : '');
    }
}
