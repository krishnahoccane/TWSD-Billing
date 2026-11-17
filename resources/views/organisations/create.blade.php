<x-layouts.app title="Add organisation"
               :breadcrumbs="[['label' => 'Organisations', 'url' => route('organisations.index')], ['label' => 'Add']]">
    @include('organisations._form')
</x-layouts.app>
