<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Social AI') }} - Admin</title>
    <meta name="theme-color" content="#0A2D6F">
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-app text-text antialiased">
    <header class="sticky top-0 z-40 border-b border-app bg-white/90 backdrop-blur-xl">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <x-application-logo class="h-[4.5rem] w-auto" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">{{ config('app.name', 'Social AI') }}</div>
                    <div class="text-[11px] text-muted">Controllo amministrativo</div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('admin.dashboard') }}" class="ui-btn-secondary">
                    Dashboard admin
                </a>
                <span class="hidden rounded-2xl border border-app bg-surface-2 px-3 py-2 text-xs font-semibold text-muted sm:inline">
                    {{ auth()->user()?->email }}
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="ui-btn-primary">
                        Esci
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @yield('content')
    </main>
</body>
</html>
