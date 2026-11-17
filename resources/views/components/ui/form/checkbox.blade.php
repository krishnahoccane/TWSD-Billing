@props(['name', 'label', 'checked' => false, 'value' => '1'])
<label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked))
           {{ $attributes->class('size-4 rounded border-zinc-300 text-accent-600 accent-accent-600 dark:border-zinc-700') }}>
    <span>{{ $label }}</span>
</label>
