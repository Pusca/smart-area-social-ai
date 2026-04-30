@extends('layouts.app')

@section('content')
@php
    $uiPublication = fn ($status) => \App\Support\UiStatus::publication((string) $status);
    $uiAi          = fn ($status) => \App\Support\UiStatus::ai((string) $status);
@endphp

<style>
    @keyframes fade-up {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .anim-0 { animation: fade-up .35s ease both; }
    .anim-1 { animation: fade-up .35s .07s ease both; }
    .anim-2 { animation: fade-up .35s .14s ease both; }
    .anim-3 { animation: fade-up .35s .21s ease both; }
    .anim-4 { animation: fade-up .35s .28s ease both; }

    .stat-card { transition: box-shadow .18s, transform .18s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(99,102,241,.12); }

    .feed-cell { transition: transform .2s, box-shadow .2s; }
    .feed-cell:hover { transform: scale(1.04); box-shadow: 0 8px 28px rgba(15,23,42,.18); }

    .content-card { transition: box-shadow .15s, transform .15s; }
    .content-card:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,.10); }

    /* Brand gradient button */
    .btn-brand {
        display: inline-flex; align-items: center; gap: 8px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        color: #fff; border-radius: 1rem; padding: 10px 20px;
        font-size: .875rem; font-weight: 600; letter-spacing: -.01em;
        box-shadow: 0 2px 12px rgba(99,102,241,.30);
        transition: opacity .15s, box-shadow .15s;
        text-decoration: none;
    }
    .btn-brand:hover { opacity: .92; box-shadow: 0 4px 18px rgba(99,102,241,.38); }

    .btn-outline {
        display: inline-flex; align-items: center; gap: 8px;
        background: #fff; color: #374151;
        border: 1px solid #e5e7eb; border-radius: 1rem; padding: 10px 20px;
        font-size: .875rem; font-weight: 600;
        box-shadow: 0 1px 4px rgba(0,0,0,.04);
        transition: background .15s, border-color .15s;
        text-decoration: none;
    }
    .btn-outline:hover { background: #f9fafb; border-color: #d1d5db; }

    /* Feed header gradient */
    .feed-header {
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 55%, #a855f7 100%);
    }
</style>

<div class="w-full px-4 py-6 pb-28 sm:px-6 lg:px-8" style="max-width:1280px;margin:0 auto;">
<div class="space-y-5">

{{-- ══════════════════════════════════════
     1. HEADER
══════════════════════════════════════ --}}
<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between anim-0">
    <div class="min-w-0">
        <div class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $scoreBadgeClass }}">
            <x-application-logo variant="icon" class="h-3.5 w-3.5" />
            Workspace {{ $scoreLabel }}
        </div>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
            Ciao {{ $u?->name }} 👋
        </h1>
        <p class="mt-1 text-sm text-gray-500">
            @if($todayPending > 0)
                {{ $todayPending }} contenut{{ $todayPending === 1 ? 'o' : 'i' }} da completare oggi
                @if($nextItem?->scheduled_at) · prossimo alle {{ $nextItem->scheduled_at->format('H:i') }}@endif
            @elseif($queuedItems > 0)
                {{ $queuedItems }} contenut{{ $queuedItems === 1 ? 'o in' : 'i in' }} generazione
            @else
                Tutto in ordine — {{ $doneItems }} contenut{{ $doneItems === 1 ? 'o pronto' : 'i pronti' }}
            @endif
        </p>
    </div>
    <div class="flex shrink-0 gap-2">
        <a href="{{ route('posts.create') }}" class="btn-brand">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Crea
        </a>
        <a href="{{ route('wizard.start') }}" class="btn-outline">Piano</a>
    </div>
</div>

