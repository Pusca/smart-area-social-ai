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

    <title>{{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-[var(--sa-bg)] text-[var(--sa-text)]">
    <div class="min-h-screen">

        {{-- TOP NAV (desktop) --}}
        <header class="hidden sm:block bg-white border-b sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold">
                        S
                    </div>
                    <div class="leading-tight">
                        <div class="font-semibold">{{ config('app.name', 'Smartera Social AI') }}</div>
                        <div class="text-xs text-gray-500">Dashboard</div>
                    </div>
                </div>

                <nav class="flex items-center gap-2 text-sm">
                    <a href="{{ route('dashboard') }}"
                       class="px-3 py-2 rounded-xl {{ request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                        Home
                    </a>

                    <a href="{{ route('calendar') }}"
                       class="px-3 py-2 rounded-xl {{ request()->routeIs('calendar') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                        Calendar
                    </a>

                    <a href="{{ route('posts.index') }}"
                       class="px-3 py-2 rounded-xl {{ request()->routeIs('posts*') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                        Posts
                    </a>

                    <a href="{{ route('wizard.start') }}"
                       class="px-3 py-2 rounded-xl {{ request()->routeIs('wizard.*') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                        Wizard
                    </a>

                    <a href="{{ route('settings') }}"
                       class="px-3 py-2 rounded-xl {{ request()->routeIs('settings') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                        Settings
                    </a>
                </nav>
            </div>
        </header>

        {{-- Header "slot" (se una view lo usa) --}}
        @isset($header)
            <div class="bg-white border-b">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </div>
        @endisset

        {{-- Page Content --}}
        <main class="pb-24">
            @hasSection('content')
                @yield('content')
            @elseif (isset($slot))
                {{ $slot }}
            @endif
        </main>

    </div>

   {{-- Bottom nav (mobile) --}}
<nav class="fixed bottom-0 inset-x-0 z-50 border-t bg-white/95 backdrop-blur sm:hidden">
    <div class="max-w-7xl mx-auto px-3 pb-[env(safe-area-inset-bottom)]">
        <div class="grid grid-cols-5 text-center text-xs gap-1 py-2">

            <a href="{{ route('dashboard') }}"
               class="touch-manipulation select-none rounded-xl px-2 py-3
               {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                Home
            </a>

            <a href="{{ route('calendar') }}"
               class="touch-manipulation select-none rounded-xl px-2 py-3
               {{ request()->routeIs('calendar') ? 'bg-gray-900 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                Calendar
            </a>

            <a href="{{ route('posts.index') }}"
               class="touch-manipulation select-none rounded-xl px-2 py-3
               {{ request()->routeIs('posts*') ? 'bg-gray-900 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                Posts
            </a>

            <a href="{{ route('wizard.start') }}"
               class="touch-manipulation select-none rounded-xl px-2 py-3
               {{ request()->routeIs('wizard.*') ? 'bg-gray-900 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                Wizard
            </a>

            <a href="{{ route('settings') }}"
               class="touch-manipulation select-none rounded-xl px-2 py-3
               {{ request()->routeIs('settings') ? 'bg-gray-900 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                Settings
            </a>

        </div>
    </div>
</nav>

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
