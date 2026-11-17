@php
    $titles = [
        'all' => ['Domain requests', 'Requests to add or remove a domain, and their progress.'],
        'pending' => ['Pending approvals', 'Requests waiting for a Super Admin decision. A request cannot be approved by the person who submitted it.'],
        'verification' => ['Domain verification', 'Approved requests that are waiting for DNS verification, in progress, or failed.'],
    ];
    [$title, $description] = $titles[$filter];
@endphp
<x-layouts.app :title="$title" :description="$description"
               :breadcrumbs="[['label' => 'Domains', 'url' => route('domains.index')], ['label' => $title]]">
    @if ($canRequest)
        <x-slot:actions>
            <x-ui.button :href="route('domains.requests.create')" icon="plus">Request new domain</x-ui.button>
        </x-slot:actions>
    @endif

    @if ($mode === 'mock' && $filter !== 'all')
        <x-ui.alert tone="warning" class="mb-6" title="Mock mode — not connected to Google">
            Verification and domain changes are simulated. Nothing is checked with or added to your Google Workspace.
        </x-ui.alert>
    @endif

    <x-ui.table>
        <x-slot:head>
            <th>Domain</th><th>Request</th>
            @if ($showOrganisation)<th>Organisation</th>@endif
            <th>Status</th><th>Requested by</th><th>Submitted</th>
        </x-slot:head>

        @forelse ($requests as $item)
            <tr>
                <td><a href="{{ route('domains.requests.show', $item) }}" class="font-medium hover:underline">{{ $item->domain_name }}</a></td>
                <td>{{ $item->isRemoval() ? 'Remove' : 'Add' }}</td>
                @if ($showOrganisation)<td>{{ $item->organisation?->name ?? '—' }}</td>@endif
                <td><x-ui.badge :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.badge></td>
                <td>{{ $item->requester?->name ?? '—' }}</td>
                <td class="muted whitespace-nowrap">{{ $item->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="!p-0">
                <x-ui.empty-state :title="$filter === 'all' ? 'No domain requests yet' : 'Nothing waiting here'" icon="inbox" />
            </td></tr>
        @endforelse

        @if ($requests->hasPages())
            <x-slot:footer>{{ $requests->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
