<x-layouts.app title="Dashboard users" description="People who can sign in to Workspace Control Center."
               :breadcrumbs="[['label' => 'Organisations', 'url' => route('organisations.index')], ['label' => 'Dashboard users']]">
    <x-slot:actions>
        <x-ui.button icon="user-plus" @click="$dispatch('open-modal', 'add-user')">Add user</x-ui.button>
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.search-field placeholder="Search name or email…" :value="$search" />
                <x-ui.button type="submit" variant="secondary" size="sm">Search</x-ui.button>
                @if ($search !== '')
                    <x-ui.button variant="ghost" size="sm" :href="route('users.index')">Clear</x-ui.button>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>User</th><th>Platform role</th><th class="text-right">Organisations</th><th>2FA</th><th>Status</th><th>Last sign-in</th><th></th>
        </x-slot:head>

        @forelse ($users as $user)
            <tr>
                <td>
                    <div class="font-medium">{{ $user->name }}</div>
                    <div class="muted text-xs">{{ $user->email }}</div>
                </td>
                <td>{{ $user->platformRoles->pluck('label')->implode(', ') ?: '—' }}</td>
                <td class="text-right tabular-nums">{{ $user->organisations_count }}</td>
                <td>
                    <x-ui.badge :tone="$user->hasTwoFactorEnabled() ? 'success' : 'neutral'">{{ $user->hasTwoFactorEnabled() ? 'On' : 'Off' }}</x-ui.badge>
                </td>
                <td>
                    <x-ui.badge :tone="$user->is_active ? 'success' : 'neutral'">{{ $user->is_active ? 'Active' : 'Deactivated' }}</x-ui.badge>
                </td>
                <td class="muted whitespace-nowrap">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                <td class="text-right">
                    <x-ui.dropdown>
                        <x-slot:trigger>
                            <button type="button" class="muted rounded-md p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Actions for {{ $user->name }}">
                                <x-ui.icon name="ellipsis" class="size-4" />
                            </button>
                        </x-slot:trigger>
                        <form method="POST" action="{{ route('users.password-link', $user) }}">
                            @csrf
                            <x-ui.dropdown.item type="submit" icon="send">Send password link</x-ui.dropdown.item>
                        </form>
                        @if ($user->is_active)
                            @unless ($user->is(auth()->user()))
                                <x-ui.dropdown.item icon="power-off" danger @click="$dispatch('open-modal', 'deactivate-{{ $loop->index }}')">Deactivate…</x-ui.dropdown.item>
                            @endunless
                        @else
                            <form method="POST" action="{{ route('users.active', $user) }}">
                                @csrf
                                <input type="hidden" name="active" value="1">
                                <x-ui.dropdown.item type="submit" icon="power">Activate</x-ui.dropdown.item>
                            </form>
                        @endif
                    </x-ui.dropdown>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No users found" icon="users" /></td></tr>
        @endforelse

        @if ($users->hasPages())
            <x-slot:footer>{{ $users->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    @foreach ($users as $user)
        @if ($user->is_active && ! $user->is(auth()->user()))
            <x-ui.confirm-modal name="deactivate-{{ $loop->index }}" title="Deactivate {{ $user->name }}?"
                                :action="route('users.active', $user)" confirm-label="Deactivate" danger>
                <input type="hidden" name="active" value="0">
                They are signed out, their API tokens are revoked and they cannot sign in again until reactivated.
                Their role assignments and history are kept.
            </x-ui.confirm-modal>
        @endif
    @endforeach

    <x-ui.modal name="add-user" title="Add dashboard user">
        <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
            @csrf
            <x-ui.form.field label="Name" for="user_name" name="name" bag="user" required>
                <x-ui.form.input name="name" id="user_name" bag="user" required maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Email" for="user_email" name="email" bag="user" required
                             hint="A link to set their own password is e-mailed to this address.">
                <x-ui.form.input name="email" id="user_email" type="email" bag="user" required maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Platform role" for="platform_role" bag="user"
                             hint="Leave empty for organisation managers and billing managers; grant their access from the organisation's page. Platform roles require two-factor authentication.">
                <x-ui.form.select name="platform_role" bag="user" placeholder="None" :options="$platformRoles" />
            </x-ui.form.field>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'add-user')">Cancel</x-ui.button>
                <x-ui.button type="submit">Add user</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    @if ($errors->hasBag('user') && $errors->getBag('user')->any())
        <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'add-user'))"></div>
    @endif
</x-layouts.app>
