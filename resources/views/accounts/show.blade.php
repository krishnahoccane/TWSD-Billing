@php
    use App\Modules\GoogleWorkspace\Enums\AccountStatus;

    $user = auth()->user();
    $status = $account->account_status;
    $idle = $account->pending_operation === null;

    $can = [
        'edit' => $idle && in_array($status, [AccountStatus::Active, AccountStatus::Suspended], true) && $user->can('update', $account),
        'suspend' => $idle && $status === AccountStatus::Active && $user->can('suspend', $account),
        'reactivate' => $idle && $status === AccountStatus::Suspended && $user->can('reactivate', $account),
        'softDelete' => $idle && in_array($status, [AccountStatus::Active, AccountStatus::Suspended], true) && $user->can('softDelete', $account),
        'restore' => $idle && $status === AccountStatus::SoftDeleted && $deletionRequest === null && $user->can('restore', $account),
        'retry' => $status === AccountStatus::CreationFailed && $user->can('update', $account),
        'requestPermanent' => $idle && $status === AccountStatus::SoftDeleted && $deletionRequest === null && $user->can('requestPermanentDeletion', $account),
        'decidePermanent' => $deletionRequest?->status === 'pending' && $user->can('approvePermanentDeletion', $account),
    ];

    $details = [
        'Name' => $account->fullName(),
        'Display name' => $account->display_name,
        'Organisation' => $account->organisation?->name,
        'Organisational unit' => $account->organisational_unit_path,
        'Recovery email' => $account->recovery_email,
        'Recovery phone' => $account->recovery_phone,
        'Billing' => $account->billing_status->label().($account->billable_from ? ' from '.$account->billable_from->format('d M Y') : ''),
        'Billing stops' => $account->deletion_effective_date?->format('d M Y'),
        'Created in Google' => $account->google_created_at?->format('d M Y H:i'),
        'Last synced' => $account->last_synced_at?->diffForHumans(),
    ];
