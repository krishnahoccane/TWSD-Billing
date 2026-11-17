<x-layouts.app title="Organisation activity" description="Audited changes to organisations, their managers, contacts and domains."
               :breadcrumbs="[['label' => 'Organisations', 'url' => route('organisations.index')], ['label' => 'Activity']]">
    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('organisations.activity') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.filter-select label="Organisation" name="organisation" :options="$organisations"
                                    :selected="$selected?->uuid" all="All organisations" x-data @change="$el.form.submit()" />
                <noscript><x-ui.button type="submit" variant="secondary" size="sm">Apply</x-ui.button></noscript>
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>When</th><th>Organisation</th><th>Action</th><th>By</th><th>Status</th><th>Details</th>
        </x-slot:head>

        @forelse ($entries as $entry)
            <tr>
                <td class="muted whitespace-nowrap" title="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('d M Y H:i') }}</td>
                <td>{{ $entry->organisation?->name ?? '—' }}</td>
                <td class="font-mono text-xs">{{ $entry->action }}</td>
                <td>
                    <div class="max-w-48 truncate">{{ $entry->user_email ?? 'System' }}</div>
                    @if ($entry->user_role)<div class="muted text-xs">{{ $entry->user_role }}</div>@endif
                </td>
                <td>
                    <x-ui.badge :tone="$entry->status === 'success' ? 'success' : ($entry->status === 'denied' ? 'warning' : 'danger')">
                        {{ ucfirst($entry->status) }}
                    </x-ui.badge>
                </td>
                <td class="max-w-xs">
                    @if ($entry->new_data || $entry->previous_data)
                        <details class="text-xs">
                            <summary class="cursor-pointer text-accent-600 dark:text-accent-400">View</summary>
                            @if ($entry->previous_data)
                                <p class="muted mt-1">Before</p>
                                <pre class="overflow-x-auto rounded bg-zinc-100 p-2 dark:bg-zinc-800">{{ json_encode($entry->previous_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @endif
                            @if ($entry->new_data)
                                <p class="muted mt-1">After</p>
                                <pre class="overflow-x-auto rounded bg-zinc-100 p-2 dark:bg-zinc-800">{{ json_encode($entry->new_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @endif
                            <p class="muted mt-1">Request {{ $entry->request_id ?? '—' }}</p>
                        </details>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="!p-0">
                <x-ui.empty-state title="No activity recorded" icon="history" description="Changes appear here as they happen." />
            </td></tr>
        @endforelse

        @if ($entries->hasPages())
            <x-slot:footer>{{ $entries->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
