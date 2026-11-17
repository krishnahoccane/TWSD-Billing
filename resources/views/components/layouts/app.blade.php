@props(['title' => null, 'breadcrumbs' => [], 'description' => null])
@php
    $flashes = collect(['success', 'error', 'warning', 'info'])
        ->filter(fn ($type) => session()->has($type))
        ->map(fn ($type) => ['type' => $type, 'message' => (string) session($type)])
        ->values();
@endphp
<x-layouts.base :title="$title">
    <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        <x-ui.sidebar />

        <div class="lg:pl-64">
            <x-ui.header :breadcrumbs="$breadcrumbs" />

            <main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                @if ($title)
                    <x-ui.page-header :title="$title" :description="$description">
                        @isset($actions)
                            <x-slot:actions>{{ $actions }}</x-slot:actions>
                        @endisset
                    </x-ui.page-header>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui.toasts :initial="$flashes" />
</x-layouts.base>
