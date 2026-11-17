<x-layouts.installer :step="$step" heading="Server requirements"
    subheading="Checks the PHP version, extensions and folder permissions this application needs.">
    @foreach ($groups as $group => $checks)
        <section>
            <h2 class="mb-2 text-sm font-semibold">{{ $group }}</h2>
            <ul class="divide-y divide-zinc-200 rounded-md border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                @foreach ($checks as $check)
                    <li class="flex items-start gap-3 px-3 py-2 text-sm">
                        @if ($check['status'] === 'ok')
                            <x-ui.icon name="circle-check" class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                        @elseif ($check['status'] === 'warn')
                            <x-ui.icon name="triangle-alert" class="mt-0.5 size-4 shrink-0 text-amber-600" />
                        @else
                            <x-ui.icon name="circle-alert" class="mt-0.5 size-4 shrink-0 text-red-600" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $check['label'] }}</p>
                            <p class="muted break-words text-xs">{{ $check['detail'] }}</p>
                        </div>
                        <x-ui.badge :tone="['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'][$check['status']]">
                            {{ ['ok' => 'OK', 'warn' => 'Recommended', 'fail' => 'Missing'][$check['status']] }}
                        </x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @unless ($passes)
        <x-ui.alert tone="danger" title="Installation cannot continue">
            Install the missing items marked in red, then check again.
        </x-ui.alert>
    @endunless

    <div class="flex justify-end gap-2">
        <x-ui.button variant="secondary" :href="route('install.requirements')" icon="refresh-cw">Check again</x-ui.button>
        <form method="POST" action="{{ route('install.requirements') }}">
            @csrf
            <x-ui.button type="submit" :disabled="! $passes">Continue</x-ui.button>
        </form>
    </div>
</x-layouts.installer>
