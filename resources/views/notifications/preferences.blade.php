<x-layouts.app title="Notification settings" description="Choose which notifications also arrive by e-mail. Every notification always appears in the app."
               :breadcrumbs="[['label' => 'Account'], ['label' => 'Notifications']]">
    <form method="POST" action="{{ route('account.notifications.save') }}" class="max-w-2xl space-y-4">
        @csrf
        <x-ui.card :padding="false">
            <table class="data-table">
                <thead><tr><th>Notification</th><th class="text-right">E-mail</th></tr></thead>
                <tbody>
                    @foreach ($types as $type)
                        <tr>
                            <td>{{ $type->label() }}</td>
                            <td class="text-right">
                                <input type="checkbox" name="mail[]" value="{{ $type->value }}" aria-label="E-mail for {{ $type->label() }}"
                                       class="size-4 accent-accent-600"
                                       @checked($preferences[$type->value]['mail'] ?? $type->mailByDefault())>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
        <x-ui.button type="submit">Save</x-ui.button>
    </form>
</x-layouts.app>
