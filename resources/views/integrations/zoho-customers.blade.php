@php
    // Organisations not linked to any Zoho customer yet come first; linked ones are marked.
    $options = $organisations
        ->sortBy(fn ($row) => [$row['zoho_id'] !== null, $row['organisation']->name])
        ->mapWithKeys(fn ($row) => [$row['organisation']->uuid => $row['organisation']->name.' ('.$row['organisation']->code.')'
            .($row['zoho_id'] !== null ? ' — already linked' : '')])
        ->all();
@endphp
<x-layouts.app title="Zoho customers"
               description="Customers that already exist in Zoho Books. Link one to an organisation so invoices go to it instead of a new duplicate customer, and copy its GST, PAN, address and contact details here."
               :breadcrumbs="[['label' => 'Zoho Books', 'url' => route('integrations.zoho')], ['label' => 'Customers']]">

    @if ($mode === 'mock')
        <x-ui.alert tone="warning" class="mb-4">Zoho Books is in mock mode: these are mock customers, not your Zoho Books data.</x-ui.alert>
    @endif

    @if ($error)
        <x-ui.alert tone="danger" class="mb-4" title="Zoho Books could not be read">{{ $error }}</x-ui.alert>
    @endif

    @error('contact')<x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @error('organisation')<x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('integrations.zoho.customers') }}" class="flex flex-wrap items-center gap-2">
                <x-ui.search-field placeholder="Search Zoho customer…" :value="$search" />
                <x-ui.button type="submit" variant="secondary" size="sm">Search</x-ui.button>
            </form>
            <span class="muted ml-auto text-xs">Read live from Zoho Books · page {{ $page }}</span>
        </x-slot:toolbar>

        <x-slot:head>
            <th>Zoho customer</th><th>Contact</th><th class="text-right">Receivable</th><th>Organisation here</th>
        </x-slot:head>

        @forelse ($contacts as $contact)
            <tr>
                <td class="align-top">
                    <div class="font-medium">{{ $contact['contact_name'] }}</div>
                    @if ($contact['company_name'] !== '' && $contact['company_name'] !== $contact['contact_name'])
                        <div class="muted text-xs">{{ $contact['company_name'] }}</div>
                    @endif
                    @if ($contact['status'] !== '' && $contact['status'] !== 'active')
                        <x-ui.badge tone="neutral" class="mt-1">{{ ucfirst($contact['status']) }}</x-ui.badge>
                    @endif
                </td>
                <td class="muted align-top text-sm">
                    {{ $contact['email'] ?? '—' }}
                    @if ($contact['phone'])<div class="text-xs">{{ $contact['phone'] }}</div>@endif
                </td>
                <td class="text-right align-top tabular-nums">
                    ₹{{ $contact['outstanding'] ?: '0.00' }}
                </td>
                <td class="align-top">
                    @if ($contact['organisation'])
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge tone="success">Linked</x-ui.badge>
                            <a href="{{ route('organisations.show', $contact['organisation']) }}" class="font-medium hover:underline">{{ $contact['organisation']->name }}</a>
                            <form method="POST" action="{{ route('integrations.zoho.customers.import', $contact['organisation']) }}">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="ghost" icon="refresh-cw">Update details from Zoho</x-ui.button>
                            </form>
                        </div>
                    @elseif ($options)
                        <form method="POST" action="{{ route('integrations.zoho.customers.link', $contact['contact_id']) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <x-ui.form.select name="organisation" id="organisation_{{ $contact['contact_id'] }}" :options="$options"
                                              placeholder="Choose organisation…" required class="w-64" aria-label="Organisation for {{ $contact['contact_name'] }}" />
                            <x-ui.button type="submit" size="sm">Link</x-ui.button>
                            <div class="basis-full">
                                <x-ui.form.checkbox name="import_details" :checked="true"
                                                    label="Copy GST, PAN, address and contact details from Zoho into the organisation" />
                            </div>
                        </form>
                    @else
                        <span class="muted text-sm">Create an organisation first.</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="!p-0">
                <x-ui.empty-state title="No customers found" icon="contact"
                                  :description="$search !== '' ? 'No Zoho Books customer matches your search.' : 'Zoho Books has no customers yet.'" />
            </td></tr>
        @endforelse

        @if ($page > 1 || $hasMore)
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    @if ($page > 1)
                        <x-ui.button size="sm" variant="secondary" :href="route('integrations.zoho.customers', array_filter(['page' => $page - 1, 'search' => $search]))">Previous</x-ui.button>
                    @else
                        <span></span>
                    @endif
                    @if ($hasMore)
                        <x-ui.button size="sm" variant="secondary" :href="route('integrations.zoho.customers', array_filter(['page' => $page + 1, 'search' => $search]))">Next</x-ui.button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-ui.table>

    <p class="muted mt-4 text-sm">
        A linked customer keeps its name and details in Zoho Books: this application never overwrites them. Organisations
        that are not linked get a new Zoho customer when their first invoice is sent.
    </p>
</x-layouts.app>
