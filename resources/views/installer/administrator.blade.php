<x-layouts.installer :step="$step" heading="Platform Super Admin"
    :subheading="$existing ? 'This database already has users, so no new administrator is created. Confirm you are one of its Super Admins.' : 'The first account. It has full access and must set up two-factor authentication at first sign-in.'">
    <form method="POST" action="{{ route('install.administrator') }}" class="space-y-4">
        @csrf

        @if ($existing)
            <x-ui.alert tone="warning" title="Existing installation found">
                {{ $info['version'] }} · {{ $info['tables'] }} tables · {{ $info['users'] }} {{ Str::plural('user', $info['users']) }}.
                Its data will be kept; only missing tables are added.
            </x-ui.alert>

            <x-ui.form.field label="Super Admin e-mail" for="email" required>
                <x-ui.form.input name="email" type="email" :value="$admin['email'] ?? ''" required autocomplete="username" />
            </x-ui.form.field>
            <x-ui.form.field label="Password" for="password" required>
                <x-ui.form.input name="password" type="password" required autocomplete="current-password" />
            </x-ui.form.field>
            <x-ui.form.field label="Previous APP_KEY (optional)" for="app_key"
                hint="If you still have the APP_KEY from the earlier installation, enter it so two-factor secrets and integration tokens stored in this database stay readable. Leave blank to generate a new key.">
                <x-ui.form.input name="app_key" autocomplete="off" placeholder="base64:…" />
            </x-ui.form.field>
        @else
            <x-ui.form.field label="Display name" for="name" required>
                <x-ui.form.input name="name" :value="$admin['name'] ?? ''" required maxlength="255" autocomplete="name" />
            </x-ui.form.field>
            <x-ui.form.field label="E-mail" for="email" required hint="Used to sign in and to receive notifications.">
                <x-ui.form.input name="email" type="email" :value="$admin['email'] ?? ''" required autocomplete="username" />
            </x-ui.form.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.field label="Password" for="password" required hint="At least 12 characters with upper and lower case, a number and a symbol.">
                    <x-ui.form.input name="password" type="password" required autocomplete="new-password" />
                </x-ui.form.field>
                <x-ui.form.field label="Confirm password" for="password_confirmation" required>
                    <x-ui.form.input name="password_confirmation" type="password" required autocomplete="new-password" />
                </x-ui.form.field>
            </div>
        @endif

        <div class="flex justify-between gap-2">
            <x-ui.button variant="secondary" :href="route('install.database')">Back</x-ui.button>
            <x-ui.button type="submit">Continue</x-ui.button>
        </div>
    </form>
</x-layouts.installer>
