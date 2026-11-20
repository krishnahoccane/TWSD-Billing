<x-layouts.app title="Storefronts" description="Websites that sell software through this application. They take payment and invoice; licenses live here."
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Storefronts']]">
    <div class="space-y-6">
        @if ($secrets)
            <x-ui.alert tone="warning" title="Copy these now — they are shown only once">
                <p class="mt-1">Put them in the {{ $secrets['storefront'] }} server's <span class="font-mono">.env</span>. Generate new ones here if they are lost.</p>
                @foreach (collect($secrets)->except('storefront') as $name => $value)
                    <p class="mt-2 rounded bg-white px-3 py-2 font-mono text-sm text-zinc-900 select-all break-all dark:bg-zinc-900 dark:text-zinc-100">{{ $name }}={{ $value }}</p>
                @endforeach
            </x-ui.alert>
        @endif

        @foreach ($storefronts as $storefront)
            <x-ui.card :title="$storefront->name" :description="'Code '.$storefront->code.' · '.$storefront->customers_count.' customers · last API call '.($storefront->last_used_at?->diffForHumans() ?? 'never')">
                <form method="POST" action="{{ route('licensing.storefronts.update', $storefront->uuid) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <x-ui.form.field label="Name" required><x-ui.form.input name="name" :value="$storefront->name" required /></x-ui.form.field>
                    <x-ui.form.field label="Webhook URL" hint="HTTPS, e.g. https://musicdesk.tech/webhooks/wcc"><x-ui.form.input name="webhook_url" :value="$storefront->webhook_url" /></x-ui.form.field>
                    <div class="flex items-center"><input type="hidden" name="is_active" value="0"><x-ui.form.checkbox name="is_active" label="Active (API key accepted)" :checked="$storefront->is_active" /></div>
                    <dl class="text-sm">
                        <div class="flex justify-between"><dt class="muted">API key</dt><dd class="font-mono">{{ $storefront->api_key_hint ? 'sk_live_…'.$storefront->api_key_hint : 'none' }}</dd></div>
                        <div class="flex justify-between"><dt class="muted">Webhook secret</dt><dd>{{ $storefront->webhook_secret ? 'Set' : 'Not set' }}</dd></div>
                    </dl>
                    <div class="sm:col-span-2"><x-ui.button type="submit">Save</x-ui.button></div>
                </form>
                <x-slot:footer>
                    <div class="flex flex-wrap items-end gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="key-round" @click="$dispatch('open-modal', 'key-{{ $storefront->uuid }}')">New API key</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" icon="refresh-cw" @click="$dispatch('open-modal', 'secret-{{ $storefront->uuid }}')">New webhook secret</x-ui.button>
                        <form method="POST" action="{{ route('licensing.storefronts.customers', $storefront->uuid) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <x-ui.form.field label="Link an existing organisation as customer">
                                <x-ui.form.select name="organisation" :options="$organisations" placeholder="Choose…" />
                            </x-ui.form.field>
                            <x-ui.button type="submit" size="sm" variant="secondary">Link</x-ui.button>
                        </form>
                    </div>
                </x-slot:footer>
            </x-ui.card>
            <x-ui.confirm-modal :name="'key-'.$storefront->uuid" title="Create a new API key?" :action="route('licensing.storefronts.key', $storefront->uuid)" confirm-label="Create key" danger>
                The current key stops working immediately. Update the storefront's WCC_BILLING_KEY straight away.
            </x-ui.confirm-modal>
            <x-ui.confirm-modal :name="'secret-'.$storefront->uuid" title="Create a new webhook secret?" :action="route('licensing.storefronts.secret', $storefront->uuid)" confirm-label="Create secret" danger>
                Webhooks are signed with the new secret from now on. Update the storefront's WCC_BILLING_WEBHOOK_SECRET straight away.
            </x-ui.confirm-modal>
        @endforeach

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card title="Add a storefront">
                <form method="POST" action="{{ route('licensing.storefronts.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.form.field label="Name" bag="storefront" required><x-ui.form.input name="name" bag="storefront" placeholder="MusicDesk" required /></x-ui.form.field>
                    <x-ui.form.field label="Code" bag="storefront" required><x-ui.form.input name="code" bag="storefront" placeholder="musicdesk" class="font-mono" required /></x-ui.form.field>
                    <x-ui.form.field label="Webhook URL" bag="storefront" class="sm:col-span-2"><x-ui.form.input name="webhook_url" bag="storefront" placeholder="https://musicdesk.tech/webhooks/wcc" /></x-ui.form.field>
                    <div><x-ui.button type="submit" icon="plus">Create storefront</x-ui.button></div>
                </form>
            </x-ui.card>

            <x-ui.card title="License server" description="For LabelDesk installations.">
                <dl class="space-y-2 text-sm">
                    <div><dt class="muted text-xs">Storefront API base URL</dt><dd class="font-mono text-xs select-all">{{ url('/api/v1/storefront') }}</dd></div>
                    <div><dt class="muted text-xs">License server base URL</dt><dd class="font-mono text-xs select-all">{{ url('/api/license/v1') }}</dd></div>
                    <div><dt class="muted text-xs">Token signing public key (Ed25519, base64) · kid {{ $keyId }}</dt><dd class="font-mono text-xs break-all select-all">{{ $publicKey }}</dd></div>
                </dl>
            </x-ui.card>
        </div>

        <x-ui.card title="Recent webhooks" :padding="false">
            @if ($events->isEmpty())
                <x-ui.empty-state title="No webhooks sent yet" icon="send" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>When</th><th>Storefront</th><th>Event</th><th>Result</th><th class="text-right">Attempts</th><th>Error</th></tr></thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr>
                                    <td class="muted whitespace-nowrap">{{ $event->created_at->inDisplayTimezone()->format('d M Y H:i') }}</td>
                                    <td>{{ $event->storefront?->name }}</td>
                                    <td class="font-mono text-xs">{{ $event->type }}</td>
                                    <td><x-ui.badge :tone="match ($event->status) { 'delivered' => 'success', 'failed' => 'danger', default => 'warning' }">{{ $event->status }}</x-ui.badge></td>
                                    <td class="text-right tabular-nums">{{ $event->attempts }}</td>
                                    <td class="muted max-w-xs break-words text-xs">{{ $event->last_error }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
