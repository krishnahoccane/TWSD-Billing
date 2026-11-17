@php
    $sections = app(\App\Support\Navigation\NavigationBuilder::class)->for(auth()->user());
@endphp

{{-- Mobile backdrop --}}
<div x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
     class="fixed inset-0 z-30 bg-zinc-950/50 lg:hidden" aria-hidden="true"></div>

<aside
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-zinc-200 bg-white transition-transform duration-200 lg:translate-x-0 dark:border-zinc-800 dark:bg-zinc-900"
    :class="{ 'translate-x-0': sidebarOpen }"
    aria-label="Primary navigation"
>
    <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-zinc-200 px-4 dark:border-zinc-800">
        <span class="flex size-7 items-center justify-center rounded-md bg-accent-600 text-white">
            <x-ui.icon name="layout-dashboard" class="size-4" />
        </span>
        <span class="truncate text-sm font-semibold tracking-tight">{{ config('app.name') }}</span>
        <button type="button" class="muted ml-auto rounded-md p-1 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800"
                @click="sidebarOpen = false" aria-label="Close navigation">
            <x-ui.icon name="x" class="size-4" />
        </button>
    </div>

    <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
        @foreach ($sections as $section)
            <div>
                @if ($section['heading'])
                    <p class="px-2 pb-1.5 text-xs font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                        {{ $section['heading'] }}
                    </p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($section['items'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               @class([
                                   'flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors',
                                   'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-50' => $item['active'],
                                   'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' => ! $item['active'],
                               ])>
                                <x-ui.icon :name="$item['icon']" class="size-4 shrink-0" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="muted shrink-0 border-t border-zinc-200 px-4 py-3 text-xs dark:border-zinc-800">
        {{ app()->environment('production') ? 'Production' : ucfirst(app()->environment()).' environment' }}
    </div>
</aside>
