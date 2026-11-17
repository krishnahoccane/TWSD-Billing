@props(['label' => 'Loading…'])
{{-- With Livewire: <x-ui.loading-state wire:loading /> --}}
<div {{ $attributes->class('muted flex items-center justify-center gap-2 px-4 py-8 text-sm') }} role="status">
    <x-ui.icon name="loader-circle" class="size-4 animate-spin" />
    <span>{{ $label }}</span>
</div>