{{-- ══════════════════════════════════════
     2. STATS — 4 colonne
══════════════════════════════════════ --}}
<div class="grid grid-cols-4 gap-2 sm:gap-3 anim-1">

    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50">
            <svg class="h-4 w-4 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/>
            </svg>
        </div>
        <p class="mt-3 text-2xl font-bold text-gray-900 sm:text-3xl">{{ $doneItems }}</p>
        <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-widest text-gray-400 sm:text-[10px]">Generati</p>
        <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-indigo-500" style="width:{{ $aiCompletion }}%"></div>
        </div>
    </div>

    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50">
            <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
        </div>
        <p class="mt-3 text-2xl font-bold text-gray-900 sm:text-3xl">{{ $publishedItems }}</p>
        <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-widest text-gray-400 sm:text-[10px]">Pubblicati</p>
        <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-emerald-500" style="width:{{ $publishRate }}%"></div>
        </div>
    </div>

    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50">
            <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
        </div>
        <p class="mt-3 text-2xl font-bold {{ $queuedItems > 0 ? 'text-amber-600' : 'text-gray-900' }} sm:text-3xl">{{ $queuedItems }}</p>
        <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-widest text-gray-400 sm:text-[10px]">In coda</p>
        <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-amber-400" style="width:{{ min(100, $queueRate) }}%"></div>
        </div>
    </div>

    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl {{ $errorItems > 0 ? 'bg-red-50' : 'bg-gray-50' }}">
            <svg class="h-4 w-4 {{ $errorItems > 0 ? 'text-red-500' : 'text-gray-400' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
        </div>
        <p class="mt-3 text-2xl font-bold {{ $errorItems > 0 ? 'text-red-600' : 'text-gray-900' }} sm:text-3xl">{{ $errorItems }}</p>
        <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-widest text-gray-400 sm:text-[10px]">Errori</p>
        <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full {{ $errorItems > 0 ? 'bg-red-500' : 'bg-gray-200' }}" style="width:{{ max(4, $errorRate) }}%"></div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════
     3. FEED
