<x-layouts.app :title="'Edit '.$account->primary_email"
               description="The address, domain and organisation of a mailbox cannot be changed here."
               :breadcrumbs="[
                   ['label' => 'Email accounts', 'url' => route('accounts.index')],
                   ['label' => $account->primary_email, 'url' => route('accounts.show', $account)],
                   ['label' => 'Edit'],
               ]">
    <form method="POST" action="{{ route('accounts.update', $account) }}" class="max-w-2xl space-y-6">
        @csrf @method('PUT')

        <x-ui.card title="Profile">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.form.field label="First name" for="first_name" required>
                    <x-ui.form.input name="first_name" :value="$account->first_name" required maxlength="60" />
                </x-ui.form.field>
                <x-ui.form.field label="Last name" for="last_name" required>
                    <x-ui.form.input name="last_name" :value="$account->last_name" required maxlength="60" />
                </x-ui.form.field>
                <x-ui.form.field label="Display name" for="display_name" class="sm:col-span-2">
                    <x-ui.form.input name="display_name" :value="$account->display_name" maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Recovery email" for="recovery_email">
                    <x-ui.form.input name="recovery_email" type="email" :value="$account->recovery_email" maxlength="255" />
                </x-ui.form.field>
                <x-ui.form.field label="Recovery phone" for="recovery_phone" hint="International format, e.g. +919876543210.">
                    <x-ui.form.input name="recovery_phone" type="tel" :value="$account->recovery_phone" maxlength="16" />
                </x-ui.form.field>
                @if (auth()->user()->hasGlobalAccess())
                    <x-ui.form.field label="Organisational unit" for="organisational_unit_path" class="sm:col-span-2"
                                     hint="Google Workspace organisational unit path. Platform administrators only.">
                        @if ($orgUnits)
                            <x-ui.form.select name="organisational_unit_path" :value="$account->organisational_unit_path"
                                              :options="['/' => '/'] + $orgUnits" />
                        @else
                            <x-ui.form.input name="organisational_unit_path" :value="$account->organisational_unit_path" maxlength="500" class="font-mono" />
                        @endif
                    </x-ui.form.field>
                @endif
            </div>
        </x-ui.card>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit">Save changes</x-ui.button>
            <x-ui.button variant="secondary" :href="route('accounts.show', $account)">Cancel</x-ui.button>
        </div>
    </form>
</x-layouts.app>
