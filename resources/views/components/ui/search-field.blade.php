@props(['placeholder' => 'Search…', 'name' => 'search'])
<div class="relative w-full sm:w-64">
    <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-zinc-400" />
    <input type="search" name="{{ $name }}" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
           {{ $attributes->class('control py-1.5 pl-8') }}>
</div>
