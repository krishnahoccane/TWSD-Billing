@props(['label' => null, 'for' => null, 'name' => null, 'hint' => null, 'required' => false, 'bag' => 'default'])
{{-- Label + control + hint + validation error for one form field. --}}
@php
    $name ??= $for;
    $error = $name ? $errors->getBag($bag)->first($name) : null;
@endphp
<div {{ $attributes->class('space-y-1.5') }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-medium">
            {{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $error }}</p>
    @elseif ($hint)
        <p class="muted text-xs">{{ $hint }}</p>
    @endif
</div>