══════════════════════════════════════ --}}
<div class="overflow-hidden rounded-[2rem] border border-purple-100 bg-white shadow-sm anim-2">

    <div class="feed-header px-6 py-5">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.6);">Feed preview</p>
                <p style="font-size:1rem;font-weight:700;color:#fff;margin-top:2px;">Anteprima piano visivo</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span style="background:rgba(255,255,255,.18);color:#fff;font-size:.7rem;font-weight:700;padding:4px 12px;border-radius:999px;">
                    {{ $instagramFeedItems->count() }} post
                </span>
                <a href="{{ route('posts.index') }}"
                   style="background:rgba(255,255,255,.22);color:#fff;font-size:.7rem;font-weight:700;padding:5px 13px;border-radius:999px;text-decoration:none;transition:background .15s;"
                   onmouseover="this.style.background='rgba(255,255,255,.32)'"
                   onmouseout="this.style.background='rgba(255,255,255,.22)'">
                    Libreria →
                </a>
            </div>
        </div>
    </div>

    <div class="p-4">
        @if($instagramFeedItems->isEmpty())
            <div class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-purple-100 bg-purple-50/40 py-12 text-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-purple-100">
                    <svg class="h-6 w-6 text-purple-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-500">Nessun contenuto ancora.</p>
                <a href="{{ route('posts.create') }}" class="btn-brand">Crea il primo</a>
            </div>
        @else
            <div class="grid grid-cols-3 gap-1.5">
                @foreach($instagramFeedItems as $gridItem)
                @php
                    $gridPubInfo = $uiPublication($gridItem->status);
                    $dotColor   = match($gridPubInfo['tone'] ?? 'neutral') {
                        'success' => '#10b981', 'info' => '#6366f1',
                        'danger'  => '#ef4444', default => '#9ca3af',
                    };
                    $preview = is_array($gridItem->media_preview ?? null) ? $gridItem->media_preview : [];
                    $vidPath = trim((string) ($preview['video_path'] ?? ''));
                    $imgPath = trim((string) ($preview['preview_image_path'] ?? ''));
                    $isVideo = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                    $gridDate = $gridItem->scheduled_at ?: $gridItem->created_at;
                @endphp
                <a href="{{ route('posts.edit', $gridItem) }}"
                   class="feed-cell group relative aspect-square overflow-hidden rounded-xl bg-gray-100">
                    <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold text-gray-300">
                        {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </div>
                    @if($imgPath !== '')
                        <img src="{{ asset('storage/' . ltrim($imgPath, '/')) }}" alt="{{ $gridItem->title }}"
                             class="absolute inset-0 h-full w-full object-cover" loading="lazy" onerror="this.remove();">
                    @elseif($isVideo && $vidPath !== '')
                        <video class="absolute inset-0 h-full w-full object-cover pointer-events-none" muted playsinline preload="metadata">
                            <source src="{{ asset('storage/' . ltrim($vidPath, '/')) }}">
                        </video>
                    @elseif(!empty($gridItem->ai_image_path))
                        <img src="{{ asset('storage/' . ltrim($gridItem->ai_image_path, '/')) }}" alt="{{ $gridItem->title }}"
                             class="absolute inset-0 h-full w-full object-cover" loading="lazy" onerror="this.remove();">
                    @endif
                    <span class="absolute left-1.5 top-1.5 z-10 h-2 w-2 rounded-full ring-1 ring-white/80"
                          style="background:{{ $dotColor }};"></span>
                    @if($isVideo)
                        <span class="absolute right-1.5 top-1.5 z-10 rounded bg-black/60 px-1 py-0.5 text-[9px] font-bold text-white">▶</span>
                    @endif
                    <div class="absolute inset-0 z-10 flex flex-col justify-end bg-gradient-to-t from-black/75 via-black/10 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
                        <p class="truncate text-[10px] font-semibold text-white">{{ $gridItem->title ?: 'Post #'.$gridItem->id }}</p>
                        @if($gridDate)<p class="text-[9px] text-white/65">{{ $gridDate->format('d/m') }}</p>@endif
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════
     4. PIANO + AGENDA
══════════════════════════════════════ --}}
<div class="grid gap-4 lg:grid-cols-2 anim-3">

    {{-- Piano attivo --}}
    <div class="overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3"/>
                    </svg>
                </span>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-indigo-500">Piano attivo</p>
                    <p class="text-sm font-semibold text-gray-900">{{ $latestPlan ? $latestPlan->name : 'Nessun piano' }}</p>
                </div>
            </div>
            @if($latestPlan)
                <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}" class="shrink-0">
                    @csrf
                    <button class="btn-brand" style="padding:8px 16px;font-size:.8rem;">Genera</button>
                </form>
            @else
                <a href="{{ route('wizard.start') }}"
                   class="shrink-0 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">
                    + Crea piano
                </a>
            @endif
        </div>

        @if($latestPlan)
        <div class="space-y-4 px-6 py-5">
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">Avanzamento tempo</span>
                    <span class="text-xs font-bold text-indigo-600">{{ $planDaysElapsed }}/{{ $planDaysTotal }} gg · {{ $planTimeProgress }}%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-400 to-indigo-600 transition-all" style="width:{{ $planTimeProgress }}%"></div>
                </div>
            </div>
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">Contenuti completati</span>
                    <span class="text-xs font-bold text-emerald-600">{{ $planItemsDone }}/{{ $planItemsTotal }} · {{ $planOutputProgress }}%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-all" style="width:{{ $planOutputProgress }}%"></div>
                </div>
            </div>
            <div class="flex gap-2">
                <div class="flex-1 rounded-2xl bg-amber-50 px-3 py-3 text-center">
                    <p class="text-xl font-bold text-amber-700">{{ $planItemsQueued }}</p>
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-amber-600">In coda</p>
                </div>
                <div class="flex-1 rounded-2xl bg-emerald-50 px-3 py-3 text-center">
                    <p class="text-xl font-bold text-emerald-700">{{ $planItemsDone }}</p>
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-emerald-600">Pronti</p>
                </div>
                <div class="flex-1 rounded-2xl {{ $planItemsError > 0 ? 'bg-red-50' : 'bg-gray-50' }} px-3 py-3 text-center">
                    <p class="text-xl font-bold {{ $planItemsError > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $planItemsError }}</p>
                    <p class="text-[9px] font-semibold uppercase tracking-wide {{ $planItemsError > 0 ? 'text-red-600' : 'text-gray-400' }}">Errori</p>
                </div>
            </div>
        </div>
        @else
        <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <p class="text-sm text-gray-500">Crea un piano per vedere progressi e copertura del calendario.</p>
            <a href="{{ route('wizard.start') }}" class="btn-brand">Inizia ora</a>
        </div>
        @endif
    </div>

    {{-- Agenda --}}
    <div class="overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-500">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>
                    </svg>
                </span>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-sky-500">Agenda</p>
                    <p class="text-sm font-semibold text-gray-900">Flusso del giorno</p>
                </div>
            </div>
            <a href="{{ route('calendar') }}"
               class="shrink-0 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">
                Calendario
            </a>
        </div>

        <div class="px-6 py-5 space-y-3">
            <div class="grid grid-cols-2 gap-2.5">
                <div class="rounded-2xl bg-gray-50 px-4 py-3.5">
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-gray-400">Previsti oggi</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $todayItems->count() }}</p>
                </div>
                <div class="rounded-2xl {{ $todayPending > 0 ? 'bg-amber-50' : 'bg-emerald-50' }} px-4 py-3.5">
                    <p class="text-[9px] font-semibold uppercase tracking-wide {{ $todayPending > 0 ? 'text-amber-600' : 'text-emerald-600' }}">Da completare</p>
                    <p class="mt-1 text-2xl font-bold {{ $todayPending > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $todayPending }}</p>
                </div>
                <div class="rounded-2xl bg-gray-50 px-4 py-3.5">
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-gray-400">Settimana</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $weekPlanned }}</p>
                </div>
                <div class="rounded-2xl bg-emerald-50 px-4 py-3.5">
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-emerald-600">Pubblicati</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-700">{{ $weekPublished }}</p>
                </div>
            </div>

            @if($nextItem?->scheduled_at)
            <div class="flex items-center gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-100">
                    <svg class="h-4 w-4 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-indigo-500">Prossimo in agenda</p>
                    <p class="mt-0.5 truncate text-sm font-semibold text-gray-900">{{ $nextItem->title ?: 'Senza titolo' }}</p>
                    <p class="text-xs font-medium text-indigo-600">{{ $nextItem->scheduled_at->format('d/m · H:i') }}</p>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-2.5 pt-1">
                <a href="{{ route('posts.create') }}" class="btn-brand justify-center">Crea contenuto</a>
                <a href="{{ route('posts.create') }}?preset=reel" class="btn-outline justify-center">Crea reel</a>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════
     5. ULTIMI CONTENUTI
