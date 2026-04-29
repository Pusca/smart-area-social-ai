<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Social AI') }}</title>
    <meta name="theme-color" content="#0A2D6F">
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('brand/icona-socialai.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="overflow-x-hidden font-sans antialiased bg-app text-text">
    @php
        $desktopNavItems = [
            ['route' => 'dashboard', 'label' => 'Home', 'active' => ['dashboard']],
            ['route' => 'settings', 'label' => 'Setup', 'active' => ['settings', 'profile.brand*']],
            ['route' => 'posts.create', 'label' => 'Crea', 'active' => ['posts.create']],
            ['route' => 'calendar', 'label' => 'Pianifica', 'active' => ['calendar', 'wizard*', 'plans.generating']],
            ['route' => 'posts.index', 'label' => 'Libreria', 'active' => ['posts.index', 'posts.edit', 'posts.generating', 'posts.generation.*', 'content-items.*']],
        ];
        $mobileNavItems = [
            ['route' => 'dashboard', 'label' => 'Home', 'active' => ['dashboard']],
            ['route' => 'posts.create', 'label' => 'Crea', 'active' => ['posts.create']],
            ['route' => 'calendar', 'label' => 'Piano', 'active' => ['calendar', 'wizard*', 'plans.generating']],
            ['route' => 'posts.index', 'label' => 'Libreria', 'active' => ['posts.index', 'posts.edit', 'posts.generating', 'posts.generation.*', 'content-items.*']],
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
                    <a href="{{ route('settings') }}" class="inline-flex items-center gap-3 rounded-2xl border border-app bg-surface-2 px-3 py-2 transition hover:bg-white">
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
                    href="{{ route('settings') }}"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-app bg-surface-2 text-gray-700 transition hover:bg-white"
                    aria-label="Setup"
                    title="Setup"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.84 1.804A1 1 0 0 1 8.82 1h2.36a1 1 0 0 1 .98.804l.295 1.473a6.96 6.96 0 0 1 1.052.608l1.387-.554a1 1 0 0 1 1.174.38l1.18 2.044a1 1 0 0 1-.205 1.27l-1.13.966a7.1 7.1 0 0 1 0 1.218l1.13.966a1 1 0 0 1 .205 1.27l-1.18 2.044a1 1 0 0 1-1.174.38l-1.387-.554a6.96 6.96 0 0 1-1.052.608l-.295 1.473A1 1 0 0 1 11.18 19H8.82a1 1 0 0 1-.98-.804l-.295-1.473a6.96 6.96 0 0 1-1.052-.608l-1.387.554a1 1 0 0 1-1.174-.38L2.752 14.245a1 1 0 0 1 .205-1.27l1.13-.966a7.1 7.1 0 0 1 0-1.218l-1.13-.966a1 1 0 0 1-.205-1.27L3.932 6.511a1 1 0 0 1 1.174-.38l1.387.554a6.96 6.96 0 0 1 1.052-.608L7.84 1.804ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/>
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

    <nav class="mobile-nav fixed inset-x-0 bottom-0 z-50 border-t border-app bg-white/95 backdrop-blur sm:hidden">
        <div class="mx-auto max-w-7xl px-3 pb-[env(safe-area-inset-bottom)]">
            <div class="ui-mobile-nav" style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr));">
                @foreach($mobileNavItems as $item)
                    @php
                        $patterns = \Illuminate\Support\Arr::wrap($item['active']);
                        $isActive = collect($patterns)->contains(fn ($pattern) => request()->routeIs($pattern));
                    @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        class="flex min-w-0 items-center justify-center whitespace-nowrap touch-manipulation select-none rounded-2xl px-2 py-3 {{ $isActive ? 'bg-brand text-white font-semibold shadow-lg shadow-blue-900/10' : 'text-gray-700 hover:bg-surface-2' }}"
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    @auth
        <div
            id="generation-drawer"
            data-feed-url="{{ route('posts.generation.feed') }}"
            class="pointer-events-none fixed inset-x-0 bottom-20 z-50 hidden px-3 sm:bottom-6 sm:left-auto sm:right-6 sm:w-[24rem] sm:px-0"
        >
            <div class="pointer-events-auto overflow-hidden rounded-[28px] border border-slate-200 bg-white/95 shadow-[0_24px_80px_rgba(15,23,42,0.18)] backdrop-blur">
                <button
                    id="generation-drawer-toggle"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 bg-gradient-to-r from-cyan-500 via-sky-600 to-indigo-700 px-4 py-4 text-left text-white"
                    aria-expanded="true"
                >
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-white/80">Generazioni attive</p>
                        <p id="generation-drawer-summary" class="mt-1 truncate text-sm font-semibold">Sto seguendo i contenuti in background.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span id="generation-drawer-count" class="inline-flex min-w-[2rem] items-center justify-center rounded-full bg-white/18 px-2 py-1 text-xs font-semibold text-white">0</span>
                        <span id="generation-drawer-chevron" class="text-lg leading-none">−</span>
                    </div>
                </button>

                <div id="generation-drawer-body" class="space-y-3 border-t border-slate-200 bg-white/96 p-3">
                    <div id="generation-drawer-items" class="max-h-[55vh] space-y-3 overflow-y-auto pr-1"></div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-600">
                        Puoi continuare a navigare: aggiorno questo pannello finche la generazione non esce da sola da queued o pending.
                    </div>
                </div>
            </div>
        </div>
    @endauth

    @auth
        <script>
            (() => {
                const root = document.getElementById('generation-drawer');
                if (!root || !window.fetch) {
                    return;
                }

                const feedUrl = root.dataset.feedUrl || '';
                if (!feedUrl) {
                    return;
                }

                const toggleBtn = document.getElementById('generation-drawer-toggle');
                const bodyEl = document.getElementById('generation-drawer-body');
                const itemsEl = document.getElementById('generation-drawer-items');
                const countEl = document.getElementById('generation-drawer-count');
                const summaryEl = document.getElementById('generation-drawer-summary');
                const chevronEl = document.getElementById('generation-drawer-chevron');
                const collapsedKey = 'socialai:generation-drawer:collapsed';
                let pollTimer = null;

                const formatAge = (seconds) => {
                    const safe = Math.max(0, Math.round(Number(seconds || 0)));
                    if (safe < 60) {
                        return `${safe} sec`;
                    }

                    const minutes = Math.floor(safe / 60);
                    const rest = safe % 60;
                    return rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
                };

                const escapeHtml = (value) => String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');

                const setCollapsed = (collapsed) => {
                    if (!bodyEl || !toggleBtn) {
                        return;
                    }

                    bodyEl.classList.toggle('hidden', collapsed);
                    toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    if (chevronEl) {
                        chevronEl.textContent = collapsed ? '+' : '−';
                    }
                    window.localStorage.setItem(collapsedKey, collapsed ? '1' : '0');
                };

                const isCollapsed = () => window.localStorage.getItem(collapsedKey) === '1';

                const renderItems = (items) => {
                    if (!itemsEl || !summaryEl || !countEl) {
                        return;
                    }

                    if (!Array.isArray(items) || items.length === 0) {
                        root.classList.add('hidden');
                        itemsEl.innerHTML = '';
                        countEl.textContent = '0';
                        summaryEl.textContent = 'Nessuna generazione attiva.';
                        return;
                    }

                    root.classList.remove('hidden');
                    countEl.textContent = String(items.length);
                    summaryEl.textContent = items.length === 1
                        ? `1 contenuto in lavorazione: ${items[0]?.title || 'contenuto'}`
                        : `${items.length} contenuti in lavorazione`;

                    itemsEl.innerHTML = items.map((item) => {
                        const status = item?.status || {};
                        const runtime = item?.runtime || {};
                        const progress = Math.max(6, Math.min(100, Number(runtime.progress || 0)));
                        const title = escapeHtml(item?.title || `Contenuto #${item?.id || ''}`);
                        const stage = escapeHtml(runtime.stage_label || status.description || 'Generazione in corso');
                        const age = formatAge(runtime.age_seconds || 0);
                        const editUrl = escapeHtml(item?.edit_url || '#');
                        const generatingUrl = escapeHtml(item?.generating_url || editUrl);
                        const format = escapeHtml(String(item?.format || 'post').toUpperCase());
                        const platform = escapeHtml(String(item?.platform || 'instagram').toUpperCase());
                        const badgeClass = escapeHtml(status.badge || 'border-slate-200 bg-slate-50 text-slate-700');
                        const label = escapeHtml(status.label || 'In lavorazione');

                        return `
                            <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900">${title}</p>
                                        <p class="mt-1 text-xs uppercase tracking-[0.18em] text-slate-500">${format} · ${platform}</p>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${badgeClass}">
                                        ${label}
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <div class="flex items-center justify-between gap-3 text-xs text-slate-600">
                                        <span class="truncate">${stage}</span>
                                        <span>${age}</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-sky-500 to-emerald-400" style="width:${progress}%"></div>
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center gap-2">
                                    <a href="${editUrl}" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Apri contenuto</a>
                                    <a href="${generatingUrl}" class="inline-flex items-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apri live</a>
                                </div>
                            </article>
                        `;
                    }).join('');
                };

                const poll = async () => {
                    try {
                        const response = await fetch(feedUrl, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });

                        if (!response.ok) {
                            return;
                        }

                        const payload = await response.json();
                        renderItems(Array.isArray(payload?.items) ? payload.items : []);
                    } catch (_) {
                        // no-op
                    }
                };

                setCollapsed(isCollapsed());
                poll();
                pollTimer = window.setInterval(poll, 8000);

                toggleBtn?.addEventListener('click', () => {
                    setCollapsed(!isCollapsed());
                });

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        poll();
                    }
                });
            })();
        </script>
    @endauth
</body>
</html>

