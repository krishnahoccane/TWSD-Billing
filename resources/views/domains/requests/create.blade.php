<x-layouts.app title="Request new domain"
               description="Ask for a domain to be added to Google Workspace for your organisation."
               :breadcrumbs="[['label' => 'Domains', 'url' => route('domains.index')], ['label' => 'Request new domain']]">
    <form method="POST" action="{{ route('domains.requests.store') }}" class="max-w-2xl space-y-6">
        @csrf

        <x-ui.card title="Domain">
            <div class="space-y-4">
                @if (count($organisations) > 1)
                    <x-ui.form.field label="Organisation" for="organisation" required>
                        <x-ui.form.select name="organisation" :options="$organisations" :value="$selected" required />
                    </x-ui.form.field>
                @else
                    <input type="hidden" name="organisation" value="{{ array_key_first($organisations) }}">
                    <p class="text-sm"><span class="muted">Organisation:</span> <span class="font-medium">{{ reset($organisations) }}</span></p>
                @endif

                <x-ui.form.field label="Domain name" for="domain_name" required
                                 hint="The domain only, without www or https — for example example.com.">
                    <x-ui.form.input name="domain_name" required maxlength="253" placeholder="example.com" class="lowercase" autocomplete="off" />
                </x-ui.form.field>

                <x-ui.form.field label="Note for the reviewer" for="note" hint="Optional. For example who manages the DNS for this domain.">
                    <x-ui.form.textarea name="note" maxlength="500" />
                </x-ui.form.field>
            </div>
        </x-ui.card>

        <x-ui.card title="What happens next">
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                <li>A Super Admin reviews and approves or rejects the request.</li>
                <li>You receive a DNS TXT record to add at the company that manages the domain's DNS.</li>
                <li>Google checks that record to confirm your organisation controls the domain.</li>
                <li>The domain is added to Google Workspace and becomes available for new mailboxes.</li>
            </ol>
            <p class="muted mt-3 text-xs">Submitting a request does not prove ownership and does not change anything in Google.</p>
        </x-ui.card>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit">Submit request</x-ui.button>
            <x-ui.button variant="secondary" :href="route('domains.requests.index')">Cancel</x-ui.button>
        </div>
    </form>
</x-layouts.app>
