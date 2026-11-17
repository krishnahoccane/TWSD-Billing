@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Invoices" description="Invoices for the organisations you can see."
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => 'Invoices']]">
    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('invoices.index') }}">
                <x-ui.filter-select label="Status" name="status" :selected="$status" x-data @change="$el.form.submit()"
                                    :options="['draft' => 'Draft', 'issued' => 'Issued', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'void' => 'Void']" />
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Invoice</th>
            @if ($showOrganisation)<th>Organisation</th>@endif
            <th>Period</th><th>Status</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Balance</th>
        </x-slot:head>

        @forelse ($invoices as $invoice)
            <tr>
                <td><a href="{{ route('invoices.show', $invoice) }}" class="font-medium hover:underline">{{ $invoice->invoice_number ?? 'Draft' }}</a></td>
                @if ($showOrganisation)<td>{{ $invoice->organisation?->name ?? '—' }}</td>@endif
                <td class="whitespace-nowrap">{{ $invoice->period_start->format('M Y') }}</td>
                <td><x-ui.badge :tone="$invoice->statusTone()">{{ ucfirst(str_replace('_', ' ', $invoice->displayStatus())) }}</x-ui.badge></td>
                <td class="muted whitespace-nowrap">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                <td class="text-right tabular-nums">{{ Money::format($invoice->total_minor, $invoice->currency) }}</td>
                <td class="text-right tabular-nums">{{ Money::format($invoice->balance_minor, $invoice->currency) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="!p-0"><x-ui.empty-state title="No invoices" icon="receipt-text" description="Invoices appear here once they have been issued." /></td></tr>
        @endforelse

        @if ($invoices->hasPages())
            <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
