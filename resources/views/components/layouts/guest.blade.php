@props(['title' => null, 'heading' => null, 'subheading' => null])
<x-layouts.base :title="$title">
    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex size-8 items-center justify-center rounded-md bg-accent-600 text-white">
                    <x-ui.icon name="layout-dashboard" class="size-4" />
                </span>
                <span class="text-base font-semibold tracking-tight">{{ config('app.name') }}</span>
            </div>

            <div class="surface p-6 shadow-xs">
                @if ($heading)
                    <h1 class="text-lg font-semibold tracking-tight">{{ $heading }}</h1>
                @endif
                @if ($subheading)
                    <p class="muted mt-1 text-sm">{{ $subheading }}</p>
                @endif

                <div @class(['mt-5' => $heading || $subheading])>
                    {{ $slot }}
                </div>
            </div>

            @isset($footer)
                <div class="muted mt-4 text-center text-sm">{{ $footer }}</div>
            @endisset
        </div>
    </main>
</x-layouts.base>
