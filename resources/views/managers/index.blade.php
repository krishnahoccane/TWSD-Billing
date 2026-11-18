<x-layouts.app title="Organisation managers" description="Every person with access to an organisation, and the role they hold there."
               :breadcrumbs="[['label' => 'Organisations', 'url' => route('organisations.index')], ['label' => 'Managers']]">
    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('managers.index') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.search-field placeholder="Search person or organisation…" :value="$search" />
                <x-ui.button type="submit" variant="secondary" size="sm">Search</x-ui.button>
                @if ($search !== '')
                    <x-ui.button variant="ghost" size="sm" :href="route('managers.index')">Clear</x-ui.button>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Person</th><th>Organisation</th><th>Role</th><th>Account</th><th>Granted</th>
        </x-slot:head>

        @forelse ($assignments as $assignment)
            <tr>
                <td>
                    <div class="font-medium">{{ $assignment->user->name }}</div>
                    <div class="muted text-xs">{{ $assignment->user->email }}</div>
                </td>
                <td>
                    <a href="{{ route('organisations.show', $assignment->organisation) }}#members" class="hover:underline">{{ $assignment->organisation->name }}</a>
                    <div class="muted font-mono text-xs">{{ $assignment->organisation->code }}</div>
                </td>
                <td>{{ $assignment->role->label }}</td>
                <td>
                    <x-ui.badge :tone="$assignment->user->is_active ? 'success' : 'neutral'">
                        {{ $assignment->user->is_active ? 'Active' : 'Deactivated' }}
                    </x-ui.badge>
                </td>
                <td class="muted whitespace-nowrap">{{ $assignment->created_at->inDisplayTimezone()->format('d M Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="!p-0">
                <x-ui.empty-state title="No assignments found" icon="users"
                                  description="Grant access from an organisation's page under “Managers and access”." />
            </td></tr>
        @endforelse

        @if ($assignments->hasPages())
            <x-slot:footer>{{ $assignments->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
