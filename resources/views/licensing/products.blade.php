@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Products & plans" description="What the storefront sells: plans, prices per currency and software releases."
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Products & plans']]">
    <div class="space-y-6">
        @if ($errors->any() || collect($errors->getBags())->except('default')->isNotEmpty())
            <x-ui.alert tone="danger">{{ collect($errors->getBags())->map->first()->filter()->first() }}</x-ui.alert>
        @endif

        @forelse ($products as $product)
            <x-ui.card :title="$product->name" :description="'Code '.$product->code.' · keys '.$product->key_prefix.'-XXXX-… · sold on '.($product->storefront?->name ?? '—').' · latest release '.($product->releases->first()?->version ?? 'none')" :padding="false">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Plan</th><th class="text-right">Domains</th><th>Prices</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($product->plans as $plan)
                                <tr>
                                    <td><span class="font-medium">{{ $plan->name }}</span> <span class="muted font-mono text-xs">{{ $plan->code }}</span>
                                        @if ($plan->is_featured)<x-ui.badge tone="info">Featured</x-ui.badge>@endif
                                        @if ($plan->blurb)<div class="muted text-xs">{{ $plan->blurb }}</div>@endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ $plan->included_domains }}</td>
                                    <td class="text-xs">
                                        @forelse ($plan->prices->where('is_active', true)->sortBy(['kind', 'currency']) as $price)
                                            <div><span class="muted">{{ str_replace('_', ' ', $price->kind) }}</span> {{ Money::format($price->amount_minor, $price->currency) }}</div>
                                        @empty
                                            <span class="muted">No prices</span>
                                        @endforelse
                                    </td>
                                    <td><x-ui.badge :tone="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'On sale' : 'Hidden' }}</x-ui.badge></td>
                                    <td class="text-right whitespace-nowrap">
                                        <x-ui.button size="sm" variant="ghost" @click="$dispatch('open-modal', 'plan-{{ $plan->uuid }}')">Edit</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" @click="$dispatch('open-modal', 'price-{{ $plan->uuid }}')">Set price</x-ui.button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="muted">No plans yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-slot:footer>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="plus" @click="$dispatch('open-modal', 'plan-new-{{ $product->uuid }}')">Add plan</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" icon="upload" @click="$dispatch('open-modal', 'release-{{ $product->uuid }}')">Publish release</x-ui.button>
                    </div>
                    @if ($product->releases->isNotEmpty())
                        <div class="mt-3 space-y-2">
                            @foreach ($product->releases as $release)
                                <form method="POST" action="{{ route('licensing.releases.package', $release->uuid) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 text-xs">
                                    @csrf
                                    <span class="w-40 font-medium">{{ $release->version }} <span class="muted">({{ $release->channel }}, {{ $release->released_at->format('d M Y') }})</span></span>
                                    @if ($release->package_path)
                                        <x-ui.badge tone="success">{{ $release->package_name }} · {{ number_format(($release->size_bytes ?? 0) / 1048576, 1) }} MB</x-ui.badge>
                                        <span class="muted font-mono" title="SHA-256">{{ \Illuminate\Support\Str::limit((string) $release->sha256, 16, '…') }}</span>
                                    @else
                                        <x-ui.badge tone="warning">No package — not downloadable</x-ui.badge>
                                    @endif
                                    <input type="file" name="package" accept=".zip,.tar,.gz,.tgz" required class="text-xs">
                                    <x-ui.button type="submit" size="sm" variant="ghost">{{ $release->package_path ? 'Replace package' : 'Upload package' }}</x-ui.button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </x-slot:footer>
            </x-ui.card>

            @foreach ($product->plans->push(null) as $plan)
                <x-ui.modal :name="$plan ? 'plan-'.$plan->uuid : 'plan-new-'.$product->uuid" :title="$plan ? 'Edit '.$plan->name : 'New plan for '.$product->name" max-width="max-w-lg">
                    <form method="POST" action="{{ $plan ? route('licensing.plans.update', $plan->uuid) : route('licensing.plans.store', $product->uuid) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @csrf @if ($plan) @method('PUT') @endif
                        <x-ui.form.field label="Name" required><x-ui.form.input name="name" :value="$plan?->name" required maxlength="255" /></x-ui.form.field>
                        <x-ui.form.field label="Code" hint="starter, business, enterprise…" required><x-ui.form.input name="code" :value="$plan?->code" required class="font-mono" maxlength="40" /></x-ui.form.field>
                        <x-ui.form.field label="Included production domains" required><x-ui.form.input type="number" name="included_domains" :value="$plan?->included_domains ?? 1" min="1" required /></x-ui.form.field>
                        <x-ui.form.field label="Sort order"><x-ui.form.input type="number" name="sort" :value="$plan?->sort ?? 0" min="0" /></x-ui.form.field>
                        <x-ui.form.field label="Blurb" class="sm:col-span-2"><x-ui.form.input name="blurb" :value="$plan?->blurb" maxlength="500" /></x-ui.form.field>
                        <x-ui.form.field label="Features (one per line)" class="sm:col-span-2"><x-ui.form.textarea name="features" :value="implode(PHP_EOL, $plan?->features ?? [])" rows="4" /></x-ui.form.field>
                        <input type="hidden" name="is_featured" value="0"><x-ui.form.checkbox name="is_featured" label="Featured" :checked="$plan?->is_featured ?? false" />
                        <input type="hidden" name="is_active" value="0"><x-ui.form.checkbox name="is_active" label="On sale" :checked="$plan?->is_active ?? true" />
                        <div class="flex justify-end gap-2 sm:col-span-2"><x-ui.button type="submit">Save plan</x-ui.button></div>
                    </form>
                </x-ui.modal>
                @if ($plan)
                    <x-ui.modal :name="'price-'.$plan->uuid" :title="'Set a price for '.$plan->name">
                        <form method="POST" action="{{ route('licensing.prices.save', $plan->uuid) }}" class="space-y-4">
                            @csrf
                            <x-ui.form.field label="Price for"><x-ui.form.select name="kind" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k => ucfirst(str_replace('_', ' ', $k))])->all()" /></x-ui.form.field>
                            <x-ui.form.field label="Currency" hint="USD, EUR, GBP, INR… one price per currency."><x-ui.form.input name="currency" value="USD" maxlength="3" class="font-mono uppercase" required /></x-ui.form.field>
                            <x-ui.form.field label="Amount" hint="Leave empty to stop selling at this price."><x-ui.form.input name="amount" inputmode="decimal" placeholder="1299.00" /></x-ui.form.field>
                            <div class="flex justify-end"><x-ui.button type="submit">Save price</x-ui.button></div>
                        </form>
                    </x-ui.modal>
                @endif
            @endforeach

            <x-ui.modal :name="'release-'.$product->uuid" :title="'Publish a '.$product->name.' release'" max-width="max-w-lg">
                <form method="POST" action="{{ route('licensing.releases.store', $product->uuid) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.form.field label="Version" required><x-ui.form.input name="version" placeholder="4.3.0" required class="font-mono" /></x-ui.form.field>
                    <x-ui.form.field label="Channel"><x-ui.form.select name="channel" :options="['stable' => 'Stable', 'beta' => 'Beta']" /></x-ui.form.field>
                    <x-ui.form.field label="Released on" required><x-ui.form.input type="date" name="released_at" :value="now(config('app.display_timezone'))->toDateString()" required /></x-ui.form.field>
                    <x-ui.form.field label="Size (bytes)"><x-ui.form.input type="number" name="size_bytes" min="0" /></x-ui.form.field>
                    <x-ui.form.field label="Package (.zip)" class="sm:col-span-2" hint="Stored on this server; size and SHA-256 are worked out from the file. Up to {{ config('wcc.licensing.max_package_mb') }} MB.">
                        <input type="file" name="package" accept=".zip,.tar,.gz,.tgz" class="text-sm">
                    </x-ui.form.field>
                    <x-ui.form.field label="SHA-256 (if no package uploaded)" class="sm:col-span-2"><x-ui.form.input name="sha256" class="font-mono text-xs" maxlength="64" /></x-ui.form.field>
                    <x-ui.form.field label="PHP"><x-ui.form.input name="php" placeholder="8.2+" /></x-ui.form.field>
                    <x-ui.form.field label="Database"><x-ui.form.input name="database" placeholder="MySQL 8 / PostgreSQL 15" /></x-ui.form.field>
                    <x-ui.form.field label="Other requirements" class="sm:col-span-2"><x-ui.form.input name="extras" placeholder="Redis, cron, 2 GB RAM" /></x-ui.form.field>
                    <x-ui.form.field label="Release notes" hint="One per line, e.g. &quot;new: Multi-currency statements&quot; (new, improved, fixed, security)." class="sm:col-span-2">
                        <x-ui.form.textarea name="notes" rows="5" />
                    </x-ui.form.field>
                    <div class="flex justify-end sm:col-span-2"><x-ui.button type="submit">Publish</x-ui.button></div>
                </form>
            </x-ui.modal>
        @empty
            <x-ui.empty-state title="No products yet" icon="package" description="Create a storefront first, then add the product it sells." />
        @endforelse

        @if ($storefronts !== [])
            <x-ui.card title="Add a product" class="max-w-2xl">
                <form method="POST" action="{{ route('licensing.products.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.form.field label="Storefront" bag="product" required><x-ui.form.select name="storefront" bag="product" :options="$storefronts" required /></x-ui.form.field>
                    <x-ui.form.field label="Name" bag="product" required><x-ui.form.input name="name" bag="product" placeholder="LabelDesk" required /></x-ui.form.field>
                    <x-ui.form.field label="Code" bag="product" hint="Used in the API: /catalog/labeldesk" required><x-ui.form.input name="code" bag="product" placeholder="labeldesk" class="font-mono" required /></x-ui.form.field>
                    <x-ui.form.field label="Key prefix" bag="product" required><x-ui.form.input name="key_prefix" bag="product" value="LDSK" class="font-mono uppercase" maxlength="8" required /></x-ui.form.field>
                    <div><x-ui.button type="submit" icon="plus">Add product</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