══════════════════════════════════════ --}}
<div class="anim-4">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold text-gray-700">Ultimi contenuti</h2>
        <a href="{{ route('posts.index') }}"
           class="inline-flex items-center gap-1 text-xs font-semibold text-gray-400 transition hover:text-gray-700">
            Vedi tutti
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
            </svg>
        </a>
    </div>

    @if($recentItems->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-[2rem] border border-dashed border-gray-200 bg-white py-12 text-center">
            <p class="text-sm text-gray-500">Nessun contenuto ancora.</p>
            <a href="{{ route('posts.create') }}" class="btn-brand">Crea il primo</a>
        </div>
    @else
        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($recentItems as $item)
            @php
                $aiInfo = $uiAi($item->ai_status);
                $pKey   = strtolower(trim((string)($item->platform ?? '')));
                $platformMeta = [
                    'instagram' => ['label'=>'IG', 'bg'=>'#fce7f3', 'color'=>'#be185d'],
                    'facebook'  => ['label'=>'FB', 'bg'=>'#dbeafe', 'color'=>'#1d4ed8'],
                    'tiktok'    => ['label'=>'TK', 'bg'=>'#1f2937', 'color'=>'#fff'],
                    'youtube'   => ['label'=>'YT', 'bg'=>'#fee2e2', 'color'=>'#b91c1c'],
                    'linkedin'  => ['label'=>'LI', 'bg'=>'#e0f2fe', 'color'=>'#0369a1'],
                    'twitter'   => ['label'=>'TW', 'bg'=>'#e0f2fe', 'color'=>'#0369a1'],
                    'pinterest' => ['label'=>'PI', 'bg'=>'#ffe4e6', 'color'=>'#be123c'],
                ];
                $pm = $platformMeta[$pKey] ?? ['label'=>strtoupper(substr($pKey?:'?',0,2)), 'bg'=>'#f3f4f6', 'color'=>'#374151'];

                $preview = is_array($item->media_preview ?? null) ? $item->media_preview : [];
                $vidPath = trim((string) ($preview['video_path'] ?? ''));
                $imgPath = trim((string) ($preview['preview_image_path'] ?? ''));
                $isVideo = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                $hasThumb = $imgPath !== '' || ($isVideo && $vidPath !== '') || !empty($item->ai_image_path);

                $toneStyle = match($aiInfo['tone'] ?? 'neutral') {
                    'success' => 'border-color:#bbf7d0;background:#f0fdf4;color:#15803d;',
                    'info'    => 'border-color:#c7d2fe;background:#eef2ff;color:#4338ca;',
                    'warning' => 'border-color:#fde68a;background:#fffbeb;color:#b45309;',
                    'danger'  => 'border-color:#fecaca;background:#fef2f2;color:#b91c1c;',
                    default   => 'border-color:#e5e7eb;background:#f9fafb;color:#6b7280;',
                };
            @endphp
            <a href="{{ route('posts.edit', $item) }}"
               class="content-card flex items-center gap-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                {{-- Thumbnail --}}
                <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl" style="background:#f3f4f6;">
                    @if($imgPath !== '')
                        <img src="{{ asset('storage/' . ltrim($imgPath, '/')) }}" alt=""
                             class="h-full w-full object-cover" loading="lazy" onerror="this.remove();">
                    @elseif($isVideo && $vidPath !== '')
                        <video class="h-full w-full object-cover pointer-events-none" muted playsinline preload="metadata">
                            <source src="{{ asset('storage/' . ltrim($vidPath, '/')) }}">
                        </video>
                    @elseif(!empty($item->ai_image_path))
                        <img src="{{ asset('storage/' . ltrim($item->ai_image_path, '/')) }}" alt=""
                             class="h-full w-full object-cover" loading="lazy" onerror="this.remove();">
                    @endif
                    @if(!$hasThumb)
                        <div class="absolute inset-0 flex items-center justify-center rounded-xl text-xs font-bold"
                             style="background:{{ $pm['bg'] }};color:{{ $pm['color'] }};">
                            {{ $pm['label'] }}
                        </div>
                    @else
                        <span class="absolute bottom-1 left-1 rounded-md px-1 py-px text-[8px] font-bold leading-none"
                              style="background:{{ $pm['bg'] }};color:{{ $pm['color'] }};">
                            {{ $pm['label'] }}
                        </span>
                    @endif
                    @if($isVideo)
                        <span class="absolute right-1 top-1 rounded bg-black/60 px-1 py-px text-[8px] font-bold text-white">▶</span>
                    @endif
                </div>
                {{-- Info --}}
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-gray-900">{{ $item->title ?: 'Senza titolo' }}</p>
                    <p class="mt-0.5 text-xs text-gray-400">
                        {{ strtoupper((string)$item->format) }}@if($item->scheduled_at) · {{ $item->scheduled_at->format('d/m') }}@endif
                    </p>
                    <span class="mt-2 inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-semibold"
                          style="{{ $toneStyle }}">
                        {{ $aiInfo['label'] }}
                    </span>
                </div>
                <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
            </a>
            @endforeach
        </div>
    @endif
</div>

</div>{{-- space-y-5 --}}
</div>{{-- container --}}
@endsection
