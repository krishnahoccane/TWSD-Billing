@props(['align' => 'right', 'width' => 'w-56'])
{{--
    The panel is teleported to <body> and positioned with Alpine's anchor
    plugin, so it is never clipped by a scrolling table or card.
--}}
<div class="inline-flex" x-data="{ open: false }" @keydown.escape.window="open = false">
    <div x-ref="trigger" class="inline-flex" @click="open = !open" :aria-expanded="open">{{ $trigger }}</div>

    <template x-teleport="body">
        <div x-cloak x-show="open"
             @if ($align === 'left') x-anchor.bottom-start.offset.6="$refs.trigger" @else x-anchor.bottom-end.offset.6="$refs.trigger" @endif
             @click="open = false"
             @click.outside="if (! $refs.trigger.contains($event.target)) open = false"
             class="surface z-50 max-w-[calc(100vw-2rem)] overflow-hidden py-1 shadow-lg {{ $width }}"
             role="menu">
            {{ $slot }}
        </div>
    </template>
</div>
