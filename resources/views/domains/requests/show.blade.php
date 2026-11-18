@php
    use App\Modules\Domains\Enums\DomainRequestStatus as S;

    $r = $domainRequest;
    $status = $r->status;
    $removal = $r->isRemoval();
    $ownRequest = $r->requested_by === auth()->id();
@endphp
<x-layouts.app :title="($removal ? 'Remove ' : 'Add ').$r->domain_name" :description="$r->organisation?->name"
               :breadcrumbs="[
                   ['label' => 'Domains', 'url' => route('domains.index')],
                   ['label' => 'Requests', 'url' => route('domains.requests.index')],
                   ['label' => $r->domain_name],
               ]">
    <x-slot:actions>
        <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
        @if ($can['cancel'])
            <form method="POST" action="{{ route('domains.requests.cancel', $r) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Cancel request</x-ui.button>
            </form>
        @endif
    </x-slot:actions>

    <div class="space-y-4">
        @if ($errors->any())
            <x-ui.alert tone="danger">{{ $errors->first() }}</x-ui.alert>
        @endif
        @if (session('warning'))
            <x-ui.alert tone="warning">{{ session('warning') }}</x-ui.alert>
        @endif
        @if ($mode === 'mock' && $status->isOpen())
            <x-ui.alert tone="warning" title="Mock mode — not connected to Google">
                Verification and domain changes are simulated. Nothing is checked with or added to your Google Workspace.
            </x-ui.alert>
        @endif

        @if ($status === S::Pending)
            <x-ui.card :title="$removal ? 'Waiting for approval of the removal' : 'Waiting for Super Admin approval'">
                <p class="text-sm">
                    @if ($removal)
                        A different Super Admin must approve this removal. The domain is not changed until then.
                    @else
                        This request is a claim only — it does not prove the organisation controls the domain. Approving it
                        starts Google's ownership verification.
                    @endif
                </p>
                @if ($can['decide'])
                    @if ($ownRequest)
                        <p class="muted mt-2 text-sm">You submitted this request, so someone else must approve it.</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button :variant="$removal ? 'danger' : 'primary'" @click="$dispatch('open-modal', 'approve')">Approve…</x-ui.button>
                        <x-ui.button variant="secondary" @click="$dispatch('open-modal', 'reject')">Reject…</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @endif

        @if ($status === S::VerificationPending)
            <x-ui.card title="Add this DNS record" description="At the company that manages DNS for the domain (registrar or DNS host).">
                @if ($r->last_error)
                    <x-ui.alert tone="warning" class="mb-4">{{ $r->last_error }}</x-ui.alert>
                @endif
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Type</th><th>Host / name</th><th>Value</th><th>TTL</th></tr></thead>
                        <tbody>
                            <tr>
                                <td class="font-mono">TXT</td>
                                <td class="font-mono">{{ $r->verificationHost() }}</td>
                                <td class="break-all font-mono text-xs select-all">{{ $r->verification_token }}</td>
                                <td class="font-mono">3600</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="muted mt-3 list-disc space-y-1 pl-5 text-xs">
                    <li>"@" means the domain itself ({{ $r->domain_name }}). Some DNS hosts want the field left empty instead.</li>
                    <li>Keep any existing TXT records; add this one alongside them.</li>
                    <li>DNS changes usually appear within minutes but can take up to 48 hours.</li>
                </ul>
                @if ($can['process'])
                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('domains.requests.check-dns', $r) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" icon="search">Check public DNS</x-ui.button>
                        </form>
                        <x-ui.button icon="shield-check" @click="$dispatch('open-modal', 'verify')">Verify with Google…</x-ui.button>
                        @if ($can['decide'])
                            <x-ui.button variant="ghost" @click="$dispatch('open-modal', 'reject')">Reject…</x-ui.button>
                        @endif
                    </div>
                @else
                    <p class="muted mt-3 text-sm">Once the record is in place, tell your administrator so they can complete verification.</p>
                @endif
            </x-ui.card>
        @endif

        @if (in_array($status, [S::Approved, S::Verifying, S::Verified], true))
            <x-ui.alert tone="info">{{ $status->label() }} — the next step is running in the background. Refresh to see the result.</x-ui.alert>
        @endif

        @if ($status === S::Failed)
            <x-ui.alert tone="danger" title="A step failed">
                <p class="break-words font-mono text-xs">{{ $r->last_error }}</p>
                @if ($can['process'])
                    <form method="POST" action="{{ route('domains.requests.retry', $r) }}" class="mt-2 flex flex-wrap gap-2">
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="secondary" icon="refresh-cw">Retry</x-ui.button>
                    </form>
                @endif
            </x-ui.alert>
        @endif

        @if ($status === S::Completed)
            <x-ui.alert tone="success">
                @if ($removal)
                    The domain has been removed.
                @else
                    The domain is active for {{ $r->organisation?->name }} and can be used for new mailboxes.
                @endif
            </x-ui.alert>
        @endif

        @if (in_array($status, [S::Rejected, S::Cancelled], true) && $r->decision_note)
            <x-ui.alert tone="warning" :title="$status->label()">{{ $r->decision_note }}</x-ui.alert>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-ui.card title="Request">
            <dl class="space-y-3 text-sm">
                <div><dt class="muted text-xs">Domain</dt><dd class="font-medium">{{ $r->domain_name }}</dd></div>
                <div><dt class="muted text-xs">Organisation</dt><dd>{{ $r->organisation?->name ?? '—' }}</dd></div>
                <div><dt class="muted text-xs">Requested by</dt><dd>{{ $r->requester?->name ?? '—' }} · {{ $r->created_at->inDisplayTimezone()->format('d M Y H:i') }}</dd></div>
                <div><dt class="muted text-xs">{{ $removal ? 'Reason' : 'Note' }}</dt><dd class="whitespace-pre-line break-words">{{ $r->request_note ?: '—' }}</dd></div>
                @if ($r->decided_at)
                    <div><dt class="muted text-xs">Decision</dt><dd>{{ $r->decider?->name ?? '—' }} · {{ $r->decided_at->inDisplayTimezone()->format('d M Y H:i') }}</dd></div>
                @endif
                @if ($r->verified_at)
                    <div><dt class="muted text-xs">Verified</dt><dd>{{ $r->verified_at->inDisplayTimezone()->format('d M Y H:i') }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="space-y-6 xl:col-span-2">
            @if ($r->verificationChecks->isNotEmpty())
                <x-ui.card title="Verification checks" :padding="false">
                    <table class="data-table">
                        <thead><tr><th>When</th><th>Check</th><th>Result</th><th>By</th></tr></thead>
                        <tbody>
                            @foreach ($r->verificationChecks as $check)
                                <tr>
                                    <td class="muted whitespace-nowrap">{{ $check->created_at->inDisplayTimezone()->format('d M Y H:i') }}</td>
                                    <td>{{ ['dns_txt' => 'Public DNS lookup', 'google_verify' => 'Google ownership verification', 'google_domain' => 'Domain in Google Workspace'][$check->type] ?? $check->type }}</td>
                                    <td><x-ui.badge :tone="$check->result === 'pass' ? 'success' : 'warning'">{{ $check->result === 'pass' ? 'Passed' : 'Not found' }}</x-ui.badge></td>
                                    <td class="muted">{{ $check->checker?->name ?? 'System' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.card>
            @endif

            <x-ui.card title="History" :padding="false">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>When</th><th>Event</th><th>Note</th><th>By</th></tr></thead>
                        <tbody>
                            @foreach ($r->history as $entry)
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
            </x-ui.card>
        </div>
    </div>

    {{-- Dialogs --}}
    @if ($can['decide'] && $status === S::Pending)
        <x-ui.modal name="approve" :title="$removal ? 'Approve removal of '.$r->domain_name.'?' : 'Approve '.$r->domain_name.'?'">
            <form method="POST" action="{{ route('domains.requests.approve', $r) }}" x-data="{ typed: '' }" class="space-y-4">
                @csrf
                @if ($removal)
                    <x-ui.alert tone="danger">The domain is deleted from Google Workspace. This cannot be undone from this application.</x-ui.alert>
                    <x-ui.form.field for="confirm_domain" name="confirm_domain">
                        <p class="text-sm">Type <span class="font-mono font-medium">{{ $r->domain_name }}</span> to confirm.</p>
                        <input type="text" name="confirm_domain" id="confirm_domain" x-model="typed" autocomplete="off" class="control">
                    </x-ui.form.field>
                @else
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">
                        Approval lets the organisation start ownership verification. The domain is added to Google Workspace
                        only after Google has verified it.
                    </p>
                @endif
                <x-ui.form.field label="Note (optional)" for="approve_note" name="note">
                    <x-ui.form.textarea name="note" id="approve_note" maxlength="500" />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'approve')">Cancel</x-ui.button>
                    <x-ui.button type="submit" :variant="$removal ? 'danger' : 'primary'"
                                 x-bind:disabled="{{ $removal ? 'typed !== '.\Illuminate\Support\Js::from($r->domain_name) : 'false' }}">
                        {{ $removal ? 'Approve removal' : 'Approve' }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
    @if ($can['decide'] && in_array($status, [S::Pending, S::VerificationPending, S::Failed], true))
        <x-ui.confirm-modal name="reject" title="Reject this request?" :action="route('domains.requests.reject', $r)"
                            confirm-label="Reject" danger require-reason>
            The requester will see your reason. Nothing is changed in Google Workspace.
        </x-ui.confirm-modal>
    @endif
    @if ($can['process'] && $status === S::VerificationPending)
        <x-ui.confirm-modal name="verify" title="Verify with Google?" :action="route('domains.requests.verify', $r)" confirm-label="Verify now">
            Confirm that the TXT record has been added. Google will check it; if it is found, the domain is added to
            Google Workspace as a secondary domain and activated for {{ $r->organisation?->name }}.
        </x-ui.confirm-modal>
    @endif
</x-layouts.app>
