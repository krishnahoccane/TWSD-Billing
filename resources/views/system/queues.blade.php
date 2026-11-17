<x-layouts.app title="Queue monitoring" :description="'Driver: '.$driver.'. Jobs that finally failed stay here until retried or discarded.'"
               :breadcrumbs="[['label' => 'System'], ['label' => 'Queues']]">
    @if ($driver === 'sync')
        <x-ui.alert tone="warning" class="mb-6">The <span class="font-mono text-xs">sync</span> driver runs jobs inside the web request — fine for development, not for production.</x-ui.alert>
    @endif

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
        @foreach ($sizes as $queue => $size)
            <x-ui.stat-card :label="'Queue: '.$queue" :value="$size === null ? '—' : number_format($size)" icon="list-checks" />
        @endforeach
    </div>

    <x-ui.table class="mt-6">
        <x-slot:head><th>Failed at</th><th>Queue</th><th>Job</th><th>Error</th><th></th></x-slot:head>
        @forelse ($failed as $job)
            <tr>
                <td class="muted whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->format('d M Y H:i') }}</td>
                <td>{{ $job->queue }}</td>
                <td class="font-mono text-xs">{{ $job->job }}</td>
                <td class="max-w-md break-words text-xs">{{ $job->error }}</td>
                <td class="text-right whitespace-nowrap">
                    <form method="POST" action="{{ route('system.queues.retry', $job->uuid) }}" class="inline">@csrf <x-ui.button type="submit" size="sm" variant="secondary">Retry</x-ui.button></form>
                    <form method="POST" action="{{ route('system.queues.forget', $job->uuid) }}" class="inline">@csrf @method('DELETE') <x-ui.button type="submit" size="sm" variant="ghost">Discard</x-ui.button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="!p-0"><x-ui.empty-state title="No failed jobs" icon="circle-check" /></td></tr>
        @endforelse
        @if ($failed->hasPages())
            <x-slot:footer>{{ $failed->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