@endphp
<x-layouts.app :title="$account->primary_email" :description="$account->organisation?->name ?? 'Not assigned to an organisation'"
               :breadcrumbs="[['label' => 'Email accounts', 'url' => route('accounts.index')], ['label' => $account->primary_email]]">
    <x-slot:actions>
        <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
        @if ($can['edit'])
            <x-ui.button variant="secondary" icon="pencil" :href="route('accounts.edit', $account)">Edit</x-ui.button>
        @endif
        @if ($can['suspend'])
            <x-ui.button variant="secondary" icon="pause-circle" @click="$dispatch('open-modal', 'suspend')">Suspend</x-ui.button>
        @endif
        @if ($can['reactivate'])
            <x-ui.button variant="secondary" icon="power" @click="$dispatch('open-modal', 'reactivate')">Reactivate</x-ui.button>
        @endif
        @if ($can['restore'])
            <x-ui.button variant="secondary" icon="refresh-cw" @click="$dispatch('open-modal', 'restore')">Restore</x-ui.button>
        @endif
        @if ($can['softDelete'])
            <x-ui.button variant="danger" icon="archive" @click="$dispatch('open-modal', 'soft-delete')">Delete</x-ui.button>
        @endif
    </x-slot:actions>

    <div class="space-y-4">
        @if ($errors->has('account') || $errors->has('request') || $errors->has('confirm_email') || $errors->has('reason'))
            <x-ui.alert tone="danger">{{ $errors->first('account') ?: $errors->first('request') ?: $errors->first('confirm_email') ?: $errors->first('reason') }}</x-ui.alert>
        @endif

        @if ($initialPassword !== null)
            <x-ui.alert tone="warning" title="Initial password — shown this one time only">
                <p class="mt-1">Give it to the mailbox owner over a secure channel. They must change it at first sign-in.</p>
                <p class="mt-2 rounded bg-white px-3 py-2 font-mono text-base tracking-wide text-zinc-900 select-all dark:bg-zinc-900 dark:text-zinc-100">{{ $initialPassword }}</p>
                <p class="mt-2 text-xs">It has now been removed from this system and cannot be shown again.</p>
            </x-ui.alert>
        @elseif ($canReveal)
            <x-ui.alert tone="info" title="Initial password ready">
                <p>You can reveal the generated password once. It expires 24 hours after the mailbox was created.</p>
                <x-ui.button size="sm" class="mt-2" icon="key-round" @click="$dispatch('open-modal', 'reveal')">Reveal password</x-ui.button>
            </x-ui.alert>
        @endif

        @if ($account->pending_operation)
            <x-ui.alert tone="info">
                {{ $status->label() }} — this is being applied in Google Workspace
                (requested {{ $account->pending_since?->diffForHumans() }}). Refresh to see the result.
            </x-ui.alert>
        @endif

        @if ($account->last_error)
            <x-ui.alert tone="danger" title="The last operation failed">
                <p class="break-words font-mono text-xs">{{ $account->last_error }}</p>
                @if ($can['retry'])
                    <form method="POST" action="{{ route('accounts.retry', $account) }}" class="mt-2">
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="secondary" icon="refresh-cw">Retry creation</x-ui.button>
                    </form>
                @endif
            </x-ui.alert>
        @endif

        @if ($account->missing_from_google_at)
            <x-ui.alert tone="danger">
                This mailbox was not returned by Google Workspace in the full synchronisation of
                {{ $account->missing_from_google_at->format('d M Y H:i') }}. It may have been deleted directly in Google.
            </x-ui.alert>
        @endif

        @if ($status === AccountStatus::SoftDeleted)
            <x-ui.alert tone="warning">
                Soft-deleted: the Google user is suspended and still exists, with its data. Local soft deletion is not a
                retention guarantee. Not billed from {{ $account->deletion_effective_date?->format('d M Y') ?? '—' }}.
                @if ($account->deletion_reason) Reason: {{ $account->deletion_reason }} @endif
            </x-ui.alert>
        @endif

        @if ($account->isUnassigned() && $assignable)
            <x-ui.card title="Assign to an organisation"
                       description="This mailbox was imported from Google Workspace and its domain is not mapped. It is not billed until it is assigned.">
                <form method="POST" action="{{ route('accounts.assign', $account) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.form.field label="Organisation" for="assign_organisation" name="organisation" class="w-full sm:w-80">
                        <x-ui.form.select name="organisation" id="assign_organisation" :options="$assignable" placeholder="Choose…" required />
                    </x-ui.form.field>
                    <x-ui.button type="submit">Assign</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($deletionRequest)
            <x-ui.card title="Permanent deletion request">
                <p class="text-sm">
                    Requested by {{ $deletionRequest->requester?->name ?? 'unknown' }} {{ $deletionRequest->created_at->diffForHumans() }}.
                    Status: <span class="font-medium">{{ ucfirst($deletionRequest->status) }}</span>.
                </p>
                <p class="muted mt-1 text-sm">Reason: {{ $deletionRequest->reason }}</p>
                @if ($can['decidePermanent'])
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button variant="danger" size="sm" @click="$dispatch('open-modal', 'approve-permanent')">Approve permanent deletion…</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" @click="$dispatch('open-modal', 'reject-permanent')">Reject…</x-ui.button>
                    </div>
                    @if ($deletionRequest->requested_by === $user->id && config('wcc.google.require_second_approver'))
                        <p class="muted mt-2 text-xs">You made this request, so a different Super Admin must approve it.</p>
                    @endif
                @endif
            </x-ui.card>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-ui.card title="Details">
            <dl class="space-y-3 text-sm">
                @foreach ($details as $label => $value)
                    <div>
                        <dt class="muted text-xs">{{ $label }}</dt>
                        <dd class="break-words">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($can['requestPermanent'])
                <x-slot:footer>
                    <x-ui.button variant="ghost" size="sm" @click="$dispatch('open-modal', 'request-permanent')">Request permanent deletion…</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.card>

        <x-ui.card title="History" :padding="false" class="xl:col-span-2">
            @if ($account->history->isEmpty())
                <x-ui.empty-state title="No history yet" icon="history" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>When</th><th>Event</th><th>Change</th><th>By</th></tr></thead>
                        <tbody>
                            @foreach ($account->history->take(50) as $entry)
                                <tr>
                                    <td class="muted whitespace-nowrap">{{ $entry->created_at->format('d M Y H:i') }}</td>
                                    <td>
                                        {{ ucfirst(str_replace('_', ' ', $entry->event)) }}
                                        @if (! empty($entry->details['reason']))<div class="muted text-xs">{{ $entry->details['reason'] }}</div>@endif
                                        @if (! empty($entry->details['error']))<div class="break-words text-xs text-red-600 dark:text-red-400">{{ $entry->details['error'] }}</div>@endif
                                    </td>
                                    <td class="muted whitespace-nowrap text-xs">
                                        @if ($entry->to_status){{ str_replace('_', ' ', $entry->from_status ?? '—') }} → {{ str_replace('_', ' ', $entry->to_status) }}@else — @endif
                                    </td>
                                    <td class="muted">{{ $entry->user?->name ?? 'System' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Confirmation dialogs --}}
    @if ($canReveal)
        <x-ui.confirm-modal name="reveal" title="Reveal the initial password?" :action="route('accounts.reveal', $account)" confirm-label="Reveal once">
            The password is shown one time and then removed from this system. Make sure nobody else can see your screen.
        </x-ui.confirm-modal>
    @endif
    @if ($can['suspend'])
        <x-ui.confirm-modal name="suspend" title="Suspend this mailbox?" :action="route('accounts.suspend', $account)" confirm-label="Suspend" danger require-reason>
            {{ $account->primary_email }} will be unable to sign in and active sessions are signed out. Mail and data are kept.
            <span class="font-medium">A suspended mailbox remains billable.</span>
        </x-ui.confirm-modal>
    @endif
    @if ($can['reactivate'])
        <x-ui.confirm-modal name="reactivate" title="Reactivate this mailbox?" :action="route('accounts.reactivate', $account)" confirm-label="Reactivate">
            {{ $account->primary_email }} will be able to sign in again.
        </x-ui.confirm-modal>
    @endif
    @if ($can['restore'])
        <x-ui.confirm-modal name="restore" title="Restore this mailbox?" :action="route('accounts.restore', $account)" confirm-label="Restore">
            {{ $account->primary_email }} becomes active again and is billable from today.
        </x-ui.confirm-modal>
    @endif
    @if ($can['softDelete'])
        <x-ui.modal name="soft-delete" title="Delete this mailbox?">
            <form method="POST" action="{{ route('accounts.soft-delete', $account) }}" x-data="{ typed: '' }" class="space-y-4">
                @csrf
                <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>The mailbox is suspended and signed out, and billing stops from today. It is <span class="font-medium">not</span> removed from Google Workspace and can be restored by a Super Admin.</p>
                    <p>Permanent removal is a separate step that needs Super Admin approval.</p>
                </div>
                <x-ui.form.field label="Reason" for="delete_reason" name="reason" required>
                    <x-ui.form.textarea name="reason" id="delete_reason" required maxlength="500" />
                </x-ui.form.field>
                <x-ui.form.field for="delete_confirm" name="confirm_email">
                    <p class="text-sm">Type <span class="font-mono font-medium">{{ $account->primary_email }}</span> to confirm.</p>
                    <input type="text" name="confirm_email" id="delete_confirm" x-model="typed" autocomplete="off" class="control">
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'soft-delete')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger" x-bind:disabled="typed !== {{ \Illuminate\Support\Js::from($account->primary_email) }}">Delete mailbox</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
    @if ($can['requestPermanent'])
        <x-ui.modal name="request-permanent" title="Request permanent deletion" max-width="max-w-lg">
            <form method="POST" action="{{ route('accounts.deletion-requests.store', $account) }}" class="space-y-4">
                @csrf
                <x-ui.alert tone="danger">
                    Permanent deletion removes the Google user, mailbox and Drive data. Google can restore a deleted user only
                    for about 20 days; after that the data cannot be recovered by anyone.
                </x-ui.alert>
                <p class="text-sm">Confirm each check. Your confirmations are recorded with the request, and a different Super Admin must approve it.</p>
                <div class="space-y-2">
                    <x-ui.form.checkbox name="ownership_verified" label="The mailbox belongs to this organisation and the organisation has asked for removal" />
                    <x-ui.form.checkbox name="retention_checked" label="No legal, contractual or internal retention requirement applies" />
                    <x-ui.form.checkbox name="vault_checked" label="Google Vault holds and retention rules have been checked" />
                    <x-ui.form.checkbox name="dependencies_checked" label="Owned files, groups, aliases and shared resources have been transferred or are not needed" />
                </div>
                @if ($errors->getBag('permanent')->any())
                    <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $errors->getBag('permanent')->first() }}</p>
                @endif
                <x-ui.form.field label="Reason" for="permanent_reason" name="reason" bag="permanent" required>
                    <x-ui.form.textarea name="reason" id="permanent_reason" bag="permanent" required maxlength="500" />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'request-permanent')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Request permanent deletion</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
    @if ($can['decidePermanent'])
        <x-ui.modal name="approve-permanent" title="Approve permanent deletion?">
            <form method="POST" action="{{ route('accounts.deletion-requests.approve', [$account, $deletionRequest]) }}" x-data="{ typed: '' }" class="space-y-4">
                @csrf
                <x-ui.alert tone="danger">This deletes the Google user and its data. It cannot be undone from this application.</x-ui.alert>
                <x-ui.form.field for="approve_confirm" name="confirm_email">
                    <p class="text-sm">Type <span class="font-mono font-medium">{{ $account->primary_email }}</span> to confirm.</p>
                    <input type="text" name="confirm_email" id="approve_confirm" x-model="typed" autocomplete="off" class="control">
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'approve-permanent')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger" x-bind:disabled="typed !== {{ \Illuminate\Support\Js::from($account->primary_email) }}">Permanently delete</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
        <x-ui.confirm-modal name="reject-permanent" title="Reject permanent deletion?" confirm-label="Reject request" require-reason
                            :action="route('accounts.deletion-requests.reject', [$account, $deletionRequest])">
            The mailbox stays soft-deleted and can still be restored.
        </x-ui.confirm-modal>
    @endif

    @if ($errors->getBag('permanent')->any())
        <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'request-permanent'))"></div>
    @endif
</x-layouts.app>
