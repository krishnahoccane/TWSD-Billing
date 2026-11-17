@php
    use App\Modules\Billing\Support\Money;
@endphp
<x-layouts.app :title="'Pricing — '.$organisation->name" description="Prices never change invoices that have already been issued."
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => 'Pricing', 'url' => route('pricing.organisations')], ['label' => $organisation->name]]">
    @if ($errors->any() && ! $errors->hasBag('price') && ! $errors->hasBag('override') && ! $errors->hasBag('adjustment') )
        <x-ui.alert tone="danger" class="mb-6">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-ui.card title="Organisation price" description="Applies to every mailbox of this organisation without its own override.">
            <form method="POST" action="{{ route('pricing.organisations.price', $organisation) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.form.field label="Monthly price (₹)" for="org_price" name="price" bag="price" required>
                    <x-ui.form.input name="price" id="org_price" bag="price" inputmode="decimal" placeholder="250.00" required />
                </x-ui.form.field>
                <x-ui.form.field label="Effective from" for="org_from" name="effective_from" bag="price" required>
                    <x-ui.form.input name="effective_from" id="org_from" type="date" bag="price" :value="$today" required />
                </x-ui.form.field>
                <div class="flex items-end"><x-ui.button type="submit">Save price</x-ui.button></div>
            </form>

            <table class="data-table mt-4">
                <thead><tr><th>From</th><th>Until</th><th class="text-right">Price</th></tr></thead>
                <tbody>
                    @forelse ($prices as $price)
                        <tr>
                            <td>{{ $price->effective_from->format('d M Y') }}</td>
                            <td class="muted">{{ $price->effective_until?->format('d M Y') ?? 'Current' }}</td>
                            <td class="text-right tabular-nums">{{ Money::format($price->monthly_price_minor, $price->currency) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No organisation price — the platform default applies.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card title="Tax (GST)">
            <p class="text-sm">GST <span class="font-medium">{{ $taxRate }}%</span> on every invoice.</p>
            <p class="muted mt-1 text-sm">Zoho Books splits it into CGST + SGST or IGST from the customer's place of supply.</p>
        </x-ui.card>

        <x-ui.card title="Mailbox price overrides" description="A different price for one mailbox. Takes precedence over the organisation price.">
            <form method="POST" action="{{ route('pricing.organisations.overrides.store', $organisation) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.form.field label="Mailbox address" for="override_email" name="email" bag="override" required>
                    <x-ui.form.input name="email" id="override_email" type="email" bag="override" required />
                </x-ui.form.field>
                <x-ui.form.field label="Monthly price (₹)" for="override_price" name="price" bag="override" required>
                    <x-ui.form.input name="price" id="override_price" bag="override" inputmode="decimal" required />
                </x-ui.form.field>
                <x-ui.form.field label="Effective from" for="override_from" name="effective_from" bag="override" required>
                    <x-ui.form.input name="effective_from" id="override_from" type="date" bag="override" :value="$today" required />
                </x-ui.form.field>
                <x-ui.form.field label="Reason" for="override_reason" name="reason" bag="override">
                    <x-ui.form.input name="reason" id="override_reason" bag="override" maxlength="500" />
                </x-ui.form.field>
                <div><x-ui.button type="submit">Save override</x-ui.button></div>
            </form>

            @if ($overrides->isNotEmpty())
                <div class="mt-4 overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Mailbox</th><th>From</th><th>Until</th><th class="text-right">Price</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($overrides as $override)
                                <tr>
                                    <td>{{ $override->account?->primary_email ?? '—' }}</td>
                                    <td>{{ $override->effective_from->format('d M Y') }}</td>
                                    <td class="muted">{{ $override->effective_until?->format('d M Y') ?? 'Current' }}</td>
                                    <td class="text-right tabular-nums">{{ Money::format($override->monthly_price_minor, $override->currency) }}</td>
                                    <td class="text-right">
                                        @if ($override->effective_until === null)
                                            <form method="POST" action="{{ route('pricing.organisations.overrides.end', [$organisation, $override->uuid]) }}" class="flex items-center justify-end gap-2">
                                                @csrf
                                                <input type="date" name="effective_from" value="{{ $today }}" class="control w-auto py-1" aria-label="End override from">
                                                <x-ui.button type="submit" variant="ghost" size="sm">End</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        @if ($canAdjust)
            <x-ui.card title="Adjustments" description="One-off credits or charges, added to the next invoice generated for this organisation.">
                <form method="POST" action="{{ route('pricing.organisations.adjustments.store', $organisation) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.form.field label="Type" for="adjustment_type" name="type" bag="adjustment" required>
                        <x-ui.form.select name="type" id="adjustment_type" bag="adjustment" :options="['credit' => 'Credit (reduces the invoice)', 'debit' => 'Charge (adds to the invoice)']" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Amount before tax (₹)" for="adjustment_amount" name="amount" bag="adjustment" required>
                        <x-ui.form.input name="amount" id="adjustment_amount" bag="adjustment" inputmode="decimal" required />
                    </x-ui.form.field>
                    <x-ui.form.field label="Description (shown on the invoice)" for="adjustment_description" name="description" bag="adjustment" required class="sm:col-span-2">
                        <x-ui.form.input name="description" id="adjustment_description" bag="adjustment" maxlength="255" required />
                    </x-ui.form.field>
                    <x-ui.form.checkbox name="taxable" label="GST applies to this adjustment" :checked="true" />
                    <div><x-ui.button type="submit">Save adjustment</x-ui.button></div>
                </form>

                @if ($adjustments->isNotEmpty())
                    <div class="mt-4 overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Description</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($adjustments as $adjustment)
                                    <tr>
                                        <td>{{ $adjustment->description }}</td>
                                        <td class="text-right tabular-nums">{{ Money::format($adjustment->signedAmount(), $adjustment->currency) }}</td>
                                        <td><x-ui.badge :tone="$adjustment->status === 'applied' ? 'success' : ($adjustment->status === 'approved' ? 'info' : 'neutral')">{{ $adjustment->status === 'approved' ? 'Waiting for next invoice' : ucfirst($adjustment->status) }}</x-ui.badge></td>
                                        <td class="text-right">
                                            @if ($adjustment->status === 'approved' && $adjustment->invoice_id === null)
                                                <form method="POST" action="{{ route('pricing.organisations.adjustments.cancel', [$organisation, $adjustment->uuid]) }}">
                                                    @csrf
                                                    <x-ui.button type="submit" variant="ghost" size="sm">Cancel</x-ui.button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
