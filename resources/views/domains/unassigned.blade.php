<x-layouts.app title="Unassigned domains"
               description="Domains in Google Workspace that are not assigned to an organisation. Assigning one maps it to that organisation; nothing is changed in Google."
               :breadcrumbs="[['label' => 'Domains', 'url' => route('domains.index')], ['label' => 'Unassigned']]">
    @if ($canSync)
        <x-slot:actions>@include('domains._sync-button')</x-slot:actions>
    @endif

    @include('domains._tabs', ['active' => 'unassigned', 'unassignedCount' => $domains->total()])

    @error('organisation')<x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('domains.unassigned') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.search-field placeholder="Search domain…" :value="$search" />
                <x-ui.button type="submit" variant="secondary" size="sm">Search</x-ui.button>
            </form>
            <span class="muted ml-auto text-xs">
                Last domain sync: {{ $lastSync?->finished_at?->diffForHumans() ?? 'never' }}
            </span>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Domain</th><th>In Google</th><th class="text-right">Unassigned mailboxes</th><th>Assign to organisation</th>
        </x-slot:head>

        @forelse ($domains as $domain)
            @php($count = $mailboxes[$domain->id] ?? 0)
            <tr>
                <td class="align-top">
                    <span class="font-medium">{{ $domain->domain_name }}</span>
                    @if ($domain->is_primary)<x-ui.badge tone="info" :dot="false" class="ml-1">Primary</x-ui.badge>@endif
                    @if ($domain->isAlias())<div class="muted text-xs">Alias of {{ $domain->alias_of }}</div>@endif
                </td>
                <td class="align-top">
                    <x-ui.badge :tone="$domain->verified ? 'success' : 'warning'">{{ $domain->verified ? 'Verified' : 'Not verified' }}</x-ui.badge>
                    <div class="muted mt-1 text-xs">Seen {{ $domain->last_seen_at?->diffForHumans() }}</div>
                </td>
                <td class="text-right align-top tabular-nums">
                    @if ($count > 0)
                        <a href="{{ route('accounts.unassigned', ['search' => '@'.$domain->domain_name]) }}" class="hover:underline">{{ $count }}</a>
                    @else
                        0
                    @endif
                </td>
                <td class="align-top">
                    @if ($organisations)
                        <form method="POST" action="{{ route('domains.unassigned.assign', $domain) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <x-ui.form.select name="organisation" id="organisation_{{ $domain->uuid }}" :options="$organisations"
                                              placeholder="Choose organisation…" required class="w-56" aria-label="Organisation for {{ $domain->domain_name }}" />
                            <x-ui.button type="submit" size="sm">Assign</x-ui.button>
                            @if ($count > 0 && $canAssignAccounts)
                                <div class="basis-full">
                                    <x-ui.form.checkbox name="with_accounts" :checked="true"
                                                        label="Also assign its {{ $count }} unassigned mailbox(es) (billed from today)" />
                                </div>
                            @endif
                        </form>
                    @else
                        <span class="muted text-sm">Create an organisation first.</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="!p-0">
                <x-ui.empty-state title="No unassigned domains" icon="globe"
                                  description="Every domain found in Google Workspace is assigned to an organisation. Run a domain sync to check for new ones." />
            </td></tr>
        @endforelse

        @if ($domains->hasPages())
            <x-slot:footer>{{ $domains->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
