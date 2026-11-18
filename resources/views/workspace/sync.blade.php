<x-layouts.app title="Synchronisation" description="Import and reconciliation between Google Workspace and this system."
               :breadcrumbs="[['label' => 'Google Workspace', 'url' => route('workspace.overview')], ['label' => 'Synchronisation']]">
    @if ($mode === 'mock')
        <x-ui.alert tone="warning" class="mb-6" title="Mock mode — not connected to Google">
            Synchronisation runs against the built-in mock directory. Nothing is read from or written to your Google Workspace.
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Operations stuck over 10 min" :value="$stuck" icon="clock" />
        <x-ui.stat-card label="Accounts in a failed state" :value="$failed" icon="triangle-alert" />
        <x-ui.stat-card label="Failed Google jobs" :value="$failedJobs" icon="list-checks" />
        <x-ui.stat-card label="API errors (24 h)" :value="$recentErrors" icon="scroll-text"
                        :href="route('integrations.logs', ['status' => 'failure'])" hint="View log" />
    </div>

    <x-ui.card title="Run now" class="mt-6"
               description="Runs immediately — keep this page open until it finishes. Ownership is never changed by synchronisation; unmatched mailboxes go to Unassigned accounts and unmatched domains to Unassigned domains.">
        <div class="flex flex-wrap gap-2" x-data="{ busy: false }">
            @foreach ($jobs as $type => $label)
                <form method="POST" action="{{ route('workspace.sync.run') }}" x-on:submit="busy = true">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <x-ui.button type="submit" variant="secondary" icon="refresh-cw" x-bind:disabled="busy">{{ $label }}</x-ui.button>
                </form>
            @endforeach
            <span x-show="busy" x-cloak class="muted self-center text-sm">Running… this can take a minute.</span>
        </div>
        <p class="muted mt-3 text-xs">
            Scheduled (needs the cron jobs): users every 15 minutes, stuck-operation retry every 30 minutes, domains every 6 hours, full import daily.
        </p>
    </x-ui.card>

    <x-ui.table class="mt-6">
        <x-slot:head>
            <th>Started</th><th>Type</th><th>Status</th><th class="text-right">Pages</th><th class="text-right">Fetched</th>
            <th class="text-right">Created</th><th class="text-right">Updated</th><th class="text-right">Unassigned</th>
            <th class="text-right">Missing</th><th class="text-right">Errors</th><th>By</th>
        </x-slot:head>

        @forelse ($logs as $log)
            <tr>
                <td class="muted whitespace-nowrap">{{ $log->started_at?->inDisplayTimezone()->format('d M Y H:i') }}</td>
                <td class="whitespace-nowrap">{{ str_replace('_', ' ', $log->type) }}</td>
                <td>
                    <x-ui.badge :tone="$log->status === 'completed' ? 'success' : ($log->status === 'failed' ? 'danger' : 'info')">{{ ucfirst($log->status) }}</x-ui.badge>
                    @if ($log->error_message)<div class="mt-1 max-w-xs break-words text-xs text-red-600 dark:text-red-400">{{ $log->error_message }}</div>@endif
                </td>
                <td class="text-right tabular-nums">{{ $log->pages }}</td>
                <td class="text-right tabular-nums">{{ $log->fetched }}</td>
                <td class="text-right tabular-nums">{{ $log->created }}</td>
                <td class="text-right tabular-nums">{{ $log->updated }}</td>
                <td class="text-right tabular-nums">{{ $log->unassigned }}</td>
                <td class="text-right tabular-nums">{{ $log->missing }}</td>
                <td class="text-right tabular-nums">{{ $log->errors }}</td>
                <td class="muted">{{ $log->triggeredBy?->name ?? 'Schedule' }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="!p-0">
                <x-ui.empty-state title="No synchronisation has run yet" icon="refresh-cw" description="Run a full user import to load the directory." />
            </td></tr>
        @endforelse

        @if ($logs->hasPages())
            <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
