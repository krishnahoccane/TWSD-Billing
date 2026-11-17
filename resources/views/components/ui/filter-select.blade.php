@props(['label', 'options' => [], 'name' => null, 'selected' => null, 'all' => 'All'])
{{-- options: ['value' => 'Label', …] --}}
<label class="flex items-center gap-2 text-sm">
    <span class="muted whitespace-nowrap">{{ $label }}</span>
    <select @if ($name) name="{{ $name }}" @endif {{ $attributes->class('control w-auto py-1.5 pr-8') }}>
        @if ($all !== false)<option value="">{{ $all }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
</label>
