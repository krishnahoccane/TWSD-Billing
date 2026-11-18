<x-layouts.app title="Integration logs" description="Every call made to Google Workspace and Zoho Books. Request bodies, passwords and tokens are never recorded."
               :breadcrumbs="[['label' => 'Integrations'], ['label' => 'Logs']]">
    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('integrations.logs') }}">
                <x-ui.filter-select label="Result" name="status" :selected="$status" x-data @change="$el.form.submit()"
                                    :options="['success' => 'Success', 'failure' => 'Failure']" />
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>When</th><th>Provider</th><th>Operation</th><th>Target</th><th>Result</th><th class="text-right">Duration</th><th>Error</th>
        </x-slot:head>

        @forelse ($logs as $log)
            <tr>
                <td class="muted whitespace-nowrap">{{ $log->created_at->inDisplayTimezone()->format('d M Y H:i:s') }}</td>
                <td><x-ui.badge :tone="$log->provider === 'google' ? 'info' : 'neutral'" :dot="false">{{ $log->provider }}</x-ui.badge></td>
                <td class="font-mono text-xs">{{ $log->operation }}</td>
                <td class="max-w-56 truncate">{{ $log->target ?? '—' }}</td>
                <td><x-ui.badge :tone="$log->status === 'success' ? 'success' : 'danger'">{{ ucfirst($log->status) }}</x-ui.badge></td>
                <td class="text-right tabular-nums">{{ number_format($log->duration_ms) }} ms</td>
                <td class="max-w-sm break-words text-xs">{{ $log->error_message ? $log->error_type.': '.$log->error_message : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No calls recorded" icon="scroll-text" /></td></tr>
        @endforelse

        @if ($logs->hasPages())
            <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    <x-ui.card title="Zoho Books calls (latest 50)" :padding="false" class="mt-6">
        @if ($zohoLogs->isEmpty())
            <x-ui.empty-state title="No Zoho Books calls recorded" icon="scroll-text" />
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>When</th><th>Provider</th><th>Operation</th><th>Target</th><th>Result</th><th class="text-right">Duration</th><th>Error</th></tr></thead>
                    <tbody>
                        @foreach ($zohoLogs as $log)
                            <tr>
                                <td class="muted whitespace-nowrap">{{ $log->created_at->inDisplayTimezone()->format('d M Y H:i:s') }}</td>
                                <td><x-ui.badge :tone="$log->provider === 'zoho' ? 'info' : 'neutral'" :dot="false">{{ $log->provider }}</x-ui.badge></td>
                                <td class="font-mono text-xs">{{ $log->operation }}</td>
                                <td class="max-w-56 truncate">{{ $log->target ?? '—' }}</td>
                                <td><x-ui.badge :tone="$log->status === 'success' ? 'success' : 'danger'">{{ ucfirst($log->status) }}</x-ui.badge></td>
                                <td class="text-right tabular-nums">{{ number_format($log->duration_ms) }} ms</td>
                                <td class="max-w-sm break-words text-xs">{{ $log->error ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</x-layouts.app>
