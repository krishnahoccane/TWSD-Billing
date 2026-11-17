<x-layouts.guest title="Two-factor authentication" heading="Two-factor authentication">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <p class="muted mb-4 text-sm" x-show="!recovery">Enter the 6-digit code from your authenticator app.</p>
        <p class="muted mb-4 text-sm" x-cloak x-show="recovery">Enter one of your emergency recovery codes.</p>

        <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
            @csrf

            <div x-show="!recovery">
                <x-ui.form.field label="Authentication code" for="code">
                    <x-ui.form.input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                                     x-bind:disabled="recovery" class="tracking-widest" />
                </x-ui.form.field>
            </div>

            <div x-cloak x-show="recovery">
                <x-ui.form.field label="Recovery code" for="recovery_code">
                    <x-ui.form.input name="recovery_code" type="password" autocomplete="off" x-bind:disabled="!recovery" />
                </x-ui.form.field>
            </div>

            <x-ui.button type="submit" class="w-full">Verify</x-ui.button>
        </form>

        <button type="button" class="mt-4 text-sm text-accent-600 hover:underline dark:text-accent-400"
                @click="recovery = !recovery"
                x-text="recovery ? 'Use an authentication code' : 'Use a recovery code'"></button>
    </div>

    <x-slot:footer><a href="{{ route('login') }}" class="hover:underline">Back to sign in</a></x-slot:footer>
</x-layouts.guest>
