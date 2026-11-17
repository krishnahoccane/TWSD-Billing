@props(['title', 'description' => null, 'icon' => 'inbox'])
<div {{ $attributes->class('flex flex-col items-center px-4 py-10 text-center') }}>
    <span class="flex size-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
        <x-ui.icon :name="$icon" class="size-5" />
    </span>
    <p class="mt-3 text-sm font-medium">{{ $title }}</p>
    @if ($description)<p class="muted mt-1 max-w-sm text-sm">{{ $description }}</p>@endif
    @if ($slot->isNotEmpty())<div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>@endif
</div>
