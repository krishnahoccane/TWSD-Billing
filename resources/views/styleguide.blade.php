{{-- Reference page for the design system. Sample values below are illustrative only. --}}
<x-layouts.app title="Design system" description="Reusable Blade components. Use these instead of hand-written markup."
               :breadcrumbs="[['label' => 'System'], ['label' => 'Design system']]">
    <x-slot:actions>
        <x-ui.button variant="secondary" icon="refresh-cw">Secondary</x-ui.button>
        <x-ui.button icon="plus">Primary action</x-ui.button>
    </x-slot:actions>

    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Stat card" value="128" icon="mail" hint="With a hint line" />
            <x-ui.stat-card label="Currency" value="₹ 48,500.00" icon="badge-indian-rupee" />
            <x-ui.stat-card label="With link" value="7" icon="clock" hint="Review" href="#" />
            <x-ui.stat-card label="Plain" value="0" />
        </div>

        <x-ui.card title="Buttons and badges">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button>Primary</x-ui.button>
                <x-ui.button variant="secondary">Secondary</x-ui.button>
                <x-ui.button variant="danger">Danger</x-ui.button>
                <x-ui.button variant="ghost">Ghost</x-ui.button>
                <x-ui.button size="sm" variant="secondary">Small</x-ui.button>
                <x-ui.button disabled>Disabled</x-ui.button>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-ui.badge tone="success">Active</x-ui.badge>
                <x-ui.badge tone="warning">Suspended</x-ui.badge>
                <x-ui.badge tone="danger">Failed</x-ui.badge>
                <x-ui.badge tone="info">Pending</x-ui.badge>
                <x-ui.badge>Soft deleted</x-ui.badge>
            </div>
        </x-ui.card>

        <x-ui.table>
            <x-slot:toolbar>
                <x-ui.search-field placeholder="Search accounts…" />
                <x-ui.filter-select label="Status" :options="['active' => 'Active', 'suspended' => 'Suspended']" />
                <x-ui.date-range class="ml-auto" />
            </x-slot:toolbar>
            <x-slot:head>
                <th>Email</th><th>Name</th><th>Status</th><th class="text-right">Monthly price</th><th></th>
            </x-slot:head>
            @foreach ([['a.sample@example.com', 'Sample User', 'success', 'Active'], ['b.sample@example.com', 'Second Sample', 'warning', 'Suspended']] as [$email, $name, $tone, $label])
                <tr>
                    <td class="font-medium">{{ $email }}</td>
                    <td>{{ $name }}</td>
                    <td><x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge></td>
                    <td class="text-right tabular-nums">₹ 250.00</td>
                    <td class="text-right">
                        <x-ui.dropdown>
                            <x-slot:trigger>
                                <button type="button" class="muted rounded-md p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Row actions">
                                    <x-ui.icon name="ellipsis" class="size-4" />
                                </button>
                            </x-slot:trigger>
                            <x-ui.dropdown.item icon="user">View</x-ui.dropdown.item>
                            <x-ui.dropdown.item icon="archive" danger @click="$dispatch('open-modal', 'demo-confirm')">Delete…</x-ui.dropdown.item>
                        </x-ui.dropdown>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Form fields">
                <form class="space-y-4" @submit.prevent>
                    <x-ui.form.field label="Text input" for="sg_text" hint="Hint text sits under the control." required>
                        <x-ui.form.input name="sg_text" placeholder="Placeholder" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Select" for="sg_select">
                        <x-ui.form.select name="sg_select" placeholder="Choose…" :options="['one' => 'Option one', 'two' => 'Option two']" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Textarea" for="sg_textarea">
                        <x-ui.form.textarea name="sg_textarea" />
                    </x-ui.form.field>
                    <x-ui.form.checkbox name="sg_check" label="Checkbox" />
                </form>
            </x-ui.card>

            <div class="space-y-6">
                <x-ui.card title="Alerts and toasts">
                    <div class="space-y-2">
                        <x-ui.alert tone="info">Informational message.</x-ui.alert>
                        <x-ui.alert tone="success">Operation completed.</x-ui.alert>
                        <x-ui.alert tone="warning" title="Heads up">Something needs attention.</x-ui.alert>
                        <x-ui.alert tone="danger">Operation failed.</x-ui.alert>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" size="sm" @click="$dispatch('toast', { type: 'success', message: 'Saved successfully.' })">Success toast</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" @click="$dispatch('toast', { type: 'error', message: 'Could not reach Google.' })">Error toast</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" @click="$dispatch('open-modal', 'demo-confirm')">Confirm modal</x-ui.button>
                    </div>
                </x-ui.card>

                <x-ui.card title="States" :padding="false">
                    <x-ui.loading-state />
                    <x-ui.empty-state title="Nothing here yet" description="Empty lists explain what will appear and offer the next action.">
                        <x-ui.button size="sm" icon="plus">Create</x-ui.button>
                    </x-ui.empty-state>
                    <x-ui.error-state description="Shown when a section fails to load.">
                        <x-ui.button size="sm" variant="secondary" icon="refresh-cw">Retry</x-ui.button>
                    </x-ui.error-state>
                </x-ui.card>
            </div>
        </div>
    </div>

    <x-ui.confirm-modal name="demo-confirm" title="Delete account?" action="#" confirm-label="Delete" danger
                        require-reason confirm-text="a.sample@example.com">
        Destructive actions require a reason and typing the resource name. (Demo only — this form does not submit anywhere.)
    </x-ui.confirm-modal>
</x-layouts.app>
