<x-layouts.app title="Audit log" :description="$technicalOnly ? 'Technical actions (mailboxes, domains, integrations, queues).' : 'Every administrative action. Entries cannot be edited or deleted.'"
               :breadcrumbs="[['label' => 'System'], ['label' => 'Audit log']]">
    <x-slot:actions>
        <x-ui.button variant="secondary" icon="download" :href="route('audit.export', array_filter($filters))">Export CSV</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('audit.index') }}" class="flex w-full flex-wrap items-end gap-2">
                <x-ui.search-field name="action" placeholder="Action, e.g. account." :value="$filters['action'] ?? ''" />
                <input type="search" name="user" value="{{ $filters['user'] ?? '' }}" placeholder="User e-mail" aria-label="User e-mail" class="control w-auto py-1.5">
                <x-ui.filter-select label="Organisation" name="organisation" :options="$organisations" :selected="$filters['organisation'] ?? null" />
                <x-ui.filter-select label="Result" name="status" :options="['success' => 'Success', 'failure' => 'Failure', 'denied' => 'Denied']" :selected="$filters['status'] ?? null" />
                <x-ui.date-range :from="$filters['from'] ?? null" :to="$filters['to'] ?? null" />
                <x-ui.button type="submit" variant="secondary" size="sm">Filter</x-ui.button>
                @if (array_filter($filters))<x-ui.button variant="ghost" size="sm" :href="route('audit.index')">Clear</x-ui.button>@endif
            </form>
        </x-slot:toolbar>

        <x-slot:head><th>When</th><th>Action</th><th>By</th><th>Organisation</th><th>Resource</th><th>Result</th><th>Details</th></x-slot:head>

        @forelse ($entries as $entry)
            <tr>
                <td class="muted whitespace-nowrap" title="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->inDisplayTimezone()->format('d M Y H:i:s') }}</td>
                <td class="font-mono text-xs">{{ $entry->action }}</td>
                <td><div class="max-w-48 truncate">{{ $entry->user_email ?? 'System' }}</div>@if ($entry->user_role)<div class="muted text-xs">{{ $entry->user_role }}</div>@endif</td>
                <td>{{ $entry->organisation?->name ?? '—' }}</td>
                <td class="muted text-xs">{{ $entry->resource_type ? class_basename($entry->resource_type) : '—' }}</td>
                <td><x-ui.badge :tone="$entry->status === 'success' ? 'success' : ($entry->status === 'denied' ? 'warning' : 'danger')">{{ ucfirst($entry->status) }}</x-ui.badge></td>
                <td class="max-w-xs">
                    <details class="text-xs">
                        <summary class="cursor-pointer text-accent-600 dark:text-accent-400">View</summary>
                        @if ($entry->previous_data)<p class="muted mt-1">Before</p><pre class="overflow-x-auto rounded bg-zinc-100 p-2 dark:bg-zinc-800">{{ json_encode($entry->previous_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                        @if ($entry->new_data)<p class="muted mt-1">After</p><pre class="overflow-x-auto rounded bg-zinc-100 p-2 dark:bg-zinc-800">{{ json_encode($entry->new_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                        <p class="muted mt-1">IP {{ $entry->ip_address ?? '—' }} · request {{ $entry->request_id ?? '—' }}</p>
                        <p class="muted break-all">{{ $entry->user_agent }}</p>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No matching entries" icon="history" /></td></tr>
        @endforelse

        @if ($entries->hasPages())
            <x-slot:footer>{{ $entries->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
