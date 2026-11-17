@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Billing cycles" description="One cycle per organisation per month. Billing is in arrears: a month is billed after it ends."
               :breadcrumbs="[['label' => 'Billing', 'url' => route('billing.overview')], ['label' => 'Billing cycles']]">
    <x-ui.card title="Run billing for a month" class="mb-6"
               description="Creates DRAFT invoices for every active and suspended organisation — use this for older months. Running the same month again never creates a second invoice.">
        <form method="POST" action="{{ route('billing.cycles.run') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <x-ui.form.field label="Month" for="month" required>
                <x-ui.form.input name="month" type="month" :value="$defaultMonth" required class="w-48" />
            </x-ui.form.field>
            <x-ui.button type="submit" icon="calendar-range">Generate draft invoices</x-ui.button>
        </form>
        <p class="muted mt-3 text-xs">Automatic: on the 1st of each month at 03:00 the previous month is billed and the invoices are issued and sent to Zoho Books without review.</p>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head>
            <th>Period</th><th>Organisation</th><th>Status</th><th>Invoice</th><th class="text-right">Total</th><th>Generated</th>
        </x-slot:head>

        @forelse ($cycles as $cycle)
            <tr>
                <td class="whitespace-nowrap">{{ $cycle->period_start->format('d M') }} – {{ $cycle->period_end->format('d M Y') }}</td>
                <td>{{ $cycle->organisation?->name ?? '—' }}</td>
                <td>
                    <x-ui.badge :tone="['invoiced' => 'success', 'failed' => 'danger', 'skipped' => 'neutral'][$cycle->status] ?? 'info'">
                        {{ $cycle->status === 'skipped' ? 'Nothing to bill' : ucfirst($cycle->status) }}
                    </x-ui.badge>
                    @if ($cycle->error)<div class="mt-1 max-w-md break-words text-xs text-red-600 dark:text-red-400">{{ $cycle->error }}</div>@endif
                </td>
                <td>
                    @if ($cycle->invoice)
                        <a href="{{ route('invoices.show', $cycle->invoice) }}" class="hover:underline">{{ $cycle->invoice->invoice_number ?? 'Draft' }}</a>
                    @else — @endif
                </td>
                <td class="text-right tabular-nums">{{ $cycle->invoice ? Money::format($cycle->invoice->total_minor, $cycle->invoice->currency) : '—' }}</td>
                <td class="muted whitespace-nowrap">{{ $cycle->generated_at?->diffForHumans() ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="!p-0"><x-ui.empty-state title="No billing cycles yet" icon="calendar-range" description="Run billing for a completed month to create the first draft invoices." /></td></tr>
        @endforelse

        @if ($cycles->hasPages())
            <x-slot:footer>{{ $cycles->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
