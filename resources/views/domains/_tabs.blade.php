{{-- Tabs shared by All domains and Unassigned domains. Expects $active ('all'|'unassigned') and $unassignedCount (null = no access). --}}
@if ($unassignedCount !== null)
    <nav class="mb-4 flex gap-1 border-b border-zinc-200 dark:border-zinc-800" aria-label="Domain lists">
        @foreach ([
            'all' => ['All domains', route('domains.index'), null],
            'unassigned' => ['Unassigned', route('domains.unassigned'), $unassignedCount],
        ] as $key => [$label, $url, $count])
            <a href="{{ $url }}" @if ($active === $key) aria-current="page" @endif
               @class([
                   '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                   'border-accent-600 text-zinc-900 dark:text-zinc-50' => $active === $key,
                   'muted border-transparent hover:text-zinc-900 dark:hover:text-zinc-50' => $active !== $key,
               ])>
                {{ $label }}
                @if ($count)<x-ui.badge :tone="$active === $key ? 'warning' : 'neutral'" :dot="false">{{ $count }}</x-ui.badge>@endif
            </a>
        @endforeach
    </nav>
@endif
