<x-layouts.app title="WhatsApp notifications" description="Sends organisation notifications to the organisation's phone and its users' phones."
               :breadcrumbs="[['label' => 'Integrations'], ['label' => 'WhatsApp']]">
    <div class="space-y-6">
        @if ($mode === 'mock')
            <x-ui.alert tone="warning" title="Mock mode — no real messages">
                Messages are recorded in the integration log but not sent. Real messages are sent in production, or locally with
                <span class="font-mono text-xs">WHATSAPP_ENABLED=true</span>.
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card title="API keys" description="Stored encrypted. They are never shown again — enter a new value to replace one.">
                <dl class="mb-4 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="muted">Keys</dt>
                        <dd>@if ($hasKeys)<x-ui.badge tone="success">Set</x-ui.badge>@else<x-ui.badge tone="warning">Not set</x-ui.badge>@endif</dd></div>
                    <div class="flex justify-between"><dt class="muted">Last test</dt><dd>{{ $lastTested?->diffForHumans() ?? 'Never' }}</dd></div>
                </dl>
                <form method="POST" action="{{ route('integrations.whatsapp.keys') }}" class="space-y-4" autocomplete="off">
                    @csrf
                    <x-ui.form.field label="App key" for="appkey" bag="keys" hint="Leave blank to keep the stored key.">
                        <x-ui.form.input name="appkey" type="password" bag="keys" autocomplete="new-password" class="font-mono" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Auth key" for="authkey" bag="keys" hint="Leave blank to keep the stored key.">
                        <x-ui.form.input name="authkey" type="password" bag="keys" autocomplete="new-password" class="font-mono" />
                    </x-ui.form.field>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" icon="key-round">Save keys</x-ui.button>
                        @if ($hasKeys)
                            <x-ui.button variant="ghost" @click="$dispatch('open-modal', 'remove-keys')">Remove keys</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card title="Send a test message">
                <form method="POST" action="{{ route('integrations.whatsapp.test') }}" class="space-y-4">
                    @csrf
                    <x-ui.form.field label="Mobile number" for="to" bag="test" hint="With country code, e.g. 919876543210. Sent immediately.">
                        <x-ui.form.input name="to" bag="test" inputmode="tel" class="font-mono" />
                    </x-ui.form.field>
                    <x-ui.button type="submit" variant="secondary" icon="send">Send test</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="What is sent" description="To the organisation's contact phone and to the phones of its users (organisation managers, assigned Operations Admins, billing managers). Users can turn WhatsApp off per event under Account → Notifications.">
            <form method="POST" action="{{ route('integrations.whatsapp.settings') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="enabled" value="0">
                <x-ui.form.checkbox name="enabled" label="Send WhatsApp notifications" :checked="$settings['enabled']" />
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($events as $value => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="events[]" value="{{ $value }}" @checked(in_array($value, $settings['events'], true))
                                   class="size-4 rounded border-zinc-300 accent-accent-600 dark:border-zinc-700">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-ui.button type="submit">Save</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Recent messages" :padding="false" description="Numbers are masked; message text is not stored.">
            @if ($recent->isEmpty())
                <x-ui.empty-state title="No messages yet" icon="message-circle" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>When</th><th>To</th><th>Result</th><th>Error</th></tr></thead>
                        <tbody>
                            @foreach ($recent as $log)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $log->created_at?->diffForHumans() }}</td>
                                    <td class="font-mono text-xs">{{ $log->target }}</td>
                                    <td><x-ui.badge :tone="$log->status === 'success' ? 'success' : 'danger'">{{ $log->status }}</x-ui.badge></td>
                                    <td class="muted max-w-md break-words text-xs">{{ $log->error }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.confirm-modal name="remove-keys" title="Remove WhatsApp keys?" :action="route('integrations.whatsapp.keys.clear')" method="DELETE"
                        confirm-label="Remove keys" danger>
        No WhatsApp messages will be sent until new keys are saved.
    </x-ui.confirm-modal>
</x-layouts.app>
