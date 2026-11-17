@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Zoho Books integration" description="Invoices are sent to Zoho Books; payments are read back from it."
               :breadcrumbs="[['label' => 'Integrations'], ['label' => 'Zoho Books']]">
    <div class="space-y-6">
        @if ($mode === 'mock')
            <x-ui.alert tone="warning" title="Mock mode — not connected to Zoho Books">
                Invoices and payments go to a built-in mock, not your Zoho Books. Links and PDFs are labelled "mock". To go
                live, set the ZOHO_* values on the server, set <span class="font-mono text-xs">ZOHO_ENABLED=true</span>, then connect below.
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card title="Connection" :padding="false">
                <table class="data-table">
                    <tbody>
                        @foreach ($checks as [$label, $ok, $detail])
                            <tr>
                                <td class="w-8"><x-ui.icon :name="$ok ? 'circle-check' : 'circle-alert'" class="size-4 {{ $ok ? 'text-emerald-600' : 'text-amber-600' }}" /></td>
                                <td>{{ $label }}</td>
                                <td class="muted max-w-xs break-words text-right text-xs">{{ $detail }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-slot:footer>
                    <div class="flex flex-wrap gap-2">
                        @if ($mode === 'live')
                            <x-ui.button :href="route('integrations.zoho.connect')" icon="plug">{{ $connected ? 'Reconnect' : 'Connect Zoho Books' }}</x-ui.button>
                        @endif
                        <form method="POST" action="{{ route('integrations.zoho.test') }}">@csrf <x-ui.button type="submit" variant="secondary">Test connection</x-ui.button></form>
                        <form method="POST" action="{{ route('integrations.zoho.sync') }}">@csrf <x-ui.button type="submit" variant="secondary" icon="refresh-cw">Sync now</x-ui.button></form>
                        @if ($connected)
                            <x-ui.button variant="ghost" @click="$dispatch('open-modal', 'disconnect')">Disconnect</x-ui.button>
                        @endif
                    </div>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Zoho Books settings" description="Ids from your Zoho Books organisation. Stored here; not secret.">
                <form method="POST" action="{{ route('integrations.zoho.settings') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.form.field label="Organisation id" for="organization_id" hint="Zoho Books → Settings → Organisation profile.">
                        <x-ui.form.input name="organization_id" :value="$settings['organization_id'] ?? ''" class="font-mono" />
                    </x-ui.form.field>
                    <x-ui.form.field label="GST 18% tax id" for="tax_id" hint="The GST 18% tax (or tax group) in Zoho Books. Zoho splits it into CGST/SGST or IGST.">
                        <x-ui.form.input name="tax_id" :value="$settings['tax_id'] ?? ''" class="font-mono" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Item id (optional)" for="item_id" hint="A 'Google Workspace mailbox' service item with its SAC code.">
                        <x-ui.form.input name="item_id" :value="$settings['item_id'] ?? ''" class="font-mono" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Deposit account id (optional)" for="deposit_account_id" hint="Bank account that offline payments are recorded into.">
                        <x-ui.form.input name="deposit_account_id" :value="$settings['deposit_account_id'] ?? ''" class="font-mono" />
                    </x-ui.form.field>
                    <div><x-ui.button type="submit">Save settings</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="Payment webhook" description="Lets Zoho tell this application about a new payment straight away. Without it, payments are picked up by the hourly sync.">
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                <li>In Zoho Books: Settings → Automation → Webhooks → New webhook, module <span class="font-medium">Customer Payments</span>, method POST, URL:
                    <span class="font-mono text-xs select-all">{{ $webhookUrl }}</span></li>
                <li>Add a custom header <span class="font-mono text-xs">X-WCC-Webhook-Token</span> with the value of <span class="font-mono text-xs">ZOHO_WEBHOOK_SECRET</span> (or use the same value as Zoho's secret token).</li>
                <li>Attach it to a workflow rule that runs when a customer payment is created.</li>
            </ol>
            <p class="muted mt-2 text-xs">A webhook only tells us which payment to look at — the payment is always re-read from Zoho's API before an invoice is marked paid.</p>
        </x-ui.card>

        <x-ui.card title="Invoices needing attention" :padding="false" description="Invoices whose Zoho copy failed to sync or differs from this application.">
            @if ($problems->isEmpty())
                <x-ui.empty-state title="Everything matches Zoho Books" icon="circle-check" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Invoice</th><th>Organisation</th><th>Status</th><th>Detail</th><th>Last sync</th></tr></thead>
                        <tbody>
                            @foreach ($problems as $row)
                                <tr>
                                    <td>@if ($row['invoice'])<a href="{{ route('invoices.show', $row['invoice']) }}" class="font-medium hover:underline">{{ $row['invoice']->invoice_number }}</a>@else — @endif</td>
                                    <td>{{ $row['invoice']?->organisation?->name ?? '—' }}</td>
                                    <td><x-ui.badge :tone="$row['mapping']->tone()">{{ $row['mapping']->label() }}</x-ui.badge></td>
                                    <td class="max-w-md break-words text-xs">{{ $row['mapping']->last_error }}</td>
                                    <td class="muted whitespace-nowrap">{{ $row['mapping']->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card title="Recent webhooks" :padding="false">
                @if ($webhooks->isEmpty())
                    <x-ui.empty-state title="No webhooks received" icon="inbox" />
                @else
                    <table class="data-table">
                        <thead><tr><th>Received</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($webhooks as $event)
                                <tr>
                                    <td class="muted whitespace-nowrap">{{ $event->created_at->diffForHumans() }}</td>
                                    <td>{{ str_replace('_', ' ', $event->event_type ?? '—') }}</td>
                                    <td><x-ui.badge :tone="['processed' => 'success', 'failed' => 'danger', 'ignored' => 'neutral'][$event->status] ?? 'info'">{{ ucfirst($event->status) }}</x-ui.badge>
                                        @if ($event->error)<div class="muted mt-1 text-xs">{{ $event->error }}</div>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>

            <x-ui.card title="Recent Zoho calls" :padding="false">
                @if ($logs->isEmpty())
                    <x-ui.empty-state title="No calls yet" icon="scroll-text" />
                @else
                    <table class="data-table">
                        <thead><tr><th>When</th><th>Operation</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="muted whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                                    <td class="font-mono text-xs">{{ $log->operation }} <span class="muted">({{ $log->provider }})</span></td>
                                    <td><x-ui.badge :tone="$log->status === 'success' ? 'success' : 'danger'">{{ ucfirst($log->status) }}</x-ui.badge>
                                        @if ($log->error)<div class="mt-1 max-w-xs break-words text-xs text-red-600 dark:text-red-400">{{ $log->error }}</div>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($connected)
        <x-ui.confirm-modal name="disconnect" title="Disconnect Zoho Books?" :action="route('integrations.zoho.disconnect')" confirm-label="Disconnect" danger>
            The stored access is revoked. Invoices stop being sent to Zoho and payments stop being read until you connect again.
        </x-ui.confirm-modal>
    @endif
</x-layouts.app>
