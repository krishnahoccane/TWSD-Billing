@php
    use App\Modules\Notifications\Enums\NotificationType;
    $mailTypes = collect($types)->map(fn ($type) => $type->value)->all();
    $rows = collect(NotificationType::cases())->filter(fn ($type) => in_array($type->value, $mailTypes, true) || in_array($type->value, $whatsapp, true));
@endphp
<x-layouts.app title="Notification settings" description="Choose which notifications also arrive by e-mail or WhatsApp. Every notification always appears in the app."
               :breadcrumbs="[['label' => 'Account'], ['label' => 'Notifications']]">
    <form method="POST" action="{{ route('account.notifications.save') }}" class="max-w-2xl space-y-4">
        @csrf
        @if ($whatsapp !== [])
            <x-ui.card title="WhatsApp number" description="Organisation notifications are sent here when WhatsApp is switched on. Leave blank to receive none.">
                <x-ui.form.field label="Mobile number" for="phone" hint="With country code, e.g. 919876543210 (a 10-digit number is taken as Indian).">
                    <x-ui.form.input name="phone" :value="$phone" inputmode="tel" autocomplete="tel" class="font-mono" />
                </x-ui.form.field>
            </x-ui.card>
        @endif
        <x-ui.card :padding="false">
            <table class="data-table">
                <thead><tr><th>Notification</th><th class="text-right">E-mail</th>@if ($whatsapp !== [])<th class="text-right">WhatsApp</th>@endif</tr></thead>
                <tbody>
                    @foreach ($rows as $type)
                        <tr>
                            <td>{{ $type->label() }}</td>
                            <td class="text-right">
                                @if (in_array($type->value, $mailTypes, true))
                                    <input type="checkbox" name="mail[]" value="{{ $type->value }}" aria-label="E-mail for {{ $type->label() }}"
                                           class="size-4 accent-accent-600"
                                           @checked($preferences[$type->value]['mail'] ?? $type->mailByDefault())>
                                @else <span class="muted">—</span> @endif
                            </td>
                            @if ($whatsapp !== [])
                                <td class="text-right">
                                    @if (in_array($type->value, $whatsapp, true))
                                        <input type="checkbox" name="whatsapp[]" value="{{ $type->value }}" aria-label="WhatsApp for {{ $type->label() }}"
                                               class="size-4 accent-accent-600"
                                               @checked($preferences[$type->value]['whatsapp'] ?? true)>
                                    @else <span class="muted">—</span> @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
        <x-ui.button type="submit">Save</x-ui.button>
    </form>
</x-layouts.app>
