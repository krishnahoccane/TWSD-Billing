@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app :title="$outstanding ? 'Outstanding payments' : 'Payments'"
               :description="$outstanding ? 'Issued invoices with a balance still to pay.' : 'Payments received and how they were applied to invoices.'"
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => $outstanding ? 'Outstanding' : 'Payments']]">
    <x-ui.alert tone="info" class="mb-6">
        Payments come from Zoho Books (online payments and payments your accountant records there) and from offline
        payments a Super Admin verifies here. An invoice is marked paid only from a verified payment record — never from a payment page.
    </x-ui.alert>

    @if ($outstanding)
        <x-ui.table>
            <x-slot:head>
                <th>Invoice</th>
                @if ($showOrganisation)<th>Organisation</th>@endif
                <th>Due</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Balance</th>
            </x-slot:head>
            @forelse ($invoices as $invoice)
                <tr>
                    <td><a href="{{ route('invoices.show', $invoice) }}" class="font-medium hover:underline">{{ $invoice->invoice_number }}</a></td>
                    @if ($showOrganisation)<td>{{ $invoice->organisation?->name ?? '—' }}</td>@endif
                    <td class="whitespace-nowrap">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                    <td><x-ui.badge :tone="$invoice->statusTone()">{{ ucfirst(str_replace('_', ' ', $invoice->displayStatus())) }}</x-ui.badge></td>
                    <td class="text-right tabular-nums">{{ Money::format($invoice->total_minor, $invoice->currency) }}</td>
                    <td class="text-right tabular-nums font-medium">{{ Money::format($invoice->balance_minor, $invoice->currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0"><x-ui.empty-state title="Nothing outstanding" icon="circle-check" /></td></tr>
            @endforelse
            @if ($invoices->hasPages())
                <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Date</th>
                @if ($showOrganisation)<th>Organisation</th>@endif
                <th>Reference</th><th>Source</th><th>Applied to</th><th class="text-right">Amount</th><th class="text-right">Unapplied credit</th>
            </x-slot:head>
            @forelse ($payments as $payment)
                <tr>
                    <td class="whitespace-nowrap">{{ $payment->paid_on->format('d M Y') }}</td>
                    @if ($showOrganisation)<td>{{ $payment->organisation?->name ?? '—' }}</td>@endif
                    <td>{{ $payment->reference ?? '—' }}</td>
                    <td><x-ui.badge :dot="false">{{ $payment->source }}</x-ui.badge></td>
                    <td class="text-xs">{{ $payment->allocations->map(fn ($a) => $a->invoice?->invoice_number)->filter()->implode(', ') ?: '—' }}</td>
                    <td class="text-right tabular-nums">{{ Money::format($payment->amount_minor, $payment->currency) }}</td>
                    <td class="text-right tabular-nums">{{ Money::format($payment->unallocated_minor, $payment->currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No payments recorded" icon="credit-card" /></td></tr>
            @endforelse
            @if ($payments->hasPages())
                <x-slot:footer>{{ $payments->links() }}</x-slot:footer>
            @endif
        </x-ui.table>
    @endif
</x-layouts.app>
