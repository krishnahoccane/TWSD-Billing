@php
    $verification = [
        'verified' => ['Verified', 'success'],
        'pending' => ['In Google, not verified', 'warning'],
        'not_found' => ['Not found in Google', 'danger'],
        'unverified' => ['Not checked with Google', 'neutral'],
        'removed' => ['Removed', 'neutral'],
    ];
@endphp
<x-layouts.app title="Domains" description="Domains that belong to the organisations you can see. Mailboxes are created on these domains."
               :breadcrumbs="[['label' => 'Domains']]">
    @if ($canRequest || $canSync)
        <x-slot:actions>
            @if ($canSync)@include('domains._sync-button')@endif
            @if ($canRequest)
                <x-ui.button :href="route('domains.requests.create')" icon="plus">Request new domain</x-ui.button>
            @endif
        </x-slot:actions>
    @endif

    @include('domains._tabs', ['active' => 'all'])

    @if ($unassignedCount)
        <x-ui.alert tone="warning" class="mb-4" title="{{ $unassignedCount }} domain(s) in Google Workspace are not assigned to an organisation">
            Mailboxes on these domains stay unassigned and are not billed. <a href="{{ route('domains.unassigned') }}" class="font-medium underline">Assign them</a>.
        </x-ui.alert>
    @endif

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('domains.index') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.search-field placeholder="Search domain…" :value="$search" />
                <x-ui.button type="submit" variant="secondary" size="sm">Search</x-ui.button>
            </form>
            <x-ui.button variant="ghost" size="sm" class="ml-auto" :href="route('domains.requests.index')">Domain requests</x-ui.button>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Domain</th>
            @if ($showOrganisation)<th>Organisation</th>@endif
            <th>Type</th><th>Status</th><th>Google verification</th><th>Added</th>
        </x-slot:head>

        @forelse ($domains as $domain)
            @php([$label, $tone] = $verification[$domain->verification_status] ?? [ucfirst($domain->verification_status), 'neutral'])
            <tr>
                <td>
                    <a href="{{ route('domains.show', $domain) }}" class="font-medium hover:underline">{{ $domain->domain_name }}</a>
                    @if ($domain->is_primary)<x-ui.badge tone="info" :dot="false" class="ml-1">Primary</x-ui.badge>@endif
                </td>
                @if ($showOrganisation)<td>{{ $domain->organisation?->name ?? '—' }}</td>@endif
                <td>{{ $domain->domain_type->label() }}</td>
                <td><x-ui.badge :tone="$domain->status === 'active' ? 'success' : 'neutral'">{{ ucfirst($domain->status) }}</x-ui.badge></td>
                <td><x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge></td>
                <td class="muted whitespace-nowrap">{{ $domain->created_at->inDisplayTimezone()->format('d M Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="!p-0">
                <x-ui.empty-state title="No domains yet" icon="globe"
                                  description="Request a new domain, or ask a Super Admin to map a domain that is already in Google Workspace.">
                    @if ($canRequest)
                        <x-ui.button size="sm" icon="plus" :href="route('domains.requests.create')">Request new domain</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </td></tr>
        @endforelse

        @if ($domains->hasPages())
            <x-slot:footer>{{ $domains->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
