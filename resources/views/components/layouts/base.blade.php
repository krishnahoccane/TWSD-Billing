@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    {{-- Apply the saved theme before first paint to avoid a flash. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            try {
                var theme = localStorage.getItem('wcc-theme');
                if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
</head>
<body {{ $attributes->class('min-h-screen font-sans') }}>
    {{ $slot }}
    @livewireScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
</body>
</html>
