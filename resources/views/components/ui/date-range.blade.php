@props(['fromName' => 'from', 'toName' => 'to', 'from' => null, 'to' => null])
<div {{ $attributes->class('flex items-center gap-2 text-sm') }}>
    <x-ui.icon name="calendar" class="size-4 shrink-0 text-zinc-400" />
    <input type="date" name="{{ $fromName }}" value="{{ $from }}" aria-label="From date" class="control w-auto py-1.5">
    <span class="muted">to</span>
    <input type="date" name="{{ $toName }}" value="{{ $to }}" aria-label="To date" class="control w-auto py-1.5">
</div>
