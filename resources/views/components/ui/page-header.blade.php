@props(['title', 'description' => null])
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold tracking-tight">{{ $title }}</h1>
        @if ($description)
            <p class="muted mt-1 text-sm">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
