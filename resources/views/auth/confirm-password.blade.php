<x-layouts.guest title="Confirm password" heading="Confirm your password"
                 subheading="This is a protected area. Re-enter your password to continue.">
    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
        @csrf

        <x-ui.form.field label="Password" for="password">
            <x-ui.form.input name="password" type="password" required autofocus autocomplete="current-password" />
        </x-ui.form.field>

        <x-ui.button type="submit" class="w-full">Confirm</x-ui.button>
    </form>

    <x-slot:footer><a href="{{ route('account.security') }}" class="hover:underline">Cancel</a></x-slot:footer>
</x-layouts.guest>
