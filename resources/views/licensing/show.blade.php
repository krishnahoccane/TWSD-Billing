@php
    use App\Modules\Billing\Support\Money;
    $status = $license->displayStatus();
    $production = $activations->where('environment', 'production')->whereIn('status', ['pending', 'active'])->count();
    $details = [
        'Customer' => $license->organisation?->name,
        'Product / plan' => $license->product?->name.' · '.$license->plan?->name,
        'Billing type' => ucfirst($license->billing_type),
        'Purchased' => $license->purchased_at?->inDisplayTimezone()->format('d M Y'),
        'Expires' => $license->expires_at?->inDisplayTimezone()->format('d M Y') ?? 'Never (lifetime)',
        'Updates & support until' => $license->support_until?->inDisplayTimezone()->format('d M Y').($license->supportExpired() ? ' (expired)' : ''),
        'Production domains' => $production.' of '.$license->domainLimit().' ('.$license->domains_included.' included + '.$license->domains_extra.' extra)',
        'Auto-renew' => $license->auto_renew ? 'On' : 'Off',
        'Price paid' => Money::format($license->price_paid_minor, $license->currency),
        'Storefront' => $license->storefront?->name ?? '—',
        'Pending until' => $license->pending_until?->inDisplayTimezone()->format('d M Y'),
        'Reason' => $license->status_reason,
    ];
