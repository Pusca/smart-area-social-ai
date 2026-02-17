<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Social AI') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    {{-- Top bar (app-style) --}}
    <header class="sticky top-0 z-40 border-b bg-white/80 backdrop-blur">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    {{-- Simple monogram --}}
                    <div class="h-10 w-10 rounded-2xl bg-gray-900 text-white grid place-items-center font-bold tracking-tight">
                        SA
                    </div>
                    <div class="leading-tight">
                        <div class="text-sm font-semibold">{{ config('app.name', 'Social AI') }}</div>
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

    <main class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-10">
        {{ $slot }}
    </main>

    <footer class="border-t bg-white">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 text-xs text-gray-500 flex items-center justify-between">
            <div>© {{ date('Y') }} {{ config('app.name', 'Social AI') }}</div>
            <div class="hidden sm:block">Minimal · Clean · App UI</div>
        </div>
    </footer>
</body>
</html>
