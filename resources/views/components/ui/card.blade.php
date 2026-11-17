@props(['title' => null, 'description' => null, 'padding' => true])
<section {{ $attributes->class('surface') }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <div class="min-w-0">
                @if ($title)<h2 class="text-sm font-semibold">{{ $title }}</h2>@endif
                @if ($description)<p class="muted mt-0.5 text-xs">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-4' => $padding])>{{ $slot }}</div>
    @isset($footer)
        <footer class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">{{ $footer }}</footer>
    @endisset
</section>
