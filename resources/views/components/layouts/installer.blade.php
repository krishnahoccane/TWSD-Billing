@props(['step' => 1, 'heading', 'subheading' => null])
@php($steps = \App\Modules\Installer\Http\Controllers\InstallerController::STEPS)
<x-layouts.base :title="'Install · '.$heading">
    <main class="flex min-h-screen flex-col items-center px-4 py-10">
        <div class="w-full max-w-2xl">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <span class="flex size-8 items-center justify-center rounded-md bg-accent-600 text-white">
                    <x-ui.icon name="layout-dashboard" class="size-4" />
                </span>
                <span class="text-base font-semibold tracking-tight">{{ config('app.name') }} — installation</span>
            </div>

            <ol class="mb-6 grid grid-cols-4 gap-2" aria-label="Installation steps">
                @foreach ($steps as $number => [$route, $label])
                    @php($state = $number < $step ? 'done' : ($number === $step ? 'current' : 'todo'))
                    <li @if ($state === 'current') aria-current="step" @endif
                        @class([
                            'rounded-md border px-3 py-2 text-xs',
                            'border-accent-600 bg-accent-50 text-accent-700 dark:bg-accent-700/10 dark:text-accent-400' => $state === 'current',
                            'border-emerald-200 text-emerald-700 dark:border-emerald-900 dark:text-emerald-400' => $state === 'done',
                            'border-zinc-200 text-zinc-500 dark:border-zinc-800' => $state === 'todo',
                        ])>
                        <span class="flex items-center gap-1.5 font-medium">
                            @if ($state === 'done')<x-ui.icon name="circle-check" class="size-3.5" />@else<span>{{ $number }}.</span>@endif
                            {{ $label }}
                        </span>
                    </li>
                @endforeach
            </ol>

            <div class="surface p-6 shadow-xs">
                <h1 class="text-lg font-semibold tracking-tight">{{ $heading }}</h1>
                @if ($subheading)
                    <p class="muted mt-1 text-sm">{{ $subheading }}</p>
                @endif

                <div class="mt-5 space-y-4">
                    @if (session('error'))
                        <x-ui.alert tone="danger">{{ session('error') }}</x-ui.alert>
                    @endif
                    @if (session('success'))
                        <x-ui.alert tone="success">{{ session('success') }}</x-ui.alert>
                    @endif

                    {{ $slot }}
                </div>
            </div>

            <p class="muted mt-4 text-center text-xs">
                Nothing is written to the server until the last step. Passwords are never shown again once entered.
            </p>
        </div>
    </main>
</x-layouts.base>
