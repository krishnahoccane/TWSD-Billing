@props(['name', 'title' => null, 'maxWidth' => 'max-w-md'])
{{--
    Open:  $dispatch('open-modal', 'name')      Close: $dispatch('close-modal', 'name') or Escape
--}}
<div x-data="{ open: false }" x-cloak x-show="open"
     @open-modal.window="if ($event.detail === @js($name)) { open = true; $nextTick(() => $refs.panel.focus()) }"
     @close-modal.window="if ($event.detail === @js($name)) open = false"
     @keydown.escape.window="open = false"
     class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
     role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-zinc-950/50" @click="open = false" aria-hidden="true"></div>

    <div x-ref="panel" tabindex="-1" x-show="open" x-transition x-trap.noscroll="open"
         class="surface relative w-full {{ $maxWidth }} shadow-xl outline-none">
        @if ($title)
            <header class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">{{ $title }}</h2>
                <button type="button" class="muted rounded-md p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        @click="open = false" aria-label="Close">
                    <x-ui.icon name="x" class="size-4" />
                </button>
            </header>
        @endif

        <div class="p-4">{{ $slot }}</div>

        @isset($footer)
            <footer class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">{{ $footer }}</footer>
        @endisset
    </div>
</div>
