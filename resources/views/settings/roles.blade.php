<x-layouts.app title="Settings" description="Roles and permissions, and the main configuration in force."
               :breadcrumbs="[['label' => 'System'], ['label' => 'Settings']]">
    <div class="space-y-6">
        <x-ui.card title="Configuration" description="Set on the server in .env; shown here for reference." :padding="false">
            <table class="data-table">
                <tbody>
                    @foreach ($config as $label => $value)
                        <tr><td class="muted">{{ $label }}</td><td class="text-right">{{ $value }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.alert tone="info">
            Tick what each role may do. Organisation roles apply only inside the organisations a person is assigned to.
            <span class="font-medium">Sensitive</span> permissions are financial or destructive. The Super Admin role always has everything.
        </x-ui.alert>

        @foreach ($roles as $role)
            @php($held = $role->permissions->pluck('name')->all())
            <x-ui.card :title="$role->label" :description="$role->description.' Scope: '.$role->scope->value.'.'" x-data="{ open: false }">
                <x-slot:actions>
                    <x-ui.badge :dot="false">{{ count($held) }} permissions</x-ui.badge>
                    <x-ui.button variant="ghost" size="sm" @click="open = !open" x-text="open ? 'Hide' : 'Show'">Show</x-ui.button>
                </x-slot:actions>

                <form method="POST" action="{{ route('settings.roles.update', $role) }}" x-show="open" x-cloak class="space-y-4">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($groups as $group => $permissions)
                            <fieldset>
                                <legend class="mb-1 text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ $group }}</legend>
                                @foreach ($permissions as $permission)
                                    <label class="flex items-center gap-2 py-0.5 text-sm">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" class="size-4 accent-accent-600"
                                               @checked(in_array($permission->name, $held, true)) @disabled($role->name === $superAdmin)>
                                        <span class="font-mono text-xs">{{ $permission->name }}</span>
                                        @if ($permission->is_sensitive)<x-ui.badge tone="warning" :dot="false">Sensitive</x-ui.badge>@endif
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                    @if ($role->name !== $superAdmin)
                        <x-ui.button type="submit">Save {{ $role->label }}</x-ui.button>
                    @else
                        <p class="muted text-sm">The Super Admin role cannot be changed.</p>
                    @endif
                </form>
            </x-ui.card>
        @endforeach
    </div>
</x-layouts.app>
