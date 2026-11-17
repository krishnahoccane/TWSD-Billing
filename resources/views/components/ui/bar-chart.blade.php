@props(['data' => [], 'format' => null, 'empty' => 'No data yet'])
{{--
    Simple horizontal bar chart, rendered on the server (no chart library).
    data: [['label' => 'Apr 2026', 'value' => 125000], ...]   format: 'money' | null
    Values are also shown as text, so the chart reads without colour.
--}}
@php
    $max = max(1, ...array_map(fn ($row) => (int) $row['value'], $data ?: [['value' => 0]]));
    $show = fn ($value) => $format === 'money' ? \App\Modules\Billing\Support\Money::format((int) $value) : number_format((int) $value);
@endphp
@if (collect($data)->sum('value') == 0)
    <p class="muted py-6 text-center text-sm">{{ $empty }}</p>
@else
    <ul {{ $attributes->class('space-y-1.5') }} role="list">
        @foreach ($data as $row)
            <li class="grid grid-cols-[6.5rem_1fr_auto] items-center gap-2 text-xs">
                <span class="muted truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                <span class="h-2.5 overflow-hidden rounded-sm bg-zinc-100 dark:bg-zinc-800" aria-hidden="true">
                    <span class="block h-full rounded-sm bg-accent-500" style="width: {{ round(((int) $row['value'] / $max) * 100, 1) }}%"></span>
                </span>
                <span class="text-right tabular-nums">{{ $show($row['value']) }}</span>
            </li>
        @endforeach
    </ul>
@endif
