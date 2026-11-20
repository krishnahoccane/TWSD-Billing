<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Support\Money;
use App\Modules\Licensing\Models\SoftwarePlan;
use App\Modules\Licensing\Models\SoftwarePlanPrice;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use App\Modules\Licensing\Models\Storefront;
use App\Modules\Licensing\Services\DownloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Licensing → Products: products, plans, per-currency prices and releases
 * (Super Admin, licensing.manage). The storefront reads them through
 * GET /api/v1/storefront/catalog/{product}.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize(Ability::LicensingManage->value);

        return view('licensing.products', [
            'products' => SoftwareProduct::query()->with(['storefront', 'plans.prices', 'releases' => fn ($q) => $q->orderByDesc('released_at')->limit(10)])->orderBy('name')->get(),
            'storefronts' => Storefront::query()->orderBy('name')->pluck('name', 'uuid')->all(),
            'kinds' => SoftwarePlan::PRICE_KINDS,
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);

        $data = $request->validateWithBag('product', [
            'storefront' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('software_products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'key_prefix' => ['required', 'string', 'max:8', 'regex:/^[A-Z0-9]+$/'],
        ]);

        $storefront = Storefront::query()->where('uuid', $data['storefront'])->firstOrFail();
        $product = SoftwareProduct::query()->create(['storefront_id' => $storefront->getKey()] + collect($data)->only(['code', 'name', 'key_prefix'])->all());
        $this->audit->record('licensing.product_created', $product, new: collect($data)->except('storefront')->all(), actor: $request->user());

        return back()->with('success', "Product {$product->name} created.");
    }

    public function storePlan(Request $request, string $product): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);
        $product = SoftwareProduct::query()->where('uuid', $product)->firstOrFail();
        $data = $this->planData($request, $product);

        $plan = SoftwarePlan::query()->create(['software_product_id' => $product->getKey()] + $data);
        $this->audit->record('licensing.plan_created', $plan, new: $data, actor: $request->user());

        return back()->with('success', "Plan {$plan->name} created.");
    }

    public function updatePlan(Request $request, string $plan): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);
        $plan = SoftwarePlan::query()->where('uuid', $plan)->with('product')->firstOrFail();
        $data = $this->planData($request, $plan->product, $plan);

        $previous = $plan->only(array_keys($data));
        $plan->forceFill($data)->save();
        $this->audit->record('licensing.plan_updated', $plan, previous: $previous, new: $data, actor: $request->user());

        return back()->with('success', "Plan {$plan->name} saved. Existing licenses keep their domains until their plan is changed.");
    }

    /** Set (or clear) one price of a plan in one currency. Earlier prices are kept in the audit log. */
    public function savePrice(Request $request, string $plan): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);
        $plan = SoftwarePlan::query()->where('uuid', $plan)->firstOrFail();

        $data = $request->validateWithBag('price', [
            'kind' => ['required', Rule::in(SoftwarePlan::PRICE_KINDS)],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'amount' => ['nullable', 'string', 'max:20'],
        ]);

        $currency = strtoupper($data['currency']);

        try {
            $amount = filled($data['amount'] ?? null) ? Money::parse($data['amount']) : null;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()])->errorBag('price');
        }

        DB::transaction(function () use ($plan, $data, $currency, $amount, $request) {
            $price = SoftwarePlanPrice::query()->where('software_plan_id', $plan->getKey())->where('kind', $data['kind'])->where('currency', $currency)->lockForUpdate()->first();
            $previous = $price ? ['amount_minor' => $price->amount_minor, 'is_active' => $price->is_active] : null;

            if ($amount === null) {
                $price?->forceFill(['is_active' => false])->save();
            } elseif ($price) {
                $price->forceFill(['amount_minor' => $amount, 'is_active' => true])->save();
            } else {
                $price = SoftwarePlanPrice::query()->create(['software_plan_id' => $plan->getKey(), 'kind' => $data['kind'], 'currency' => $currency, 'amount_minor' => $amount]);
            }

            if ($price) {
                $this->audit->record('licensing.price_saved', $price, previous: $previous,
                    new: ['plan' => $plan->code, 'kind' => $data['kind'], 'currency' => $currency, 'amount_minor' => $amount], actor: $request->user());
            }
        });

        return back()->with('success', 'Price saved.');
    }

    public function storeRelease(Request $request, string $product): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);
        $product = SoftwareProduct::query()->where('uuid', $product)->firstOrFail();

        $data = $request->validateWithBag('release', [
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+([.\-+][0-9A-Za-z.\-]+)?$/',
                Rule::unique('software_releases', 'version')->where('software_product_id', $product->getKey())],
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'released_at' => ['required', 'date_format:Y-m-d'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'sha256' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'php' => ['nullable', 'string', 'max:40'],
            'database' => ['nullable', 'string', 'max:100'],
            'extras' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'package' => self::packageRules(),
        ]);

        // One note per line: "new: …", "improved: …", "fixed: …", "security: …".
        $notes = collect(preg_split('/\R/', (string) ($data['notes'] ?? '')))->map('trim')->filter()->map(function (string $line) {
            [$kind, $text] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : ['improved', $line];

            return ['kind' => in_array(strtolower($kind), ['new', 'improved', 'fixed', 'security'], true) ? strtolower($kind) : 'improved', 'text' => $text];
        })->values()->all();

        $release = SoftwareRelease::query()->create([
            'software_product_id' => $product->getKey(),
            'version' => $data['version'],
            'channel' => $data['channel'],
            'released_at' => $data['released_at'],
            'size_bytes' => $data['size_bytes'] ?? null,
            'sha256' => $data['sha256'] ?? null,
            'requirements' => array_filter(['php' => $data['php'] ?? null, 'database' => $data['database'] ?? null, 'extras' => $data['extras'] ?? null]),
            'notes' => $notes,
        ]);

        $this->audit->record('licensing.release_published', $release, new: ['product' => $product->code, 'version' => $release->version], actor: $request->user());

        if ($request->hasFile('package')) {
            app(DownloadService::class)->storePackage($release, $request->file('package'), $request->user());
        }

        return back()->with('success', "Release {$release->version} published.".($release->package_path ? ' Package uploaded.' : ' Upload its package to make it downloadable.'));
    }

    /** Upload or replace the package of an existing release. */
    public function uploadPackage(Request $request, string $release, DownloadService $downloads): RedirectResponse
    {
        Gate::authorize(Ability::LicensingManage->value);
        $release = SoftwareRelease::query()->where('uuid', $release)->firstOrFail();
        $request->validateWithBag('package', ['package' => array_merge(['required'], array_slice(self::packageRules(), 1))]);

        $downloads->storePackage($release, $request->file('package'), $request->user());

        return back()->with('success', "Package for {$release->version} uploaded (SHA-256 {$release->fresh()->sha256}).");
    }

    /** @return list<string> */
    private static function packageRules(): array
    {
        return ['nullable', 'file', 'extensions:zip,tar,gz,tgz', 'max:'.((int) config('wcc.licensing.max_package_mb', 200) * 1024)];
    }

    /** @return array<string, mixed> */
    private function planData(Request $request, SoftwareProduct $product, ?SoftwarePlan $plan = null): array
    {
        $data = $request->validateWithBag('plan', [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('software_plans', 'code')->where('software_product_id', $product->getKey())->ignore($plan?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'blurb' => ['nullable', 'string', 'max:500'],
            'features' => ['nullable', 'string', 'max:3000'],
            'included_domains' => ['required', 'integer', 'min:1', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'blurb' => $data['blurb'] ?? null,
            'features' => collect(preg_split('/\R/', (string) ($data['features'] ?? '')))->map('trim')->filter()->values()->all(),
            'included_domains' => (int) $data['included_domains'],
            'sort' => (int) ($data['sort'] ?? 0),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
