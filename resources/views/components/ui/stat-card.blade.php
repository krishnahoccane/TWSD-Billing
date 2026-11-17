@props(['label', 'value', 'icon' => null, 'hint' => null, 'href' => null])
<div {{ $attributes->class('surface p-4') }}>
    <div class="flex items-center justify-between gap-2">
        <p class="muted truncate text-xs font-medium">{{ $label }}</p>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />@endif
    </div>
    <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{{ $value }}</p>
    @if ($hint || $href)
        <p class="muted mt-1 text-xs">
            @if ($href)<a href="{{ $href }}" class="text-accent-600 hover:underline dark:text-accent-400">{{ $hint ?? 'View' }}</a>@else{{ $hint }}@endif
        </p>
    @endif
</div>
