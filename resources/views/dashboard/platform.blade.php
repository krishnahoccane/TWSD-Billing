@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app title="Dashboard" description="Platform overview of the organisations you can see. Choose an organisation in the header to see its own dashboard."
               :breadcrumbs="[['label' => 'Dashboard']]">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Organisations" :value="number_format($stats['organisations'])" icon="building-2"
                        :hint="$stats['active_organisations'].' active · '.$stats['suspended_organisations'].' suspended'" />
        @if ($stats['accounts'] !== null)
            <x-ui.stat-card label="Google accounts" :value="number_format($stats['accounts'])" icon="mail"
                            :hint="$stats['active_accounts'].' active · '.$stats['suspended_accounts'].' suspended'" />
        @endif
        @if ($stats['unassigned_accounts'] !== null)
            <x-ui.stat-card label="Unassigned accounts" :value="number_format($stats['unassigned_accounts'])" icon="circle-help" :href="route('accounts.unassigned')" hint="Review" />
        @endif
        @if ($stats['domains'] !== null)
            <x-ui.stat-card label="Domains" :value="number_format($stats['domains'])" icon="globe"
                            :hint="$stats['pending_domain_requests'].' request(s) awaiting approval'" />
        @endif
        @if ($stats['mrr_minor'] !== null)
            <x-ui.stat-card label="Monthly recurring revenue" :value="Money::format($stats['mrr_minor'])" icon="badge-indian-rupee" hint="Before GST" />
            <x-ui.stat-card label="Invoiced this month" :value="Money::format($stats['month_invoiced_minor'])" icon="receipt-text" :hint="$stats['month_invoices'].' invoice(s)'" />
            <x-ui.stat-card label="Received this month" :value="Money::format($stats['month_received_minor'])" icon="credit-card" />
            <x-ui.stat-card label="Outstanding" :value="Money::format($stats['outstanding_minor'])" icon="clock" :href="route('payments.outstanding')" hint="View" />
        @endif
        @if ($stats['failed_google_jobs'] !== null)
            <x-ui.stat-card label="Failed Google jobs" :value="number_format($stats['failed_google_jobs'])" icon="triangle-alert" :href="route('system.queues')" hint="Queues" />
        @endif
        @if ($stats['failed_zoho_syncs'] !== null)
            <x-ui.stat-card label="Zoho records needing attention" :value="number_format($stats['failed_zoho_syncs'])" icon="book-open" :href="route('integrations.zoho')" hint="Zoho Books" />
        @endif
        @if ($stats['users'] !== null)
            <x-ui.stat-card label="Dashboard users" :value="number_format($stats['users'])" icon="users" />
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        @if ($charts['revenue'] !== null)
            <x-ui.card title="Monthly revenue" description="Invoiced, by billing month, last 12 months."><x-ui.bar-chart :data="$charts['revenue']" format="money" /></x-ui.card>
            <x-ui.card title="Payment collection" description="Payments received, by month."><x-ui.bar-chart :data="$charts['collections']" format="money" /></x-ui.card>
        @endif
        @if ($charts['growth'] !== null)
            <x-ui.card title="Account growth" description="Mailboxes in the system at the end of each month."><x-ui.bar-chart :data="$charts['growth']" /></x-ui.card>
            <x-ui.card title="Mailboxes by organisation" description="Top 10."><x-ui.bar-chart :data="$charts['byOrganisation']" /></x-ui.card>
            <x-ui.card title="Account lifecycle"><x-ui.bar-chart :data="$charts['lifecycle']" /></x-ui.card>
        @endif

        @if ($recentActivity->isNotEmpty())
            <x-ui.card title="Recent activity" :padding="false">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Action</th><th>User</th><th>Status</th><th>When</th></tr></thead>
                        <tbody>
                            @foreach ($recentActivity as $entry)
                                <tr>
                                    <td class="font-mono text-xs">{{ $entry->action }}</td>
                                    <td class="max-w-48 truncate">{{ $entry->user_email ?? 'System' }}</td>
                                    <td><x-ui.badge :tone="$entry->status === 'success' ? 'success' : ($entry->status === 'denied' ? 'warning' : 'danger')">{{ ucfirst($entry->status) }}</x-ui.badge></td>
                                    <td class="muted whitespace-nowrap">{{ $entry->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-slot:footer><x-ui.button variant="ghost" size="sm" :href="route('audit.index')">Audit log</x-ui.button></x-slot:footer>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
