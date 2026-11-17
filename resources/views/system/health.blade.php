@php
    $icons = ['ok' => ['circle-check', 'text-emerald-600'], 'warn' => ['triangle-alert', 'text-amber-600'], 'fail' => ['circle-alert', 'text-red-600']];
    $failing = collect($checks)->where(1, 'fail')->count();
    $warning = collect($checks)->where(1, 'warn')->count();
@endphp
<x-layouts.app title="System health" description="Live checks of the database, cache, queue, scheduler, integrations and production settings."
               :breadcrumbs="[['label' => 'System'], ['label' => 'Health']]">
    <x-ui.alert :tone="$failing ? 'danger' : ($warning ? 'warning' : 'success')" class="mb-6">
        {{ $failing ? $failing.' check(s) failing' : ($warning ? $warning.' warning(s)' : 'All checks pass') }}.
    </x-ui.alert>

    <x-ui.card :padding="false" class="max-w-4xl">
        <table class="data-table">
            <tbody>
                @foreach ($checks as [$label, $state, $detail])
                    <tr>
                        <td class="w-8"><x-ui.icon :name="$icons[$state][0]" class="size-4 {{ $icons[$state][1] }}" /></td>
                        <td class="font-medium">{{ $label }}</td>
                        <td class="muted text-right text-sm">{{ $detail }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
</x-layouts.app>
