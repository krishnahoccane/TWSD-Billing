@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null])
@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap transition-colors',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'px-2.5 py-1.5 text-xs' => $size === 'sm',
        'px-3 py-2 text-sm' => $size === 'md',
        'bg-accent-600 text-white shadow-xs hover:bg-accent-700' => $variant === 'primary',
        'border border-zinc-300 bg-white text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800' => $variant === 'secondary',
        'bg-red-600 text-white shadow-xs hover:bg-red-700' => $variant === 'danger',
        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-50' => $variant === 'ghost',
    ]);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
