<div>
    <x-ui.table>
        <x-slot:toolbar>
            <x-ui.search-field placeholder="Search address or name…" wire:model.live.debounce.300ms="search" />
            @if ($preset === 'all')
                <x-ui.filter-select label="Status" wire:model.live="status" :options="$statuses" all="All current" />
            @endif
            @if ($organisations)
                <x-ui.filter-select label="Organisation" wire:model.live="organisation" :options="$organisations" all="All" />
            @endif
            <x-ui.loading-state wire:loading.flex class="hidden !p-0" label="Updating…" />
        </x-slot:toolbar>

        <x-slot:head>
            <th>Email</th>
            <th>Name</th>
            @if ($showOrganisation)<th>Organisation</th>@endif
            <th>Status</th>
            <th>Billing</th>
            <th>Last synced</th>
        </x-slot:head>

        @forelse ($accounts as $account)
            <tr wire:key="acct-{{ $account->uuid }}">
                <td>
                    <a href="{{ route('accounts.show', $account) }}" class="font-medium hover:underline">{{ $account->primary_email }}</a>
                    @if ($account->missing_from_google_at)
                        <div class="text-xs text-red-600 dark:text-red-400">Not found in Google at last full sync</div>
                    @endif
                </td>
                <td>{{ $account->fullName() }}</td>
                @if ($showOrganisation)
                    <td>
                        @if ($account->organisation)
                            {{ $account->organisation->name }}
                        @else
                            <x-ui.badge tone="warning">Unassigned</x-ui.badge>
                        @endif
                    </td>
                @endif
                <td><x-ui.badge :tone="$account->account_status->tone()">{{ $account->account_status->label() }}</x-ui.badge></td>
                <td class="muted">{{ $account->billing_status->label() }}</td>
                <td class="muted whitespace-nowrap">{{ $account->last_synced_at?->diffForHumans() ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="!p-0">
                    <x-ui.empty-state :title="$search !== '' || $status !== '' ? 'No accounts match' : 'No accounts here'" icon="mail"
                                      :description="$preset === 'unassigned' ? 'Every imported mailbox is assigned to an organisation.' : 'Mailboxes appear here once they are created or imported from Google Workspace.'" />
                </td>
            </tr>
        @endforelse

        @if ($accounts->hasPages())
            <x-slot:footer>{{ $accounts->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</div>
