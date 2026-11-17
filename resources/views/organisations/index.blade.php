<x-layouts.app title="Organisations" description="Every organisation you have access to."
               :breadcrumbs="[['label' => 'Organisations']]">
    @can('create', \App\Modules\Organisations\Models\Organisation::class)
        <x-slot:actions>
            <x-ui.button :href="route('organisations.create')" icon="plus">Add organisation</x-ui.button>
        </x-slot:actions>
    @endcan

    <livewire:organisation-table />
</x-layouts.app>
