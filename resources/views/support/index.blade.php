@php use App\Modules\Support\Models\SupportRequest; @endphp
<x-layouts.app title="Support" description="Ask the infrastructure team for help."
               :breadcrumbs="[['label' => 'Support']]">
    @if ($organisations)
        <x-slot:actions>
            <x-ui.button icon="plus" @click="$dispatch('open-modal', 'new-support')">New request</x-ui.button>
        </x-slot:actions>
    @endif

    <x-ui.table>
        <x-slot:toolbar>
            <form method="GET" action="{{ route('support.index') }}">
                <x-ui.filter-select label="Status" name="status" :selected="$status" :options="SupportRequest::STATUSES" x-data @change="$el.form.submit()" />
            </form>
        </x-slot:toolbar>
        <x-slot:head>
            <th>Subject</th>
            @if ($showOrganisation)<th>Organisation</th>@endif
            <th>Category</th><th>Status</th><th>Raised by</th><th>When</th>
        </x-slot:head>

        @forelse ($requests as $item)
            <tr>
                <td><a href="{{ route('support.show', $item->uuid) }}" class="font-medium hover:underline">{{ $item->subject }}</a></td>
                @if ($showOrganisation)<td>{{ $item->organisation?->name ?? '—' }}</td>@endif
                <td>{{ SupportRequest::CATEGORIES[$item->category] ?? $item->category }}</td>
                <td><x-ui.badge :tone="$item->tone()">{{ SupportRequest::STATUSES[$item->status] ?? $item->status }}</x-ui.badge></td>
                <td>{{ $item->user?->name ?? '—' }}</td>
                <td class="muted whitespace-nowrap">{{ $item->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="!p-0"><x-ui.empty-state title="No support requests" icon="life-buoy" /></td></tr>
        @endforelse

        @if ($requests->hasPages())
            <x-slot:footer>{{ $requests->links() }}</x-slot:footer>
        @endif
    </x-ui.table>

    @if ($organisations)
        <x-ui.modal name="new-support" title="New support request" max-width="max-w-lg">
            <form method="POST" action="{{ route('support.store') }}" class="space-y-4">
                @csrf
                @if (count($organisations) > 1)
                    <x-ui.form.field label="Organisation" for="support_org" name="organisation" bag="support" required>
                        <x-ui.form.select name="organisation" id="support_org" bag="support" :options="$organisations" :value="$selected" />
                    </x-ui.form.field>
                @else
                    <input type="hidden" name="organisation" value="{{ $selected }}">
                @endif
                <x-ui.form.field label="Topic" for="support_category" name="category" bag="support" required>
                    <x-ui.form.select name="category" id="support_category" bag="support" :options="SupportRequest::CATEGORIES" />
                </x-ui.form.field>
                <x-ui.form.field label="Subject" for="support_subject" name="subject" bag="support" required>
                    <x-ui.form.input name="subject" id="support_subject" bag="support" maxlength="200" required />
                </x-ui.form.field>
                <x-ui.form.field label="Message" for="support_message" name="message" bag="support" required hint="Never include passwords.">
                    <x-ui.form.textarea name="message" id="support_message" bag="support" rows="6" maxlength="5000" required />
                </x-ui.form.field>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-support')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Send</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
        @if ($errors->getBag('support')->any())
            <div x-data x-init="$nextTick(() => $dispatch('open-modal', 'new-support'))"></div>
        @endif
    @endif
</x-layouts.app>
