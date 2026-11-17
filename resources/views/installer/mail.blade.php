<x-layouts.installer :step="$step" heading="E-mail (SMTP)"
    subheading="Used for password resets and notifications. Send a test e-mail before installing.">
    <form method="POST" action="{{ route('install.mail') }}" class="space-y-4"
          x-data="{ skip: @js((bool) old('skip_mail')), busy: false }" @submit="busy = true">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2" x-show="! skip">
            <x-ui.form.field label="SMTP host" for="host" required>
                <x-ui.form.input name="host" :value="$mail['host'] ?? ''" placeholder="smtp.example.com" autocomplete="off" />
            </x-ui.form.field>
            <div class="grid grid-cols-2 gap-4">
                <x-ui.form.field label="Port" for="port" required>
                    <x-ui.form.input name="port" type="number" :value="$mail['port'] ?? 587" min="1" max="65535" />
                </x-ui.form.field>
                <x-ui.form.field label="Encryption" for="encryption" required>
                    <x-ui.form.select name="encryption" :value="$mail['encryption'] ?? 'tls'"
                        :options="['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None']" />
                </x-ui.form.field>
            </div>
            <x-ui.form.field label="Username" for="username">
                <x-ui.form.input name="username" :value="$mail['username'] ?? ''" autocomplete="off" />
            </x-ui.form.field>
            <x-ui.form.field label="Password" for="password"
                :hint="filled($mail['password'] ?? null) ? 'Leave blank to keep the password entered before.' : null">
                <x-ui.form.input name="password" type="password" autocomplete="new-password" />
            </x-ui.form.field>
            <x-ui.form.field label="From address" for="from_address" required>
                <x-ui.form.input name="from_address" type="email" :value="$mail['from_address'] ?? ''" />
            </x-ui.form.field>
            <x-ui.form.field label="From name" for="from_name" required>
                <x-ui.form.input name="from_name" :value="$mail['from_name'] ?? ''" maxlength="100" />
            </x-ui.form.field>
        </div>

        <x-ui.form.checkbox name="skip_mail" label="Set up e-mail later (messages are written to the log file until then)" x-model="skip" />

        <x-ui.alert tone="info">
            Installing writes a production <code>.env</code> (debug off, two-factor enforced, secure cookies on HTTPS),
            prepares the database and creates the Super Admin. After that this installer is switched off.
        </x-ui.alert>

        <div class="flex flex-wrap justify-between gap-2">
            <x-ui.button variant="secondary" :href="route('install.administrator')">Back</x-ui.button>
            <div class="flex gap-2">
                <x-ui.button type="submit" name="action" value="test" variant="secondary" icon="send" x-show="! skip">
                    Send test e-mail to {{ $adminEmail }}
                </x-ui.button>
                <x-ui.button type="submit" name="action" value="install">
                    <span x-show="! busy">Install</span>
                    <span x-show="busy" x-cloak>Working…</span>
                </x-ui.button>
            </div>
        </div>
    </form>
</x-layouts.installer>
