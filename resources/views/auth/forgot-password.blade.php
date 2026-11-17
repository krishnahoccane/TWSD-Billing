<x-layouts.guest title="Reset password" heading="Reset your password"
                 subheading="Enter your email and we will send a reset link if the account exists.">
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.form.field label="Email" for="email">
            <x-ui.form.input name="email" type="email" required autofocus autocomplete="username" />
        </x-ui.form.field>

        <x-ui.button type="submit" class="w-full">Send reset link</x-ui.button>
    </form>

    <x-slot:footer><a href="{{ route('login') }}" class="hover:underline">Back to sign in</a></x-slot:footer>
</x-layouts.guest>
