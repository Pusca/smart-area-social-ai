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
<body class="overflow-x-hidden font-sans antialiased bg-app text-text">
    @php
        $desktopNavItems = [
            ['route' => 'dashboard', 'label' => 'Home', 'active' => ['dashboard']],
            ['route' => 'setup.index', 'label' => 'Setup', 'active' => ['setup.index', 'settings', 'profile.brand*']],
            ['route' => 'posts.create', 'label' => 'Crea', 'active' => ['posts.create', 'posts.reels.create']],
            ['route' => 'calendar', 'label' => 'Pianifica', 'active' => ['calendar', 'wizard*', 'plans.generating']],
            ['route' => 'posts.index', 'label' => 'Libreria', 'active' => ['posts.index', 'posts.edit', 'posts.generating', 'posts.generation.*', 'content-items.*']],
        ];
        $mobileNavItems = [
            ['route' => 'dashboard', 'label' => 'Home', 'active' => ['dashboard']],
            ['route' => 'setup.index', 'label' => 'Setup', 'active' => ['setup.index', 'settings', 'profile.brand*']],
            ['route' => 'posts.create', 'label' => 'Crea', 'active' => ['posts.create', 'posts.reels.create']],
            ['route' => 'calendar', 'label' => 'Piano', 'active' => ['calendar', 'wizard*', 'plans.generating']],
            ['route' => 'posts.index', 'label' => 'Lib', 'active' => ['posts.index', 'posts.edit', 'posts.generating', 'posts.generation.*', 'content-items.*']],
        ];
    @endphp

    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-20 top-0 h-72 w-72 rounded-full blur-3xl" style="background: rgba(59, 200, 255, 0.14);"></div>
        <div class="absolute right-0 top-0 h-80 w-80 rounded-full blur-3xl" style="background: rgba(10, 45, 111, 0.08);"></div>
    </div>

    <div class="min-h-screen">
        <header class="sticky top-0 z-40 hidden border-b border-app bg-white/88 backdrop-blur-xl sm:block">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-6 py-3">
                <a href="{{ route('dashboard') }}" class="shrink-0">
                    <x-application-logo class="h-[4.75rem] w-auto" />
                </a>

                <nav class="flex items-center gap-2 text-sm">
                    @foreach($desktopNavItems as $item)
                        @php
                            $patterns = \Illuminate\Support\Arr::wrap($item['active']);
                            $isActive = collect($patterns)->contains(fn ($pattern) => request()->routeIs($pattern));
                        @endphp
                        <a
                            href="{{ route($item['route']) }}"
                            class="{{ $isActive ? 'ui-nav-link-active' : 'ui-nav-link' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-3">
                    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-3 rounded-2xl border border-app bg-surface-2 px-3 py-2 transition hover:bg-white">
                        <x-application-logo variant="icon" class="h-10 w-10" />
                        <div class="hidden text-left lg:block">
                            <p class="text-[11px] uppercase tracking-[0.18em] text-muted">Account</p>
                            <p class="text-sm font-semibold text-gray-900">{{ auth()->user()?->name }}</p>
                        </div>
                    </a>
                </div>
            </div>
        </header>

        <header class="sticky top-0 z-40 border-b border-app bg-white/92 backdrop-blur-xl sm:hidden">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3">
                <a href="{{ route('dashboard') }}" class="shrink-0">
                    <x-application-logo class="h-12 w-auto" />
                </a>

                <a
                    href="{{ route('setup.index') }}"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-app bg-surface-2 text-gray-700 transition hover:bg-white"
                    aria-label="Account"
                    title="Account"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10 2.75a3.75 3.75 0 1 0 0 7.5 3.75 3.75 0 0 0 0-7.5ZM5.5 6.5a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.52 15.1a5.75 5.75 0 0 1 5.32-3.6h2.32a5.75 5.75 0 0 1 5.32 3.6.75.75 0 1 1-1.39.56A4.25 4.25 0 0 0 11.16 13H8.84a4.25 4.25 0 0 0-3.93 2.66.75.75 0 1 1-1.39-.56Z"/>
                    </svg>
                </a>
            </div>
        </header>

        @php
            $impersonation = session('admin_impersonation', []);
            $isImpersonating = !empty($impersonation['original_admin_id']);
        @endphp

        @if($isImpersonating)
            <div class="border-b border-amber-200 bg-amber-50/95">
                <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <div>
                        <p class="font-semibold">Modalita admin attiva nel workspace</p>
                        <p class="text-xs text-amber-800">
                            Tenant: {{ $impersonation['target_tenant_name'] ?? 'Tenant' }}
                            · Utente: {{ $impersonation['target_user_name'] ?? auth()->user()?->name }}
                            · Admin origine: {{ $impersonation['original_admin_email'] ?? '' }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('admin.impersonation.stop') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100">
                            Torna alla dashboard admin
                        </button>
                    </form>
                </div>
            </div>
        @endif

        @isset($header)
            <div class="border-b border-app bg-white/70">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </div>
        @endisset

        <main class="overflow-x-hidden pb-28">
            @hasSection('content')
                @yield('content')
            @elseif (isset($slot))
                {{ $slot }}
            @endif
        </main>
    </div>

    <nav class="fixed inset-x-0 bottom-0 z-50 border-t border-app bg-white/95 backdrop-blur sm:hidden">
        <div class="mx-auto max-w-7xl px-3 pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-5 gap-2 py-2 text-center text-[11px]">
                @foreach($mobileNavItems as $item)
                    @php
                        $patterns = \Illuminate\Support\Arr::wrap($item['active']);
                        $isActive = collect($patterns)->contains(fn ($pattern) => request()->routeIs($pattern));
                    @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        class="touch-manipulation select-none rounded-2xl px-2 py-3 {{ $isActive ? 'bg-brand text-white font-semibold shadow-lg shadow-blue-900/10' : 'text-gray-700 hover:bg-surface-2' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>
</body>
</html>

