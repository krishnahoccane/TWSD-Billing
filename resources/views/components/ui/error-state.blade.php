@props(['title' => 'Something went wrong', 'description' => null])
<div {{ $attributes->class('flex flex-col items-center px-4 py-10 text-center') }} role="alert">
    <span class="flex size-10 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400">
        <x-ui.icon name="triangle-alert" class="size-5" />
    </span>
    <p class="mt-3 text-sm font-medium">{{ $title }}</p>
    @if ($description)<p class="muted mt-1 max-w-sm text-sm">{{ $description }}</p>@endif
    @if ($slot->isNotEmpty())<div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>@endif
</div>
