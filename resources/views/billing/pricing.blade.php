@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Pricing" description="Monthly price per mailbox. Precedence: mailbox override, then organisation price, then platform default."
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => 'Pricing']]">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-ui.card title="Platform default price" description="Used when an organisation has no price of its own." class="xl:col-span-1">
            <form method="POST" action="{{ route('pricing.platform.store') }}" class="space-y-4">
                @csrf
                <x-ui.form.field label="Monthly price per mailbox (₹)" for="platform_price" name="price" bag="platform" required>
                    <x-ui.form.input name="price" id="platform_price" bag="platform" inputmode="decimal" placeholder="250.00" required />
                </x-ui.form.field>
                <x-ui.form.field label="Effective from" for="platform_from" name="effective_from" bag="platform" required
                                 hint="Earlier prices are kept and closed the day before.">
                    <x-ui.form.input name="effective_from" id="platform_from" type="date" bag="platform" :value="$today" required />
                </x-ui.form.field>
                <x-ui.button type="submit">Save default price</x-ui.button>
            </form>

            @if ($plans->isNotEmpty())
                <table class="data-table mt-4">
                    <thead><tr><th>From</th><th>Until</th><th class="text-right">Price</th></tr></thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr>
                                <td>{{ $plan->effective_from->format('d M Y') }}</td>
                                <td class="muted">{{ $plan->effective_until?->format('d M Y') ?? 'Current' }}</td>
                                <td class="text-right tabular-nums">{{ Money::format($plan->monthly_price_minor, $plan->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>

        <x-ui.card title="Organisation pricing" :padding="false" class="xl:col-span-2">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Organisation</th><th class="text-right">Price today</th><th>Since</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="font-medium">{{ $row['organisation']->name }}</td>
                                <td class="text-right tabular-nums">
                                    {{ $row['price'] ? Money::format($row['price']->monthly_price_minor, $row['price']->currency) : 'Platform default' }}
                                </td>
                                <td class="muted">{{ $row['price']?->effective_from->format('d M Y') ?? '—' }}</td>
                                <td class="text-right">
                                    <x-ui.button variant="secondary" size="sm" :href="route('pricing.organisations.show', $row['organisation'])">Manage</x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="!p-0"><x-ui.empty-state title="No organisations yet" icon="building-2" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
