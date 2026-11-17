@php use App\Modules\GoogleWorkspace\Enums\AccountStatus; @endphp
<x-layouts.app title="Google Workspace" description="Mailbox inventory across the organisations you can see."
               :breadcrumbs="[['label' => 'Google Workspace']]">
    @if ($mode === 'mock')
        <x-ui.alert tone="warning" class="mb-6" title="Mock mode — not connected to Google">
            These figures come from the built-in mock directory, not from your Google Workspace. No real mailbox is created or changed.
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total accounts" :value="number_format($total)" icon="mail" :href="route('accounts.index')" hint="View all" />
        <x-ui.stat-card label="Active" :value="number_format($counts[AccountStatus::Active->value] ?? 0)" icon="circle-check" />
        <x-ui.stat-card label="Suspended" :value="number_format($counts[AccountStatus::Suspended->value] ?? 0)" icon="pause-circle"
                        :href="route('accounts.suspended')" hint="View" />
        <x-ui.stat-card label="Soft deleted" :value="number_format($counts[AccountStatus::SoftDeleted->value] ?? 0)" icon="archive"
                        :href="route('accounts.deleted')" hint="View" />
        @if ($unassigned !== null)
            <x-ui.stat-card label="Unassigned" :value="number_format($unassigned)" icon="circle-help"
                            :href="route('accounts.unassigned')" hint="Review" />
        @endif
        <x-ui.stat-card label="Missing from Google" :value="number_format($missing)" icon="triangle-alert" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Accounts by lifecycle status" :padding="false">
            <table class="data-table">
                <tbody>
                    @foreach ($statuses as $status)
                        <tr>
                            <td><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></td>
                            <td class="text-right tabular-nums">{{ number_format($counts[$status->value] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        @if ($lastSync)
            <x-ui.card title="Last completed synchronisation">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="muted">Type</dt><dd>{{ str_replace('_', ' ', $lastSync->type) }}</dd></div>
                    <div class="flex justify-between"><dt class="muted">Finished</dt><dd>{{ $lastSync->finished_at?->diffForHumans() }}</dd></div>
                    <div class="flex justify-between"><dt class="muted">Fetched</dt><dd class="tabular-nums">{{ number_format($lastSync->fetched) }}</dd></div>
                    <div class="flex justify-between"><dt class="muted">Created / updated</dt><dd class="tabular-nums">{{ $lastSync->created }} / {{ $lastSync->updated }}</dd></div>
                </dl>
                <x-slot:footer>
                    <x-ui.button variant="secondary" size="sm" :href="route('workspace.sync')">Synchronisation</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
