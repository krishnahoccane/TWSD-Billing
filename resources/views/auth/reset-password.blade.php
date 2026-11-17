<x-layouts.guest title="Choose a new password" heading="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.form.field label="Email" for="email">
            <x-ui.form.input name="email" type="email" :value="$request->email" required autocomplete="username" />
        </x-ui.form.field>

        <x-ui.form.field label="New password" for="password"
                         hint="At least 12 characters with upper and lower case letters, a number and a symbol.">
            <x-ui.form.input name="password" type="password" required autofocus autocomplete="new-password" />
        </x-ui.form.field>

        <x-ui.form.field label="Confirm new password" for="password_confirmation">
            <x-ui.form.input name="password_confirmation" type="password" required autocomplete="new-password" />
        </x-ui.form.field>

        <x-ui.button type="submit" class="w-full">Reset password</x-ui.button>
    </form>
</x-layouts.guest>
