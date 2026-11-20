<x-layouts.app title="Add license" description="For a sale made outside the storefront (bank transfer, partner, replacement). Storefront sales are added automatically."
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Licenses', 'url' => route('licensing.index')], ['label' => 'Add']]">
    @if ($plans === [])
        <x-ui.alert tone="warning" title="No plans yet">Create a product and its plans under Products &amp; Plans first.</x-ui.alert>
    @else
        <x-ui.card class="max-w-2xl">
            <form method="POST" action="{{ route('licensing.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.form.field label="Customer (organisation)" for="organisation" class="sm:col-span-2" required>
                    <x-ui.form.select name="organisation" :options="$organisations" placeholder="Choose…" required />
                </x-ui.form.field>
                <x-ui.form.field label="Plan" for="plan" class="sm:col-span-2" required>
                    <x-ui.form.select name="plan" :options="$plans" placeholder="Choose…" required />
                </x-ui.form.field>
                <x-ui.form.field label="Billing type" for="billing_type" required hint="Annual: expires after a year. Lifetime: never expires, 12 months of updates.">
                    <x-ui.form.select name="billing_type" :options="['annual' => 'Annual', 'lifetime' => 'Lifetime']" required />
                </x-ui.form.field>
                <x-ui.form.field label="Extra domains" for="domains_extra">
                    <x-ui.form.input type="number" name="domains_extra" value="0" min="0" max="100" />
                </x-ui.form.field>
                <x-ui.form.field label="Currency" for="currency" required>
                    <x-ui.form.input name="currency" value="USD" maxlength="3" class="font-mono uppercase" required />
                </x-ui.form.field>
                <x-ui.form.field label="Price paid (optional)" for="price_paid" hint="For the record only; invoicing happens on the storefront.">
                    <x-ui.form.input name="price_paid" inputmode="decimal" placeholder="1299.00" />
                </x-ui.form.field>
                <x-ui.form.field label="Reason" for="reason" class="sm:col-span-2" required>
                    <x-ui.form.textarea name="reason" required minlength="3" maxlength="500" />
                </x-ui.form.field>
                <div class="sm:col-span-2"><x-ui.button type="submit" icon="key-round">Create license</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif
</x-layouts.app>
