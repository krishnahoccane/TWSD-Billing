@props(['name', 'value' => null, 'rows' => 3, 'bag' => 'default'])
@php($invalid = $errors->getBag($bag)->has($name))
<textarea name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" rows="{{ $rows }}"
          @if ($invalid) aria-invalid="true" @endif
          {{ $attributes->except('id')->class(['control', 'control-invalid' => $invalid]) }}>{{ old($name, $value) }}</textarea>
