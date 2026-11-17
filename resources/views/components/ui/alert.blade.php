@props(['tone' => 'info', 'title' => null])
@php
    $tones = [
        'info' => ['border-accent-100 bg-accent-50 text-accent-700 dark:border-accent-700/40 dark:bg-accent-700/10 dark:text-accent-400', 'info'],
        'success' => ['border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-400', 'circle-check'],
        'warning' => ['border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400', 'triangle-alert'],
        'danger' => ['border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400', 'circle-alert'],
    ];
    [$classes, $icon] = $tones[$tone] ?? $tones['info'];
@endphp
<div {{ $attributes->class(['flex gap-2.5 rounded-md border px-3 py-2.5 text-sm', $classes]) }}
     role="{{ in_array($tone, ['danger', 'warning']) ? 'alert' : 'status' }}">
    <x-ui.icon :name="$icon" class="mt-0.5 size-4 shrink-0" />
    <div class="min-w-0">
        @if ($title)<p class="font-medium">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
