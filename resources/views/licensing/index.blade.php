<x-layouts.app title="Licenses" description="Software licenses (LabelDesk) of the organisations you can see."
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Licenses']]">
    @if ($canManage)
        <x-slot:actions>
            <x-ui.button icon="plus" :href="route('licensing.create')">Add license</x-ui.button>
        </x-slot:actions>
    @endif

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('licensing.index') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.search-field name="q" :value="$search" placeholder="License, customer or domain" />
                <x-ui.filter-select label="Status" name="status" :selected="$status" x-data @change="$el.form.submit()"
                                    :options="['active' => 'Active', 'expiring' => 'Expiring (30 days)', 'expired' => 'Expired', 'pending' => 'Pending', 'suspended' => 'Suspended', 'revoked' => 'Revoked']" />
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>License</th>
            @if ($showOrganisation)<th>Customer</th>@endif
            <th>Product / plan</th><th>Type</th><th>Status</th><th class="text-right">Domains</th><th>Expires</th><th>Support until</th>
        </x-slot:head>

        @forelse ($licenses as $license)
            <tr>
                <td>
                    <a href="{{ route('licensing.show', $license) }}" class="font-medium hover:underline">{{ $license->number }}</a>
                    <div class="muted font-mono text-xs">{{ $license->maskedKey() }}</div>
                </td>
                @if ($showOrganisation)<td>{{ $license->organisation?->name ?? '—' }}</td>@endif
                <td>{{ $license->product?->name }} · {{ $license->plan?->name }}</td>
                <td>{{ ucfirst($license->billing_type) }}</td>
                <td><x-ui.badge :tone="$license->statusTone()">{{ ucfirst($license->displayStatus()) }}</x-ui.badge></td>
                <td class="text-right tabular-nums">{{ $license->production_used }} / {{ $license->domainLimit() }}</td>
                <td class="muted whitespace-nowrap">{{ $license->expires_at?->inDisplayTimezone()->format('d M Y') ?? 'Lifetime' }}</td>
                <td class="muted whitespace-nowrap">{{ $license->support_until?->inDisplayTimezone()->format('d M Y') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="!p-0"><x-ui.empty-state title="No licenses" icon="key-round" description="Licenses appear here when the storefront reports a sale or a Super Admin adds one." /></td></tr>
        @endforelse

        @if ($licenses->hasPages())
            <x-slot:footer>{{ $licenses->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
