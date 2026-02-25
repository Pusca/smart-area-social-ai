<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#4f46e5">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>{{ config('app.name', 'Social AI') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-[var(--sa-bg)] text-[var(--sa-text)] antialiased">
    @php($isHome = request()->routeIs('home'))

    @unless($isHome)
        <header class="sticky top-0 z-40 border-b bg-white/80 backdrop-blur">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <a href="{{ route('home') }}" class="flex items-center gap-3">
                        <div class="leading-tight">
                            <x-application-logo class="h-8 w-auto" />
                            <div class="text-[11px] text-gray-500">Workspace</div>
                        </div>
                    </a>

                    <div class="flex items-center gap-2">
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}"
                               class="rounded-xl px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">
                                Accedi
                            </a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}"
                               class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                Registrati
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </header>
    @endunless

    <main class="{{ $isHome ? 'relative min-h-screen px-4 py-8 sm:px-6 lg:px-8' : 'mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-10' }}">
        {{ $slot }}
    </main>

    @unless($isHome)
        <footer class="border-t bg-white">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 text-xs text-gray-500 flex items-center justify-between">
                <div>&copy; {{ date('Y') }} {{ config('app.name', 'Social AI') }}</div>
                <div class="hidden sm:block">Minimal · Clean · App UI</div>
            </div>
        </footer>
    @endunless

    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function (err) {
                console.error('SW register failed', err);
            });
        });
    }
    </script>
</body>
</html>
