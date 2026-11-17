@php use App\Modules\Billing\Support\Money; @endphp
<x-layouts.app :title="$organisation?->name ?? 'Dashboard'"
               :description="$organisation ? 'Organisation code '.$organisation->code : null"
               :breadcrumbs="[['label' => 'Dashboard']]">
    @if ($organisation === null)
        <x-ui.card>
            <x-ui.empty-state title="No organisation assigned" icon="building-2"
                              description="Your account is not assigned to an organisation yet. Ask your administrator to grant you access." />
        </x-ui.card>
    @else
        @if ($organisation->status !== \App\Modules\Organisations\Enums\OrganisationStatus::Active)
            <x-ui.alert tone="warning" class="mb-6">This organisation is currently {{ strtolower($organisation->status->label()) }}.</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @if ($data['accounts'])
                <x-ui.stat-card label="Email accounts" :value="number_format($data['accounts']['total'])" icon="mail" :href="route('accounts.index')" hint="View all" />
                <x-ui.stat-card label="Active" :value="number_format($data['accounts']['active'])" icon="circle-check" />
                <x-ui.stat-card label="Suspended (still billed)" :value="number_format($data['accounts']['suspended'])" icon="pause-circle" />
                <x-ui.stat-card label="Being set up / changed" :value="number_format($data['accounts']['pending'])" icon="loader-circle" />
            @endif
            @if ($data['domains'])
                <x-ui.stat-card label="Domains" :value="number_format($data['domains']['active'])" icon="globe" :href="route('domains.index')" hint="My domains" />
            @endif
            @if ($data['billing'])
                <x-ui.stat-card label="Monthly subscription" :value="Money::format($data['billing']['subscription_minor'])" icon="badge-indian-rupee" hint="Before GST, at today's prices" />
                <x-ui.stat-card label="Outstanding" :value="Money::format($data['billing']['outstanding_minor'])" icon="clock"
                                :hint="$data['billing']['overdue_minor'] > 0 ? Money::format($data['billing']['overdue_minor']).' overdue' : 'Nothing overdue'" />
                <x-ui.stat-card label="Last payment" :value="$data['billing']['last_payment'] ? Money::format($data['billing']['last_payment']->amount_minor) : '—'" icon="credit-card"
                                :hint="$data['billing']['last_payment']?->paid_on->format('d M Y')" />
            @endif
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @if ($data['billing'])
                <x-ui.card title="Current billing" :description="'Billing period '.$data['billing']['period'].'. Invoiced on the 1st of next month.'">
                    @if ($current = $data['billing']['current'])
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm">Latest invoice <a href="{{ route('invoices.show', $current) }}" class="font-medium hover:underline">{{ $current->invoice_number }}</a>
                                    · {{ $current->period_start->format('F Y') }}</p>
                                <p class="muted text-xs">Total {{ Money::format($current->total_minor) }} · balance {{ Money::format($current->balance_minor) }} · due {{ $current->due_date?->format('d M Y') }}</p>
                            </div>
                            <x-ui.badge :tone="$current->statusTone()">{{ ucfirst(str_replace('_', ' ', $current->displayStatus())) }}</x-ui.badge>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($data['billing']['pay_url'])
                                <x-ui.button :href="$data['billing']['pay_url']" target="_blank" rel="noopener noreferrer" icon="credit-card">Pay {{ Money::format($current->balance_minor) }}</x-ui.button>
                            @endif
                            <x-ui.button variant="secondary" :href="route('invoices.show', $current)">View invoice</x-ui.button>
                        </div>
                    @else
                        <p class="muted text-sm">No invoices yet.</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="Invoices and payments" :padding="false">
                    <table class="data-table">
                        <thead><tr><th>Invoice</th><th>Period</th><th>Status</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                            @forelse ($data['billing']['invoices'] as $invoice)
                                <tr>
                                    <td><a href="{{ route('invoices.show', $invoice) }}" class="hover:underline">{{ $invoice->invoice_number }}</a></td>
                                    <td>{{ $invoice->period_start->format('M Y') }}</td>
                                    <td><x-ui.badge :tone="$invoice->statusTone()">{{ ucfirst(str_replace('_', ' ', $invoice->displayStatus())) }}</x-ui.badge></td>
                                    <td class="text-right tabular-nums">{{ Money::format($invoice->total_minor) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">No invoices yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer><x-ui.button variant="ghost" size="sm" :href="route('payments.history')">Payment history</x-ui.button></x-slot:footer>
                </x-ui.card>
            @endif

            @if ($data['accounts'])
                <x-ui.card title="Recent account activity" :padding="false">
                    @if ($data['accounts']['recent']->isEmpty())
                        <x-ui.empty-state title="No activity yet" icon="history" />
                    @else
                        <table class="data-table">
                            <tbody>
                                @foreach ($data['accounts']['recent'] as $row)
                                    <tr>
                                        <td>@if ($row['account'])<a href="{{ route('accounts.show', $row['account']) }}" class="hover:underline">{{ $row['account']->primary_email }}</a>@else — @endif</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $row['entry']->event)) }}</td>
                                        <td class="muted whitespace-nowrap">{{ $row['entry']->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-ui.card>
            @endif

            @if ($data['domains'])
                <x-ui.card title="Domain requests in progress" :padding="false">
                    @if ($data['domains']['pending_requests']->isEmpty())
                        <x-ui.empty-state title="None in progress" icon="globe" />
                    @else
                        <table class="data-table">
                            <tbody>
                                @foreach ($data['domains']['pending_requests'] as $request)
                                    <tr>
                                        <td><a href="{{ route('domains.requests.show', $request) }}" class="hover:underline">{{ $request->domain_name }}</a></td>
                                        <td><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-ui.card>
            @endif

            @if ($memberships->count() > 1)
                <x-ui.card title="Your organisations" :padding="false">
                    <table class="data-table">
                        <tbody>
                            @foreach ($memberships as $membership)
                                <tr>
                                    <td class="font-medium">{{ $membership['organisation']->name }}</td>
                                    <td>{{ implode(', ', $membership['roles']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <x-slot:footer><p class="muted text-xs">Switch organisation with the selector at the top of the page.</p></x-slot:footer>
                </x-ui.card>
            @endif
        </div>
    @endif
</x-layouts.app>