@endphp
<x-layouts.app :title="$license->number" :description="$license->organisation?->name"
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Licenses', 'url' => route('licensing.index')], ['label' => $license->number]]">
    <x-slot:actions>
        <x-ui.badge :tone="$license->statusTone()">{{ ucfirst($status) }}</x-ui.badge>
        <x-ui.button variant="secondary" icon="eye" @click="$dispatch('open-modal', 'reveal-key')">Reveal key</x-ui.button>
        @if ($canManage && $license->status !== 'revoked')
            <x-ui.button variant="secondary" icon="pencil" @click="$dispatch('open-modal', 'edit-license')">Edit</x-ui.button>
            @if (in_array($license->status, ['active', 'pending'], true))
                <x-ui.button variant="secondary" icon="pause-circle" @click="$dispatch('open-modal', 'suspend-license')">Suspend</x-ui.button>
            @else
                <x-ui.button variant="secondary" icon="power" @click="$dispatch('open-modal', 'reinstate-license')">Reinstate</x-ui.button>
            @endif
            <x-ui.button variant="danger" icon="ban" @click="$dispatch('open-modal', 'revoke-license')">Revoke</x-ui.button>
        @endif
    </x-slot:actions>

    <div class="space-y-6">
        @if ($errors->any())
            <x-ui.alert tone="danger">{{ $errors->first() ?: collect($errors->getBags())->map->first()->filter()->first() }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <x-ui.card title="Details" class="xl:col-span-2">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ($details as $label => $value)
                        @if (filled($value))
                            <div><dt class="muted text-xs">{{ $label }}</dt><dd class="text-sm">{{ $value }}</dd></div>
                        @endif
                    @endforeach
                    <div><dt class="muted text-xs">Key</dt><dd class="font-mono text-sm">{{ $license->maskedKey() }}</dd></div>
                    <div><dt class="muted text-xs">API id</dt><dd class="font-mono text-xs select-all">{{ $license->publicId() }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Orders" :padding="false" description="Paid on the storefront; the invoice is the storefront's.">
                @if ($orders->isEmpty())
                    <x-ui.empty-state title="No orders" icon="receipt-text" />
                @else
                    <table class="data-table">
                        <tbody>
                            @foreach ($orders as $order)
                                <tr>
                                    <td>{{ str_replace('_', ' ', $order->kind) }}
                                        @if (($order->payment_status ?? 'paid') !== 'paid')<span class="muted text-xs">· {{ $order->payment_status === 'refunded' ? 'refunded' : 'awaiting payment' }}</span>@endif
                                        <div class="muted text-xs">{{ $order->invoice_number ?? $order->payment_reference ?? '—' }}@if ($order->refund_reason) · {{ $order->refund_reason }}@endif</div></td>
                                    <td class="text-right tabular-nums">{{ Money::format($order->amount_minor, $order->currency) }}@if ($order->amount_usd_minor !== null && $order->currency !== 'USD')<div class="muted text-xs">≈ {{ Money::format($order->amount_usd_minor, 'USD') }}</div>@endif<div class="muted text-xs">{{ $order->created_at->inDisplayTimezone()->format('d M Y') }}</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Domains & installations" :padding="false">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Domain</th><th>Environment</th><th>Status</th><th>Version</th><th>Last check-in</th><th>Server</th>@if ($canManage)<th></th>@endif</tr></thead>
                    <tbody>
                        @forelse ($activations as $activation)
                            <tr @class(['opacity-60' => $activation->status === 'deactivated'])>
                                <td class="font-medium">{{ $activation->domain }}</td>
                                <td>{{ ucfirst($activation->environment) }}</td>
                                <td>
                                    <x-ui.badge :tone="match ($activation->status) { 'active' => $activation->isOnline() ? 'success' : 'warning', 'pending' => 'info', default => 'neutral' }">
                                        {{ $activation->status === 'active' ? ($activation->isOnline() ? 'Online' : 'Offline') : ucfirst($activation->status) }}
                                    </x-ui.badge>
                                </td>
                                <td class="tabular-nums">{{ $activation->version ?? '—' }}@if ($activation->php_version)<div class="muted text-xs">PHP {{ $activation->php_version }}</div>@endif</td>
                                <td class="muted whitespace-nowrap">{{ $activation->last_check_in_at?->diffForHumans() ?? '—' }}</td>
                                <td class="muted font-mono text-xs">{{ $activation->maskedIp() ?? '—' }}</td>
                                @if ($canManage)
                                    <td class="text-right">
                                        @if ($activation->status !== 'deactivated')
                                            <form method="POST" action="{{ route('licensing.activations.destroy', [$license, $activation->uuid]) }}">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" size="sm" variant="ghost">Deactivate</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No domains yet" icon="globe" description="A domain appears when the installation activates or when one is added here." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($canManage && ! in_array($license->status, ['suspended', 'revoked'], true))
                <x-slot:footer>
                    <form method="POST" action="{{ route('licensing.activations.store', $license) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <x-ui.form.field label="Add domain" for="domain" bag="activation">
                            <x-ui.form.input name="domain" bag="activation" placeholder="portal.example.com" />
                        </x-ui.form.field>
                        <x-ui.form.field label="Environment" for="environment">
                            <x-ui.form.select name="environment" :options="['production' => 'Production', 'staging' => 'Staging (free)']" />
                        </x-ui.form.field>
                        <x-ui.button type="submit" variant="secondary" icon="plus">Add</x-ui.button>
                    </form>
                </x-slot:footer>
            @endif
        </x-ui.card>

        <x-ui.card title="History" :padding="false">
            <table class="data-table">
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="muted whitespace-nowrap">{{ $event->created_at->inDisplayTimezone()->format('d M Y H:i') }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $event->event)) }}
                                @if ($event->details)<div class="muted text-xs">{{ collect($event->details)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }}</div>@endif
                            </td>
                            <td class="muted text-xs">{{ $event->actor }}</td>
                        </tr>
                    @empty
                        <tr><td class="muted">No history yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.confirm-modal name="reveal-key" title="Reveal the license key?" :action="route('licensing.reveal', $license)" confirm-label="Reveal">
        The full key is shown on the next page and the reveal is recorded in the audit log. Make sure nobody else can see your screen.
    </x-ui.confirm-modal>

    @if ($canManage && $license->status !== 'revoked')
        <x-ui.modal name="edit-license" title="Edit license" max-width="max-w-lg">
            <form method="POST" action="{{ route('licensing.update', $license) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @csrf @method('PUT')
                <x-ui.form.field label="Plan" for="edit_plan" name="plan" bag="license" class="sm:col-span-2">
                    <x-ui.form.select name="plan" id="edit_plan" bag="license" :value="$license->plan?->uuid"
                                      :options="$license->product->plans->mapWithKeys(fn ($p) => [$p->uuid => $p->name.' ('.$p->included_domains.' domains)'])->all()" />
                </x-ui.form.field>
                <x-ui.form.field label="Extra domains" for="domains_extra" bag="license">
                    <x-ui.form.input type="number" name="domains_extra" bag="license" :value="$license->domains_extra" min="{{ $license->domains_extra }}" max="500" />
                </x-ui.form.field>
                <x-ui.form.field label="Expires" for="expires_at" bag="license" hint="Annual licenses only.">
                    <x-ui.form.input type="date" name="expires_at" bag="license" :value="$license->expires_at?->inDisplayTimezone()->toDateString()" :disabled="$license->billing_type === 'lifetime'" />
                </x-ui.form.field>
                <x-ui.form.field label="Updates & support until" for="support_until" bag="license">
                    <x-ui.form.input type="date" name="support_until" bag="license" :value="$license->support_until?->inDisplayTimezone()->toDateString()" />
                </x-ui.form.field>
                <div class="flex items-end"><input type="hidden" name="auto_renew" value="0"><x-ui.form.checkbox name="auto_renew" label="Auto-renew" :checked="$license->auto_renew" /></div>
                <x-ui.form.field label="Reason" for="edit_reason" name="reason" bag="license" class="sm:col-span-2" required>
                    <x-ui.form.textarea name="reason" id="edit_reason" bag="license" required minlength="3" maxlength="500" />
                </x-ui.form.field>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'edit-license')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach (['suspend' => ['suspended', 'Suspend this license?', 'Installations become read-only at their next check-in until the license is reinstated.', true],
                   'reinstate' => ['active', 'Reinstate this license?', 'Installations work normally again at their next check-in.', false],
                   'revoke' => ['revoked', 'Revoke this license permanently?', 'Revoking cannot be undone. Installations become read-only. Use it for refunds, chargebacks or fraud.', true]] as $name => [$to, $title, $text, $danger])
            <x-ui.confirm-modal :name="$name.'-license'" :title="$title" :action="route('licensing.status', $license)" :confirm-label="ucfirst($name)"
                                :danger="$danger" require-reason :confirm-text="$name === 'revoke' ? $license->number : null">
                <input type="hidden" name="status" value="{{ $to }}">
                {{ $text }}
            </x-ui.confirm-modal>
        @endforeach

        @if ($errors->getBag('license')->any())
            <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'edit-license'))"></div>
        @endif
    @endif
</x-layouts.app>
