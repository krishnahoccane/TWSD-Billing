@props(['items' => []])
{{-- items: [['label' => 'Organisations', 'url' => route(...)], ['label' => 'Acme']] --}}
@if ($items)
    <nav aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5 text-sm">
            @foreach ($items as $item)
                @if (! $loop->first)
                    <li class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">
                        <x-ui.icon name="chevron-right" class="size-3.5" />
                    </li>
                @endif
                <li class="truncate">
                    @if (! $loop->last && ! empty($item['url']))
                        <a href="{{ $item['url'] }}" class="muted hover:text-zinc-900 dark:hover:text-zinc-100">{{ $item['label'] }}</a>
                    @else
                        <span @class(['font-medium' => $loop->last, 'muted' => ! $loop->last])
                              @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
