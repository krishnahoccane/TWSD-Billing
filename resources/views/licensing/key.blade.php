<x-layouts.app :title="$license->number" description="License key"
               :breadcrumbs="[['label' => 'Software Licensing'], ['label' => 'Licenses', 'url' => route('licensing.index')], ['label' => $license->number, 'url' => route('licensing.show', $license)], ['label' => 'Key']]">
    <x-ui.alert tone="warning" title="License key — keep it private" class="max-w-2xl">
        <p class="mt-2 rounded bg-white px-3 py-2 font-mono text-base tracking-wide text-zinc-900 select-all dark:bg-zinc-900 dark:text-zinc-100">{{ $key }}</p>
        <p class="mt-2 text-xs">This reveal was recorded in the audit log. Anyone with the key can activate an installation on a free domain slot.</p>
    </x-ui.alert>
    <div class="mt-4"><x-ui.button variant="secondary" icon="arrow-left" :href="route('licensing.show', $license)">Back to the license</x-ui.button></div>
</x-layouts.app>
