<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Social AI') }}</title>
    <meta name="theme-color" content="#0A2D6F">
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-app text-text antialiased">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-24 -top-16 h-72 w-72 rounded-full blur-3xl" style="background: rgba(59, 200, 255, 0.14);"></div>
        <div class="absolute right-0 top-0 h-80 w-80 rounded-full blur-3xl" style="background: rgba(10, 45, 111, 0.08);"></div>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
        {{ $slot }}
    </main>

    <footer class="border-t border-app bg-white/78">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-6 text-xs text-muted sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <x-application-logo variant="icon" class="h-10 w-10" />
                <span>&copy; {{ date('Y') }} {{ config('app.name', 'Social AI') }}</span>
            </div>
            <div class="hidden sm:block">Pianifica, crea e pubblica contenuti con un'esperienza piu chiara.</div>
        </div>
    </footer>
</body>
</html>
