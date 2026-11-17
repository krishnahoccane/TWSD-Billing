@props(['name', 'options' => [], 'value' => null, 'placeholder' => null, 'bag' => 'default'])
@php
    $invalid = $errors->getBag($bag)->has($name);
    $current = (string) old($name, $value);
@endphp
<select name="{{ $name }}" id="{{ $attributes->get('id', $name) }}"
        @if ($invalid) aria-invalid="true" @endif
        {{ $attributes->except('id')->class(['control', 'control-invalid' => $invalid]) }}>
    @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
    @foreach ($options as $optionValue => $text)
        <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $text }}</option>
    @endforeach
    {{ $slot }}
</select>
