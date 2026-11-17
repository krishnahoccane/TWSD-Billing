@php
    $titles = [
        'all' => ['Email accounts', 'Google Workspace mailboxes you have access to.'],
        'suspended' => ['Suspended accounts', 'Suspended mailboxes remain billable.'],
        'deleted' => ['Deleted accounts', 'Soft-deleted and permanently deleted mailboxes. Soft-deleted mailboxes still exist in Google.'],
        'unassigned' => ['Unassigned accounts', 'Mailboxes found in Google Workspace whose domain is not mapped to an organisation. Assign each one explicitly.'],
    ];
    [$title, $description] = $titles[$preset] ?? $titles['all'];
@endphp
<x-layouts.app :title="$title" :description="$description"
               :breadcrumbs="[['label' => 'Email accounts', 'url' => route('accounts.index')], ['label' => $title]]">
    @if (auth()->user()->hasAbilityAnywhere(\App\Modules\AccessControl\Enums\Ability::AccountsCreate))
        <x-slot:actions>
            <x-ui.button :href="route('accounts.create')" icon="mail-plus">Create email</x-ui.button>
        </x-slot:actions>
    @endif

    <livewire:account-table :preset="$preset" />
</x-layouts.app>
