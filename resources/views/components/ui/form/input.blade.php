@props(['name', 'type' => 'text', 'value' => null, 'bag' => 'default'])
@php
    $invalid = $errors->getBag($bag)->has($name);
    // Never repopulate secrets from old input.
    $secret = in_array($type, ['password'], true);
@endphp
<input type="{{ $type }}" name="{{ $name }}" id="{{ $attributes->get('id', $name) }}"
       @unless ($secret) value="{{ old($name, $value) }}" @endunless
       @if ($invalid) aria-invalid="true" @endif
       {{ $attributes->except('id')->class(['control', 'control-invalid' => $invalid]) }}>
