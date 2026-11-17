@php
    // Display data for the form only. The server re-resolves the organisation
    // and domain inside the user's own scope when the form is submitted.
    $options = $organisations->map(fn ($organisation) => [
        'uuid' => $organisation->uuid,
        'name' => $organisation->name,
        'domains' => $organisation->domains->map(fn ($domain) => ['uuid' => $domain->uuid, 'name' => $domain->domain_name])->values(),
    ])->values();
@endphp
<x-layouts.app title="Create email account"
               description="The mailbox is created in Google Workspace in the background and becomes billable once it is active."
               :breadcrumbs="[['label' => 'Email accounts', 'url' => route('accounts.index')], ['label' => 'Create']]">
    <form method="POST" action="{{ route('accounts.store') }}" class="max-w-2xl space-y-6"
          x-data="{
              organisations: @js($options),
              organisation: @js(old('organisation', $selected)),
              domain: @js(old('domain', '')),
              get domains() { return (this.organisations.find(o => o.uuid === this.organisation) || { domains: [] }).domains },
              get domainName() { return (this.domains.find(d => d.uuid === this.domain) || { name: '' }).name },
              init() { if (! this.domains.some(d => d.uuid === this.domain)) this.domain = this.domains[0]?.uuid ?? '' },
          }">
        @csrf

        <x-ui.card title="Mailbox">
            <div class="space-y-4">
                @if ($organisations->count() > 1)
                    <x-ui.form.field label="Organisation" for="organisation" required>
                        <select name="organisation" id="organisation" class="control" x-model="organisation"
                                @change="domain = domains[0]?.uuid ?? ''" required>
                            <template x-for="option in organisations" :key="option.uuid">
                                <option :value="option.uuid" x-text="option.name" :selected="option.uuid === organisation"></option>
                            </template>
                        </select>
                    </x-ui.form.field>
                @else
                    <input type="hidden" name="organisation" value="{{ $organisations->first()->uuid }}">
                    <p class="text-sm"><span class="muted">Organisation:</span> <span class="font-medium">{{ $organisations->first()->name }}</span></p>
                @endif

                <div x-show="domains.length === 0" x-cloak>
                    <x-ui.alert tone="warning">
                        This organisation has no active domain yet. A Super Admin must map a domain before mailboxes can be created.
                    </x-ui.alert>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" x-show="domains.length > 0">
                    <x-ui.form.field label="Username" for="username" required>
                        <x-ui.form.input name="username" required maxlength="64" autocomplete="off" class="lowercase" placeholder="first.last" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Domain" for="domain" required>
                        <select name="domain" id="domain" class="control" x-model="domain" required>
                            <template x-for="option in domains" :key="option.uuid">
                                <option :value="option.uuid" x-text="'@' + option.name" :selected="option.uuid === domain"></option>
                            </template>
                        </select>
                    </x-ui.form.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.field label="First name" for="first_name" required>
                        <x-ui.form.input name="first_name" required maxlength="60" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Last name" for="last_name" required>
                        <x-ui.form.input name="last_name" required maxlength="60" />
                    </x-ui.form.field>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Account recovery" description="Optional. Used by Google to help the person regain access.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.form.field label="Recovery email" for="recovery_email">
                    <x-ui.form.input name="recovery_email" type="email" maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Recovery phone" for="recovery_phone" hint="International format, e.g. +919876543210.">
                    <x-ui.form.input name="recovery_phone" type="tel" maxlength="16" />
                </x-ui.form.field>
            </div>
        </x-ui.card>

        <x-ui.alert tone="info">
            A secure initial password is generated automatically. You can reveal it once, for 24 hours, on the account page
            after creation. The person must change it at first sign-in.
        </x-ui.alert>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" x-bind:disabled="domains.length === 0">Create email account</x-ui.button>
            <x-ui.button variant="secondary" :href="route('accounts.index')">Cancel</x-ui.button>
        </div>
    </form>
</x-layouts.app>
