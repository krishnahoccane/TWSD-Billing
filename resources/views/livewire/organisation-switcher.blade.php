<div>
    @if ($organisations->count() > 1 || ($allowAll && $organisations->isNotEmpty()))
        <x-ui.dropdown width="w-64">
            <x-slot:trigger>
                <button type="button"
                        class="flex max-w-48 items-center gap-2 rounded-md border border-zinc-200 px-2.5 py-1.5 text-sm hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800"
                        aria-label="Switch organisation">
                    <x-ui.icon name="building-2" class="muted size-4 shrink-0" />
                    <span class="truncate">{{ $current?->name ?? 'All organisations' }}</span>
                    <x-ui.icon name="chevrons-up-down" class="muted size-3.5 shrink-0" />
                </button>
            </x-slot:trigger>

            <div class="max-h-72 overflow-y-auto">
                @if ($allowAll)
                    <x-ui.dropdown.item wire:click="select(null)" :icon="$current === null ? 'check' : null">
                        All organisations
                    </x-ui.dropdown.item>
                @endif
                @foreach ($organisations as $organisation)
                    <x-ui.dropdown.item wire:key="org-{{ $organisation->uuid }}"
                                        wire:click="select('{{ $organisation->uuid }}')"
                                        :icon="$current?->is($organisation) ? 'check' : null">
                        {{ $organisation->name }}
                    </x-ui.dropdown.item>
                @endforeach
            </div>
        </x-ui.dropdown>
    @elseif ($current)
        <span class="muted hidden max-w-48 items-center gap-2 truncate px-2 text-sm sm:flex">
            <x-ui.icon name="building-2" class="size-4 shrink-0" />
            <span class="truncate">{{ $current->name }}</span>
        </span>
    @endif
</div>
