{{-- Shared by create and edit. $organisation is a new or existing model. --}}
@php($editing = $organisation->exists)
<form method="POST" action="{{ $editing ? route('organisations.update', $organisation) : route('organisations.store') }}" class="max-w-4xl space-y-6">
    @csrf
    @if ($editing) @method('PUT') @endif

    <x-ui.card title="Identity">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.field label="Organisation name" for="name" required>
                <x-ui.form.input name="name" :value="$organisation->name" required maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Legal business name" for="legal_name">
                <x-ui.form.input name="legal_name" :value="$organisation->legal_name" maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Organisation code" for="code" :required="! $editing"
                             :hint="$editing ? 'The code cannot be changed after creation.' : 'Short unique reference, e.g. ACME. Cannot be changed later.'">
                <x-ui.form.input name="code" :value="$organisation->code" maxlength="32" class="font-mono uppercase"
                                 :disabled="$editing" :required="! $editing" />
            </x-ui.form.field>
            <div class="grid grid-cols-2 gap-4">
                <x-ui.form.field label="PAN" for="pan">
                    <x-ui.form.input name="pan" :value="$organisation->pan" maxlength="10" class="font-mono uppercase" />
                </x-ui.form.field>
                <x-ui.form.field label="GSTIN" for="gstin">
                    <x-ui.form.input name="gstin" :value="$organisation->gstin" maxlength="15" class="font-mono uppercase" />
                </x-ui.form.field>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card title="Contact">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.field label="Primary contact person" for="primary_contact_name">
                <x-ui.form.input name="primary_contact_name" :value="$organisation->primary_contact_name" maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Primary contact email" for="primary_contact_email">
                <x-ui.form.input name="primary_contact_email" type="email" :value="$organisation->primary_contact_email" maxlength="255" />
            </x-ui.form.field>
            <x-ui.form.field label="Contact phone" for="contact_phone">
                <x-ui.form.input name="contact_phone" type="tel" :value="$organisation->contact_phone" maxlength="32" />
            </x-ui.form.field>
            <x-ui.form.field label="Billing email" for="billing_email" hint="Invoices and payment notices are sent here.">
                <x-ui.form.input name="billing_email" type="email" :value="$organisation->billing_email" maxlength="255" />
            </x-ui.form.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Address">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.field label="Registered address" for="registered_address">
                <x-ui.form.textarea name="registered_address" :value="$organisation->registered_address" maxlength="1000" />
            </x-ui.form.field>
            <x-ui.form.field label="Billing address" for="billing_address">
                <x-ui.form.textarea name="billing_address" :value="$organisation->billing_address" maxlength="1000" />
            </x-ui.form.field>
            <x-ui.form.field label="City" for="city">
                <x-ui.form.input name="city" :value="$organisation->city" maxlength="100" />
            </x-ui.form.field>
            <x-ui.form.field label="State" for="state">
                <x-ui.form.input name="state" :value="$organisation->state" maxlength="100" />
            </x-ui.form.field>
            <x-ui.form.field label="Postal code" for="postal_code">
                <x-ui.form.input name="postal_code" :value="$organisation->postal_code" maxlength="16" />
            </x-ui.form.field>
            <x-ui.form.field label="Country" for="country" hint="Two-letter ISO code." required>
                <x-ui.form.input name="country" :value="$organisation->country ?? 'IN'" maxlength="2" class="max-w-24 font-mono uppercase" required />
            </x-ui.form.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Internal notes" description="Visible to platform staff only.">
        <x-ui.form.field for="notes">
            <x-ui.form.textarea name="notes" :value="$organisation->notes" rows="4" maxlength="2000" />
        </x-ui.form.field>
    </x-ui.card>

    <div class="flex flex-wrap gap-2">
        <x-ui.button type="submit">{{ $editing ? 'Save changes' : 'Create organisation' }}</x-ui.button>
        <x-ui.button variant="secondary" :href="$editing ? route('organisations.show', $organisation) : route('organisations.index')">Cancel</x-ui.button>
    </div>
</form>
