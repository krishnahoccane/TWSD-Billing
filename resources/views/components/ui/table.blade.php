{{--
    Data table shell. Scrolls horizontally on small screens.

    <x-ui.table>
        <x-slot:toolbar> search / filters / export </x-slot:toolbar>
        <x-slot:head><th>Name</th><th>Status</th></x-slot:head>
        <tr><td>…</td><td>…</td></tr>
        <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
    </x-ui.table>
--}}
<div {{ $attributes->class('surface overflow-hidden') }}>
    @isset($toolbar)
        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            {{ $toolbar }}
        </div>
    @endisset

    <div class="overflow-x-auto">
        <table class="data-table">
            @isset($head)
                <thead><tr>{{ $head }}</tr></thead>
            @endisset
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @isset($footer)
        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">{{ $footer }}</div>
    @endisset
</div>
