@php
    use Laravel\Fortify\Fortify;

    $messages = [
        Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED => 'Scan the QR code below and enter a code to finish setting up two-factor authentication.',
        Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED => 'Two-factor authentication is now active.',
        Fortify::TWO_FACTOR_AUTHENTICATION_DISABLED => 'Two-factor authentication has been turned off.',
        Fortify::RECOVERY_CODES_GENERATED => 'New recovery codes generated. Your previous codes no longer work.',
        Fortify::PASSWORD_UPDATED => 'Your password has been updated.',
    ];
@endphp
<x-layouts.app title="Security" description="Password and two-factor authentication for your account."
               :breadcrumbs="[['label' => 'Account'], ['label' => 'Security']]">
    <div class="max-w-2xl space-y-6">
        @if (session('warning'))
            <x-ui.alert tone="warning">{{ session('warning') }}</x-ui.alert>
        @endif

        @if (isset($messages[$status]))
            <x-ui.alert tone="{{ $status === Fortify::TWO_FACTOR_AUTHENTICATION_DISABLED ? 'warning' : 'success' }}">
                {{ $messages[$status] }}
            </x-ui.alert>
        @endif

        <x-ui.card title="Two-factor authentication"
                   description="A time-based one-time code from an authenticator app, required at every sign-in.">
            <x-slot:actions>
                @if ($user->hasTwoFactorEnabled())
                    <x-ui.badge tone="success">Enabled</x-ui.badge>
                @elseif ($pending)
                    <x-ui.badge tone="warning">Setup in progress</x-ui.badge>
                @else
                    <x-ui.badge tone="{{ $required ? 'danger' : 'neutral' }}">{{ $required ? 'Required' : 'Off' }}</x-ui.badge>
                @endif
            </x-slot:actions>

            @if ($recoveryCodes)
                <x-ui.alert tone="warning" title="Save your recovery codes now" class="mb-4">
                    Each code works once if you lose your authenticator. They will not be shown again.
                </x-ui.alert>
                <ul class="mb-4 grid grid-cols-1 gap-1 rounded-md bg-zinc-100 p-3 font-mono text-sm sm:grid-cols-2 dark:bg-zinc-800">
                    @foreach ($recoveryCodes as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($pending)
                <div class="flex flex-col gap-5 sm:flex-row">
                    <div class="shrink-0 self-start rounded-md bg-white p-2 ring-1 ring-zinc-200">{!! $qrCode !!}</div>

                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex-1 space-y-4">
                        @csrf
                        <p class="text-sm">Scan the QR code with Google Authenticator, Microsoft Authenticator, 1Password or a similar app, then enter the 6-digit code it shows.</p>
                        <x-ui.form.field label="Authentication code" for="code" bag="confirmTwoFactorAuthentication">
                            <x-ui.form.input name="code" bag="confirmTwoFactorAuthentication" inputmode="numeric"
                                             autocomplete="one-time-code" required autofocus class="max-w-40 tracking-widest" />
                        </x-ui.form.field>
                        <x-ui.button type="submit">Confirm and enable</x-ui.button>
                    </form>
                </div>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-4">
                    @csrf @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" size="sm">Cancel setup</x-ui.button>
                </form>
            @elseif ($user->hasTwoFactorEnabled())
                <p class="text-sm">Your account is protected. Confirmed {{ $user->two_factor_confirmed_at->diffForHumans() }}.</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('two-factor.recovery-codes') }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="refresh-cw">Regenerate recovery codes</x-ui.button>
                    </form>
                    <x-ui.button variant="ghost" @click="$dispatch('open-modal', 'disable-2fa')">Turn off</x-ui.button>
                </div>

                <x-ui.confirm-modal name="disable-2fa" title="Turn off two-factor authentication?"
                                    :action="route('two-factor.disable')" method="DELETE" confirm-label="Turn off" danger>
                    @if ($required)
                        Your role requires two-factor authentication. You will have to set it up again before you can continue using the application.
                    @else
                        Your account will be protected by your password only.
                    @endif
                </x-ui.confirm-modal>
            @else
                <p class="text-sm">
                    @if ($required)
                        Your role requires two-factor authentication. You cannot use the rest of the application until it is set up.
                    @else
                        Add a second step to sign-in to protect your account if your password is ever exposed.
                    @endif
                </p>
                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit" icon="shield-check">Set up two-factor authentication</x-ui.button>
                </form>
                <p class="muted mt-2 text-xs">You will be asked to confirm your password first.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Password">
            <form method="POST" action="{{ route('user-password.update') }}" class="space-y-4">
                @csrf @method('PUT')

                <x-ui.form.field label="Current password" for="current_password" bag="updatePassword">
                    <x-ui.form.input name="current_password" type="password" bag="updatePassword" required autocomplete="current-password" />
                </x-ui.form.field>

                <x-ui.form.field label="New password" for="new_password" name="password" bag="updatePassword"
                                 hint="At least 12 characters with upper and lower case letters, a number and a symbol.">
                    <x-ui.form.input name="password" id="new_password" type="password" bag="updatePassword" required autocomplete="new-password" />
                </x-ui.form.field>

                <x-ui.form.field label="Confirm new password" for="password_confirmation" bag="updatePassword">
                    <x-ui.form.input name="password_confirmation" type="password" bag="updatePassword" required autocomplete="new-password" />
                </x-ui.form.field>

                <x-ui.button type="submit">Update password</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
