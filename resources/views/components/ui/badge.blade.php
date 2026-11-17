@props(['tone' => 'neutral', 'dot' => true])
{{-- Status badge. tone: neutral | success | warning | danger | info --}}
@php
    $tones = [
        'neutral' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'bg-zinc-400'],
        'success' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400', 'bg-emerald-500'],
        'warning' => ['bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-400', 'bg-amber-500'],
        'danger' => ['bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-400', 'bg-red-500'],
        'info' => ['bg-accent-50 text-accent-700 dark:bg-accent-700/20 dark:text-accent-400', 'bg-accent-500'],
    ];
    [$surface, $dotColour] = $tones[$tone] ?? $tones['neutral'];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', $surface]) }}>
    @if ($dot)<span class="size-1.5 rounded-full {{ $dotColour }}" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
