<x-layouts.app :title="'Edit '.$organisation->name"
               :breadcrumbs="[
                   ['label' => 'Organisations', 'url' => route('organisations.index')],
                   ['label' => $organisation->name, 'url' => route('organisations.show', $organisation)],
                   ['label' => 'Edit'],
               ]">
    @include('organisations._form')
</x-layouts.app>
