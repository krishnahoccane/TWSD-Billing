@props(['href' => null, 'icon' => null, 'danger' => false])
@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm',
        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800' => ! $danger,
        'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40' => $danger,
    ]);
@endphp
@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4 shrink-0" />@endif
        <span class="truncate">{{ $slot }}</span>
    </a>
@else
    <button role="menuitem" {{ $attributes->merge(['type' => 'button'])->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4 shrink-0" />@endif
        <span class="truncate">{{ $slot }}</span>
    </button>
@endif
