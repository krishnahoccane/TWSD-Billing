@php
    use App\Modules\Billing\Models\Invoice;
    use App\Modules\Billing\Support\Money;

    $c = $invoice->currency;
    $customer = $invoice->organisation_snapshot ?? [];
    $title = $invoice->invoice_number ?? 'Draft invoice';
@endphp
<x-layouts.app :title="$title" :description="($customer['name'] ?? '').' · '.$invoice->period_start->format('d M').' – '.$invoice->period_end->format('d M Y')"
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => 'Invoices', 'url' => route('invoices.index')], ['label' => $title]]">
    <x-slot:actions>
        <x-ui.badge :tone="$invoice->statusTone()">{{ ucfirst(str_replace('_', ' ', $invoice->displayStatus())) }}</x-ui.badge>
        @if ($zoho?->zoho_id)
            <x-ui.button variant="secondary" icon="download" :href="route('invoices.pdf', $invoice)" target="_blank">PDF</x-ui.button>
        @else
            <x-ui.button variant="secondary" icon="download" @click="window.print()">Print</x-ui.button>
        @endif
        @if ($canManage && $invoice->status === Invoice::DRAFT)
            <form method="POST" action="{{ route('invoices.recalculate', $invoice) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="refresh-cw">Recalculate</x-ui.button>
            </form>
            <x-ui.button icon="circle-check" @click="$dispatch('open-modal', 'issue')">Issue…</x-ui.button>
        @endif
    </x-slot:actions>

    <div class="space-y-4 print:hidden">
        @if ($errors->any())
            <x-ui.alert tone="danger">{{ $errors->first() }}</x-ui.alert>
        @endif
        @if ($errors->getBag('payment')->any() || $errors->getBag('credit')->any())
            <x-ui.alert tone="danger">{{ $errors->getBag('payment')->first() ?: $errors->getBag('credit')->first() }}</x-ui.alert>
        @endif
        @if ($invoice->status === Invoice::DRAFT)
            <x-ui.alert tone="warning" title="Draft">
                Not visible to the organisation and not yet numbered. Check the lines, then issue it. After issue the
                invoice can no longer be changed — corrections are made with a credit note or an adjustment.
            </x-ui.alert>
        @endif
        @if ($invoice->status === Invoice::VOID)
            <x-ui.alert tone="warning" title="Void">{{ $invoice->void_reason }}</x-ui.alert>
        @endif
        @if ($invoice->status !== \App\Modules\Billing\Models\Invoice::DRAFT)
            @if ($zoho === null)
                <x-ui.alert tone="info">Not sent to Zoho Books yet. It is sent automatically; the hourly sync retries anything that failed.</x-ui.alert>
            @else
                <x-ui.alert :tone="$zoho->tone() === 'success' ? 'success' : ($zoho->tone() === 'danger' ? 'danger' : 'warning')"
                            :title="$zoho->label().($zohoMode === 'mock' ? ' (mock)' : '')">
                    @if ($zoho->zoho_number)<p>Zoho Books invoice {{ $zoho->zoho_number }} · {{ str_replace('_', ' ', $zoho->zoho_status ?? '') }}@if ($zoho->zoho_balance_minor !== null) · balance in Zoho {{ Money::format($zoho->zoho_balance_minor, $c) }}@endif</p>@endif
                    @if ($zoho->last_error)<p class="mt-1 break-words text-xs">{{ $zoho->last_error }}</p>@endif
                </x-ui.alert>
            @endif
        @endif

        @if ($zoho?->zoho_url && $invoice->isOpen() && $invoice->balance_minor > 0)
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">Pay this invoice online</p>
                        <p class="muted text-xs">Opens the invoice in Zoho Books, where it can be paid if a payment gateway is enabled. The invoice is marked paid here only after Zoho confirms the payment.</p>
                    </div>
                    <x-ui.button :href="$zoho->zoho_url" target="_blank" rel="noopener noreferrer" icon="credit-card">
                        Pay {{ Money::format($invoice->balance_minor, $c) }}{{ $zohoMode === 'mock' ? ' (mock link)' : '' }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card class="mt-4" :padding="false">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
            <div>
                <p class="muted text-xs">Billed to</p>
                <p class="font-medium">{{ $customer['legal_name'] ?? $customer['name'] ?? '—' }}</p>
                <p class="muted whitespace-pre-line text-sm">{{ $customer['billing_address'] ?? $customer['registered_address'] ?? '' }}</p>
                @if (! empty($customer['gstin']))<p class="text-sm">GSTIN {{ $customer['gstin'] }}</p>@endif
            </div>
            <div class="text-sm">
                <p><span class="muted">Invoice number:</span> {{ $invoice->invoice_number ?? 'Not issued' }}</p>
                <p><span class="muted">Issue date:</span> {{ $invoice->issue_date?->format('d M Y') ?? '—' }}</p>
                <p><span class="muted">Due date:</span> {{ $invoice->due_date?->format('d M Y') ?? '—' }}</p>
                <p><span class="muted">Period:</span> {{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</p>
            </div>
            <div class="text-sm sm:text-right">
                <p class="muted text-xs">Balance due</p>
                <p class="text-2xl font-semibold tabular-nums">{{ Money::format($invoice->balance_minor, $c) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto border-t border-zinc-200 dark:border-zinc-800">
            <table class="data-table">
                <thead>
                    <tr><th>Item</th><th>Period</th><th class="text-right">Days</th><th class="text-right">Monthly price</th><th class="text-right">Amount</th><th class="text-right">GST</th><th class="text-right">Total</th></tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td>
                                <div class="font-medium">{{ $item->account_email ?? $item->description }}</div>
                                @if ($item->account_email)
                                    <div class="muted text-xs">{{ $item->account_name }}@if ($item->account_status === 'suspended') · suspended (billable)@endif</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs">@if ($item->period_start){{ $item->period_start->format('d M') }} – {{ $item->period_end->format('d M') }}@else — @endif</td>
                            <td class="text-right tabular-nums">@if ($item->days){{ $item->days }}/{{ $item->days_in_period }}@else — @endif</td>
                            <td class="text-right tabular-nums">
                                @if ($item->unit_price_minor !== null)
                                    {{ Money::format($item->unit_price_minor, $c) }}
                                    <div class="muted text-xs">{{ ['account' => 'mailbox price', 'organisation' => 'organisation price', 'platform' => 'default price'][$item->price_source] ?? '' }}</div>
                                @else — @endif
                            </td>
                            <td class="text-right tabular-nums">{{ Money::format($item->amount_minor, $c) }}</td>
                            <td class="text-right tabular-nums">{{ Money::format($item->tax_minor, $c) }}</td>
                            <td class="text-right tabular-nums">{{ Money::format($item->total_minor, $c) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-zinc-200 p-4 dark:border-zinc-800">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between"><dt class="muted">Mailbox charges</dt><dd class="tabular-nums">{{ Money::format($invoice->subtotal_minor, $c) }}</dd></div>
                @if ($invoice->adjustments_minor !== 0)
                    <div class="flex justify-between"><dt class="muted">Adjustments</dt><dd class="tabular-nums">{{ Money::format($invoice->adjustments_minor, $c) }}</dd></div>
                @endif
                @if ($invoice->tax_treatment === 'intra_state')
                    <div class="flex justify-between"><dt class="muted">CGST {{ Money::decimal(intdiv($invoice->tax_rate_bp, 2)) }}%</dt><dd class="tabular-nums">{{ Money::format($invoice->cgst_minor, $c) }}</dd></div>
                    <div class="flex justify-between"><dt class="muted">SGST {{ Money::decimal(intdiv($invoice->tax_rate_bp, 2)) }}%</dt><dd class="tabular-nums">{{ Money::format($invoice->sgst_minor, $c) }}</dd></div>
                @elseif ($invoice->tax_treatment === 'inter_state')
                    <div class="flex justify-between"><dt class="muted">IGST {{ Money::decimal($invoice->tax_rate_bp) }}%</dt><dd class="tabular-nums">{{ Money::format($invoice->igst_minor, $c) }}</dd></div>
                @else
                    <div class="flex justify-between"><dt class="muted">GST {{ Money::decimal($invoice->tax_rate_bp) }}%</dt><dd class="tabular-nums">{{ Money::format($invoice->tax_minor, $c) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-zinc-200 pt-1 font-semibold dark:border-zinc-800"><dt>Total</dt><dd class="tabular-nums">{{ Money::format($invoice->total_minor, $c) }}</dd></div>
                @if ($invoice->amount_paid_minor > 0)
                    <div class="flex justify-between"><dt class="muted">Paid</dt><dd class="tabular-nums">-{{ Money::format($invoice->amount_paid_minor, $c) }}</dd></div>
                @endif
                @if ($invoice->credited_minor > 0)
                    <div class="flex justify-between"><dt class="muted">Credit notes</dt><dd class="tabular-nums">-{{ Money::format($invoice->credited_minor, $c) }}</dd></div>
                @endif
                <div class="flex justify-between font-semibold"><dt>Balance due</dt><dd class="tabular-nums">{{ Money::format($invoice->balance_minor, $c) }}</dd></div>
            </dl>
        </div>
    </x-ui.card>

    @if ($invoice->allocations->isNotEmpty() || $invoice->creditNotes->isNotEmpty())
        <x-ui.card title="Payments and credits" :padding="false" class="mt-6 print:hidden">
            <table class="data-table">
                <tbody>
                    @foreach ($invoice->allocations as $allocation)
                        <tr>
                            <td>Payment · {{ $allocation->payment->paid_on->format('d M Y') }}</td>
                            <td class="muted">{{ $allocation->payment->reference ?? '—' }} ({{ $allocation->payment->source }})</td>
                            <td class="text-right tabular-nums">{{ Money::format($allocation->amount_minor, $c) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($invoice->creditNotes as $note)
                        <tr>
                            <td>Credit note {{ $note->credit_note_number }} · {{ $note->issued_at->inDisplayTimezone()->format('d M Y') }}</td>
                            <td class="muted">{{ $note->reason }}</td>
                            <td class="text-right tabular-nums">{{ Money::format($note->amount_minor, $c) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif

    @if ($canRecordPayment)
        <x-ui.card title="Record an offline payment" class="mt-6 print:hidden" description="For a bank transfer, UPI, cheque or cash payment you have verified. It is applied to this invoice and recorded in Zoho Books.">
            <form method="POST" action="{{ route('invoices.payments.store', $invoice) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                @csrf
                <x-ui.form.field label="Amount (₹)" for="pay_amount" name="amount" bag="payment" required>
                    <x-ui.form.input name="amount" id="pay_amount" bag="payment" inputmode="decimal" :value="Money::decimal($invoice->balance_minor)" required />
                </x-ui.form.field>
                <x-ui.form.field label="Received on" for="pay_date" name="paid_on" bag="payment" required>
                    <x-ui.form.input name="paid_on" id="pay_date" type="date" bag="payment" :value="now(config('wcc.billing.timezone'))->toDateString()" required />
                </x-ui.form.field>
                <x-ui.form.field label="Method" for="pay_method" name="method" bag="payment" required>
                    <x-ui.form.select name="method" id="pay_method" bag="payment" :options="$offlineMethods" />
                </x-ui.form.field>
                <x-ui.form.field label="Reference (UTR / cheque no.)" for="pay_reference" name="reference" bag="payment" required>
                    <x-ui.form.input name="reference" id="pay_reference" bag="payment" maxlength="100" required />
                </x-ui.form.field>
                <div class="sm:col-span-4"><x-ui.button type="submit" icon="circle-check">Record verified payment</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    @if ($canManage)
        <div class="mt-6 flex flex-wrap gap-2 print:hidden">
            @if ($zohoMode === 'mock' && $zoho?->zoho_id && $invoice->isOpen() && $invoice->balance_minor > 0 && ! app()->isProduction())
                <form method="POST" action="{{ route('invoices.simulate-zoho-payment', $invoice) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Simulate online payment (mock Zoho)</x-ui.button>
                </form>
            @endif
            @if ($invoice->isOpen())
                <x-ui.button variant="secondary" @click="$dispatch('open-modal', 'credit')">Issue credit note…</x-ui.button>
            @endif
            @if (in_array($invoice->status, [Invoice::DRAFT, Invoice::ISSUED], true) && $invoice->amount_paid_minor === 0 && $invoice->credited_minor === 0)
                <x-ui.button variant="ghost" @click="$dispatch('open-modal', 'void')">Void…</x-ui.button>
            @endif
        </div>

        @if ($invoice->status === Invoice::DRAFT)
            <x-ui.confirm-modal name="issue" title="Issue this invoice?" :action="route('invoices.issue', $invoice)" confirm-label="Issue invoice">
                It receives the next invoice number, becomes visible to the organisation and can no longer be edited.
                Total {{ Money::format($invoice->total_minor, $c) }}.
            </x-ui.confirm-modal>
        @endif
        <x-ui.confirm-modal name="void" title="Void this invoice?" :action="route('invoices.void', $invoice)" confirm-label="Void invoice" danger require-reason>
            The invoice is kept with its number and marked void. Nothing is owed on it. This cannot be undone.
        </x-ui.confirm-modal>
        @if ($invoice->isOpen())
            <x-ui.modal name="credit" title="Issue credit note">
                <form method="POST" action="{{ route('invoices.credit-notes.store', $invoice) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">Reduces what is owed on this invoice without changing the invoice. Outstanding: {{ Money::format($invoice->balance_minor, $c) }}.</p>
                    <x-ui.form.field label="Amount including tax (₹)" for="credit_amount" name="amount" bag="credit" required>
                        <x-ui.form.input name="amount" id="credit_amount" bag="credit" inputmode="decimal" required />
                    </x-ui.form.field>
                    <x-ui.form.field label="Reason" for="credit_reason" name="reason" bag="credit" required>
                        <x-ui.form.textarea name="reason" id="credit_reason" bag="credit" required maxlength="500" />
                    </x-ui.form.field>
                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'credit')">Cancel</x-ui.button>
                        <x-ui.button type="submit">Issue credit note</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endif
</x-layouts.app>
