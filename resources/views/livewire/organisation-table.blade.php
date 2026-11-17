<div>
    <x-ui.table>
        <x-slot:toolbar>
            <x-ui.search-field placeholder="Search name, code or email…" wire:model.live.debounce.300ms="search" />
            <x-ui.filter-select label="Status" wire:model.live="status"
                                :options="['active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive']" />
            <x-ui.loading-state wire:loading.flex class="hidden !p-0" label="Updating…" />
            <x-ui.button variant="secondary" size="sm" icon="download" class="ml-auto"
                         :href="route('organisations.export', array_filter(['search' => $search, 'status' => $status]))">
                Export CSV
            </x-ui.button>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Organisation</th>
            <th>Code</th>
            <th>Status</th>
            <th class="text-right">Users</th>
            <th class="text-right">Domains</th>
            <th>Location</th>
            <th>Created</th>
        </x-slot:head>

        @forelse ($organisations as $organisation)
            <tr wire:key="org-{{ $organisation->uuid }}">
                <td>
                    <a href="{{ route('organisations.show', $organisation) }}" class="font-medium hover:underline">{{ $organisation->name }}</a>
                    @if ($organisation->legal_name && $organisation->legal_name !== $organisation->name)
                        <div class="muted text-xs">{{ $organisation->legal_name }}</div>
                    @endif
                </td>
                <td class="font-mono text-xs">{{ $organisation->code }}</td>
                <td><x-ui.badge :tone="$organisation->status->tone()">{{ $organisation->status->label() }}</x-ui.badge></td>
                <td class="text-right tabular-nums">{{ $organisation->members_count }}</td>
                <td class="text-right tabular-nums">{{ $organisation->domains_count }}</td>
                <td class="muted">{{ collect([$organisation->city, $organisation->state])->filter()->implode(', ') ?: '—' }}</td>
                <td class="muted whitespace-nowrap">{{ $organisation->created_at->format('d M Y') }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="!p-0">
                    @if ($search !== '' || $status !== '')
                        <x-ui.empty-state title="No organisations match" icon="search" description="Try a different search or clear the filters." />
                    @else
                        <x-ui.empty-state title="No organisations yet" icon="building-2"
                                          description="Organisations appear here once they are created.">
                            @if ($canCreate)
                                <x-ui.button size="sm" icon="plus" :href="route('organisations.create')">Add organisation</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </td>
            </tr>
        @endforelse

        @if ($organisations->hasPages())
            <x-slot:footer>{{ $organisations->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</div>
