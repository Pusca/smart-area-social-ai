<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Social AI') }}</title>
    <meta name="theme-color" content="#0E3B8F">
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-app text-text antialiased">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-24 -top-16 h-72 w-72 rounded-full blur-3xl" style="background: rgba(160, 224, 255, 0.16);"></div>
        <div class="absolute right-0 top-0 h-80 w-80 rounded-full blur-3xl" style="background: rgba(0, 64, 128, 0.1);"></div>
    </div>

    <header class="sticky top-0 z-40 border-b border-app bg-white/86 backdrop-blur-xl">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="flex min-h-[4.75rem] items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <x-application-logo variant="icon" class="h-12 w-12 sm:hidden" />
                    <x-application-logo class="hidden h-16 w-auto sm:block" />
                    <div class="leading-tight">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand">Social content workspace</div>
                        <div class="text-sm font-semibold text-gray-900">{{ config('app.name', 'Social AI') }}</div>
                        <div class="text-[11px] text-muted">Più presenza, più continuità, meno attrito operativo.</div>
                    </div>
                </a>

                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}"
                           class="ui-btn-primary">
                            Apri workspace
                        </a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}"
                               class="ui-btn-secondary">
                                Accedi
                            </a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}"
                               class="ui-btn-primary">
                                Prova Social AI
                            </a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
        {{ $slot }}
    </main>

    <footer class="border-t border-app bg-white/78">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-6 text-xs text-muted sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <x-application-logo variant="icon" class="h-9 w-9" />
                <span>&copy; {{ date('Y') }} {{ config('app.name', 'Social AI') }}</span>
            </div>
            <div class="hidden sm:block">Pianifica, crea e pubblica contenuti con un'esperienza piu chiara.</div>
        </div>
    </footer>
</body>
</html>
