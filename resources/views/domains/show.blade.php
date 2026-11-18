<x-layouts.app :title="$domain->domain_name" :description="$domain->organisation?->name"
               :breadcrumbs="[['label' => 'Domains', 'url' => route('domains.index')], ['label' => $domain->domain_name]]">
    <x-slot:actions>
        <x-ui.badge :tone="$domain->status === 'active' ? 'success' : 'neutral'">{{ ucfirst($domain->status) }}</x-ui.badge>
        @if ($canRemove && $domain->status === 'active' && ! $domain->is_primary && $openRequest === null)
            <x-ui.button variant="danger" icon="trash-2" @click="$dispatch('open-modal', 'remove-domain')">Request removal</x-ui.button>
        @endif
    </x-slot:actions>

    @if ($errors->has('domain') || $errors->has('checks'))
        <x-ui.alert tone="danger" class="mb-6">{{ $errors->first('domain') ?: $errors->first('checks') }}</x-ui.alert>
    @endif

    @if ($openRequest)
        <x-ui.alert tone="info" class="mb-6">
            A {{ $openRequest->isRemoval() ? 'removal' : 'domain' }} request is in progress
            ({{ $openRequest->status->label() }}).
            <a href="{{ route('domains.requests.show', $openRequest) }}" class="font-medium underline">View request</a>
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Mailboxes on this domain" :value="number_format($accounts)" icon="mail" />
        <x-ui.stat-card label="Type" :value="$domain->domain_type->label()" icon="globe" />
        <x-ui.stat-card label="Google verification" :value="ucfirst(str_replace('_', ' ', $domain->verification_status))" icon="shield-check" />
    </div>

    @if ($mailboxes !== null)
        <x-ui.card title="Mailboxes" :padding="false" class="mt-6"
                   :description="'Email accounts on '.$domain->domain_name.'.'">
            @if ($mailboxes->isEmpty())
                <x-ui.empty-state title="No mailboxes on this domain" icon="mail" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Billing</th><th>Organisation</th></tr></thead>
                        <tbody>
                            @foreach ($mailboxes as $mailbox)
                                <tr>
                                    <td><a href="{{ route('accounts.show', $mailbox) }}" class="font-medium hover:underline">{{ $mailbox->primary_email }}</a></td>
                                    <td>{{ $mailbox->fullName() ?: '—' }}</td>
                                    <td><x-ui.badge :tone="$mailbox->account_status->tone()">{{ $mailbox->account_status->label() }}</x-ui.badge></td>
                                    <td class="whitespace-nowrap">
                                        {{ $mailbox->billing_status->label() }}
                                        @if ($mailbox->billable_from)<div class="muted text-xs">from {{ $mailbox->billable_from->format('d M Y') }}</div>@endif
                                    </td>
                                    <td class="muted">{{ $mailbox->organisation?->name ?? 'Not assigned' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($mailboxes->hasPages())
                    <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">{{ $mailboxes->links() }}</div>
                @endif
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="Activity" :padding="false" class="mt-6">
        @if ($history->isEmpty())
            <x-ui.empty-state title="No recorded activity" icon="history"
                              description="Domains mapped directly by a Super Admin have no request history." />
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>When</th><th>Event</th><th>Note</th><th>By</th></tr></thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr>
                                <td class="muted whitespace-nowrap">{{ $entry->created_at->inDisplayTimezone()->format('d M Y H:i') }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $entry->event)) }}</td>
                                <td class="muted max-w-md break-words text-xs">{{ $entry->details['note'] ?? $entry->details['reason'] ?? $entry->details['error'] ?? '—' }}</td>
                                <td class="muted">{{ $entry->user?->name ?? 'System' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if ($canRemove)
        <x-ui.modal name="remove-domain" title="Request removal of this domain" max-width="max-w-lg">
            <form method="POST" action="{{ route('domains.removal', $domain) }}" class="space-y-4">
                @csrf
                <x-ui.alert tone="danger">
                    Removing a domain deletes it from Google Workspace. It is blocked while any mailbox still uses the
                    domain, and a different Super Admin must approve it.
                </x-ui.alert>
                <p class="text-sm">Mailboxes on this domain now: <span class="font-medium">{{ $accounts }}</span>.</p>
                <div class="space-y-2">
                    <x-ui.form.checkbox name="aliases_checked" label="No user aliases or domain aliases still use this domain" />
                    <x-ui.form.checkbox name="groups_checked" label="No groups or other Google resources still use this domain" />
                </div>
                @if ($errors->getBag('removal')->any())
                    <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $errors->getBag('removal')->first() }}</p>
                @endif
                <x-ui.form.field label="Reason" for="removal_reason" name="reason" bag="removal" required>
                    <x-ui.form.textarea name="reason" id="removal_reason" bag="removal" required maxlength="500" />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'remove-domain')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Request removal</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
        @if ($errors->getBag('removal')->any())
            <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'remove-domain'))"></div>
        @endif
    @endif
</x-layouts.app>
