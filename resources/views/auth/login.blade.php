<x-layouts.guest title="Sign in" heading="Sign in" subheading="Use your Workspace Control Center account.">
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.form.field label="Email" for="email">
            <x-ui.form.input name="email" type="email" required autofocus autocomplete="username" />
        </x-ui.form.field>

        <x-ui.form.field label="Password" for="password">
            <x-ui.form.input name="password" type="password" required autocomplete="current-password" />
        </x-ui.form.field>

        <div class="flex items-center justify-between">
            <x-ui.form.checkbox name="remember" label="Remember me" />
            <a href="{{ route('password.request') }}" class="text-sm text-accent-600 hover:underline dark:text-accent-400">
                Forgot password?
            </a>
        </div>

        <x-ui.button type="submit" class="w-full">Sign in</x-ui.button>
    </form>

    <x-slot:footer>Accounts are created by your administrator.</x-slot:footer>
</x-layouts.guest>
