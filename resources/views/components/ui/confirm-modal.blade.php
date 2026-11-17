@props([
    'name',
    'title',
    'action',
    'method' => 'POST',
    'confirmLabel' => 'Confirm',
    'danger' => false,
    'requireReason' => false,
    'confirmText' => null,
])
{{--
    Confirmation dialog that submits a real form (CSRF-protected, server-authorised).
    - requireReason: adds a mandatory "reason" textarea (suspension, deletion, rejection…).
    - confirmText:   the user must type this exact text before the button enables
                     (use for destructive actions, e.g. the mailbox address).
--}}
<x-ui.modal :name="$name" :title="$title">
    <form method="POST" action="{{ $action }}" x-data="{ typed: '' }" class="space-y-4">
        @csrf
        @if (strtoupper($method) !== 'POST') @method($method) @endif

        <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $slot }}</div>

        @if ($requireReason)
            <x-ui.form.field label="Reason" for="{{ $name }}-reason" name="reason" required>
                <x-ui.form.textarea name="reason" id="{{ $name }}-reason" required maxlength="500" />
            </x-ui.form.field>
        @endif

        @if ($confirmText)
            <x-ui.form.field for="{{ $name }}-confirm">
                <p class="text-sm">Type <span class="font-mono font-medium">{{ $confirmText }}</span> to confirm.</p>
                <input type="text" id="{{ $name }}-confirm" x-model="typed" autocomplete="off" class="control">
            </x-ui.form.field>
        @endif

        <div class="flex flex-wrap justify-end gap-2 pt-1">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal', '{{ $name }}')">Cancel</x-ui.button>
            <x-ui.button type="submit" :variant="$danger ? 'danger' : 'primary'"
                         x-bind:disabled="{{ $confirmText ? 'typed !== '.\Illuminate\Support\Js::from($confirmText) : 'false' }}">
                {{ $confirmLabel }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
