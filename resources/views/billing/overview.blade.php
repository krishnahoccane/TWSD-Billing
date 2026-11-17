@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Billing overview" description="Subscription value, invoices and payments for the organisations you can see."
               :breadcrumbs="[['label' => 'Billing']]">
    @if ($summary['unpriced_accounts'] > 0)
        <x-ui.alert tone="warning" class="mb-6">
            {{ $summary['unpriced_accounts'] }} billable mailbox(es) have no price configured and are not included in the monthly value.
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Monthly recurring revenue" :value="Money::format($summary['mrr_minor'])" icon="badge-indian-rupee" hint="Before tax, at today's prices" />
        <x-ui.stat-card label="Billable accounts" :value="number_format($summary['billable_accounts'])" icon="mail" />
        <x-ui.stat-card label="Suspended (still billable)" :value="number_format($summary['suspended_billable'])" icon="pause-circle" />
        <x-ui.stat-card label="Soft-deleted (not billed)" :value="number_format($summary['soft_deleted'])" icon="archive" />
        <x-ui.stat-card label="Total invoiced" :value="Money::format($summary['invoiced_minor'])" icon="receipt-text" :href="route('invoices.index')" hint="Invoices" />
        <x-ui.stat-card label="Payments received" :value="Money::format($summary['received_minor'])" icon="credit-card" />
        <x-ui.stat-card label="Outstanding" :value="Money::format($summary['outstanding_minor'])" icon="clock" :href="route('payments.outstanding')" hint="View" />
        <x-ui.stat-card label="Overdue" :value="Money::format($summary['overdue_minor'])" icon="triangle-alert" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Monthly value by organisation" :padding="false">
            @if (empty($summary['by_organisation']))
                <x-ui.empty-state title="No billable mailboxes yet" icon="building-2" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Organisation</th><th class="text-right">Mailboxes</th><th class="text-right">Monthly value</th></tr></thead>
                        <tbody>
                            @foreach ($summary['by_organisation'] as $row)
                                <tr>
                                    <td>{{ $row['organisation']->name }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($row['accounts']) }}</td>
                                    <td class="text-right tabular-nums">{{ Money::format($row['mrr_minor']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Billing history" description="Issued invoices by billing month." :padding="false">
            @if ($summary['history']->isEmpty())
                <x-ui.empty-state title="No invoices issued yet" icon="receipt-text" />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Month</th><th class="text-right">Invoices</th><th class="text-right">Invoiced</th><th class="text-right">Received</th></tr></thead>
                        <tbody>
                            @foreach ($summary['history'] as $row)
                                <tr>
                                    <td>{{ $row->period_start->format('F Y') }}</td>
                                    <td class="text-right tabular-nums">{{ $row->invoices }}</td>
                                    <td class="text-right tabular-nums">{{ Money::format((int) $row->total_minor) }}</td>
                                    <td class="text-right tabular-nums">{{ Money::format((int) $row->paid_minor) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
