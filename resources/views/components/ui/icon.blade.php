@props(['name'])
{{-- Lucide icon. Usage: <x-ui.icon name="mail" class="size-4" /> --}}
{{ svg('lucide-'.$name, $attributes->get('class', 'size-4'), ['aria-hidden' => 'true']) }}
