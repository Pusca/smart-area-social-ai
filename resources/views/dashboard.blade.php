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

    .dash-card { background:#fff; border:1px solid #D0DCF0; border-radius:1.75rem; box-shadow:0 2px 12px rgba(7,24,63,.06); }

    .stat-hover { transition: box-shadow .18s, transform .18s; }
    .stat-hover:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(10,45,111,.12); }

    .feed-cell { transition: transform .2s, box-shadow .2s; }
    .feed-cell:hover { transform: scale(1.04); box-shadow: 0 8px 28px rgba(15,23,42,.18); }

    .content-card { background:#fff; border:1px solid #D0DCF0; border-radius:1.25rem; box-shadow:0 2px 10px rgba(7,24,63,.05); transition: box-shadow .15s, transform .15s; }
    .content-card:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(10,45,111,.10); text-decoration:none; }

    .btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        background: linear-gradient(135deg, #071D4E 0%, #0A2D6F 58%, #2DBAF7 100%);
        color: #fff; border-radius: 1rem; padding: 10px 20px;
        font-size: .875rem; font-weight: 600;
        box-shadow: 0 2px 12px rgba(10,45,111,.28);
        transition: opacity .15s, box-shadow .15s;
        text-decoration: none; border: none; cursor: pointer;
    }
    .btn-primary:hover { opacity: .9; box-shadow: 0 4px 18px rgba(10,45,111,.36); color:#fff; }

    .btn-secondary {
        display: inline-flex; align-items: center; gap: 8px;
        background: #fff; color: #07183F;
        border: 1px solid #D0DCF0; border-radius: 1rem; padding: 10px 20px;
        font-size: .875rem; font-weight: 600;
        box-shadow: 0 1px 4px rgba(0,0,0,.04);
        transition: background .15s, border-color .15s;
        text-decoration: none;
    }
    .btn-secondary:hover { background: #f4f7fc; border-color: #b0c4e0; color:#07183F; }

    .brand-icon-box {
        display:flex; align-items:center; justify-content:center;
        border-radius:.875rem; width:2.25rem; height:2.25rem; flex-shrink:0;
        background: linear-gradient(135deg, #0A2D6F, #1498F3);
    }
    .accent-icon-box {
        display:flex; align-items:center; justify-content:center;
        border-radius:.875rem; width:2.25rem; height:2.25rem; flex-shrink:0;
        background: linear-gradient(135deg, #1498F3, #3BC8FF);
    }
</style>

<div class="w-full overflow-x-hidden px-4 py-6 pb-28 sm:px-6 lg:px-8" style="max-width:1280px;margin:0 auto;">
<div class="space-y-5">

{{-- ══════════════════════════════════════
     BANNER WORKSPACE (dismissibile)
══════════════════════════════════════ --}}
<div x-data="{ open: true }" x-show="open" x-transition.opacity class="anim-0">
    <div class="flex items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-sm font-medium {{ $scoreBadgeClass }}">
        <div class="flex items-center gap-2">
            <x-application-logo variant="icon" class="h-4 w-4 shrink-0" />
            <span>Workspace <strong>{{ $scoreLabel }}</strong></span>
        </div>
        <button @click="open = false" class="shrink-0 rounded-lg p-1 opacity-60 transition hover:opacity-100">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</div>

{{-- ══════════════════════════════════════
     1. HEADER
══════════════════════════════════════ --}}
<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between anim-1">
    <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl" style="color:#07183F;">
            Ciao {{ $u?->name }} 👋
        </h1>
        <p class="mt-1 text-sm" style="color:#566783;">
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
        <a href="{{ route('posts.create') }}" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Crea
        </a>
        <a href="{{ route('wizard.start') }}" class="btn-secondary">Piano</a>
    </div>
</div>

{{-- ══════════════════════════════════════
     2. STATS — 2×2 mobile, 4 col desktop
══════════════════════════════════════ --}}
<div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-3 anim-2">

    {{-- Generati --}}
    <div class="stat-hover dash-card overflow-hidden">
        <div class="px-5 py-5 sm:px-5 sm:py-5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#566783;">Generati</p>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl" style="background:#EEF4FE;">
                    <svg class="h-4 w-4" style="color:#0A2D6F;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/>
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl" style="color:#07183F;">{{ $doneItems }}</p>
            <p class="mt-1 text-xs" style="color:#566783;">di {{ $totalItems }} totali</p>
        </div>
        <div class="h-1.5 w-full" style="background:#EEF4FE;">
            <div class="h-full" style="width:{{ $aiCompletion }}%;background:linear-gradient(90deg,#0A2D6F,#3BC8FF);"></div>
        </div>
    </div>

    {{-- Pubblicati --}}
    <div class="stat-hover dash-card overflow-hidden">
        <div class="px-5 py-5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#566783;">Pubblicati</p>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl" style="background:#ecfdf5;">
                    <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl" style="color:#07183F;">{{ $publishedItems }}</p>
            <p class="mt-1 text-xs" style="color:#566783;">{{ $publishRate }}% del totale</p>
        </div>
        <div class="h-1.5 w-full bg-gray-100">
            <div class="h-full bg-emerald-500" style="width:{{ $publishRate }}%"></div>
        </div>
    </div>

    {{-- In coda --}}
    <div class="stat-hover dash-card overflow-hidden">
        <div class="px-5 py-5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#566783;">In coda</p>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl" style="background:#fffbeb;">
                    <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl" style="color:{{ $queuedItems > 0 ? '#b45309' : '#07183F' }};">{{ $queuedItems }}</p>
            <p class="mt-1 text-xs" style="color:#566783;">in generazione</p>
        </div>
        <div class="h-1.5 w-full bg-gray-100">
            <div class="h-full bg-amber-400" style="width:{{ min(100, $queueRate) }}%"></div>
        </div>
    </div>

    {{-- Errori --}}
    <div class="stat-hover dash-card overflow-hidden">
        <div class="px-5 py-5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#566783;">Errori</p>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl" style="background:{{ $errorItems > 0 ? '#fef2f2' : '#f9fafb' }};">
                    <svg class="h-4 w-4" style="color:{{ $errorItems > 0 ? '#dc2626' : '#9ca3af' }};" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl" style="color:{{ $errorItems > 0 ? '#dc2626' : '#07183F' }};">{{ $errorItems }}</p>
            <p class="mt-1 text-xs" style="color:#566783;">{{ $errorItems > 0 ? 'da verificare' : 'tutto ok' }}</p>
        </div>
        <div class="h-1.5 w-full bg-gray-100">
            <div class="h-full" style="width:{{ max(4, $errorRate) }}%;background:{{ $errorItems > 0 ? '#dc2626' : '#e5e7eb' }};"></div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════
     3. FEED
══════════════════════════════════════ --}}
<div class="overflow-hidden anim-3" style="border-radius:2rem;border:1px solid #D0DCF0;box-shadow:0 2px 12px rgba(7,24,63,.06);">

    {{-- Header con gradiente brand --}}
    <div style="background:linear-gradient(135deg,#071D4E 0%,#0A2D6F 58%,#2DBAF7 100%);padding:2.5rem 1.5rem;">
        <div class="flex items-center justify-between gap-4">
            <p style="font-size:1rem;font-weight:700;color:#fff;">Feed visivo</p>
            <div class="flex items-center gap-2">
                <span style="background:rgba(255,255,255,.15);color:rgba(255,255,255,.9);font-size:.7rem;font-weight:700;padding:4px 12px;border-radius:999px;">
                    {{ $instagramFeedItems->count() }} post
                </span>
                <a href="{{ route('posts.index') }}"
                   style="background:rgba(255,255,255,.2);color:#fff;font-size:.7rem;font-weight:700;padding:5px 14px;border-radius:999px;text-decoration:none;transition:background .15s;"
                   onmouseover="this.style.background='rgba(255,255,255,.32)'"
                   onmouseout="this.style.background='rgba(255,255,255,.2)'">
                    Libreria →
                </a>
            </div>
        </div>
    </div>

    {{-- Grid --}}
    <div class="bg-white p-4">
        @if($instagramFeedItems->isEmpty())
            <div class="flex flex-col items-center gap-3 rounded-2xl border border-dashed py-12 text-center" style="border-color:#D0DCF0;background:#F4F7FC;">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl" style="background:#EEF4FE;">
                    <svg class="h-6 w-6" style="color:#0A2D6F;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium" style="color:#566783;">Nessun contenuto ancora.</p>
                <a href="{{ route('posts.create') }}" class="btn-primary">Crea il primo</a>
            </div>
        @else
            <div class="grid grid-cols-3 gap-1.5">
                @foreach($instagramFeedItems as $gridItem)
                @php
                    $gridPubInfo = $uiPublication($gridItem->status);
                    $dotColor   = match($gridPubInfo['tone'] ?? 'neutral') {
                        'success' => '#10b981', 'info' => '#0A2D6F',
                        'danger'  => '#dc2626', default => '#9ca3af',
                    };
                    $preview = is_array($gridItem->media_preview ?? null) ? $gridItem->media_preview : [];
                    $vidPath = trim((string) ($preview['video_path'] ?? ''));
                    $imgPath = trim((string) ($preview['preview_image_path'] ?? ''));
                    $isVideo = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                    $gridDate = $gridItem->scheduled_at ?: $gridItem->created_at;
                @endphp
                <a href="{{ route('posts.edit', $gridItem) }}"
                   class="feed-cell group relative aspect-square overflow-hidden rounded-xl" style="background:#EEF4FE;">
                    <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold" style="color:#b0c4e0;">
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
    <div class="dash-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 px-6 py-6" style="border-bottom:1px solid #D0DCF0;">
            <div class="flex items-center gap-3">
                <span class="brand-icon-box">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3"/>
                    </svg>
                </span>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest" style="color:#1498F3;">Piano attivo</p>
                    <p class="text-sm font-semibold" style="color:#07183F;">{{ $latestPlan ? $latestPlan->name : 'Nessun piano' }}</p>
                </div>
            </div>
            @if($latestPlan)
                <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}" class="shrink-0">
                    @csrf
                    <button class="btn-primary" style="padding:8px 18px;font-size:.8rem;">Genera</button>
                </form>
            @else
                <a href="{{ route('wizard.start') }}" class="btn-secondary shrink-0" style="padding:8px 16px;font-size:.8rem;">+ Crea piano</a>
            @endif
        </div>

        @if($latestPlan)
        <div class="space-y-5 px-6 py-6">
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium" style="color:#566783;">Avanzamento tempo</span>
                    <span class="text-xs font-bold" style="color:#0A2D6F;">{{ $planDaysElapsed }}/{{ $planDaysTotal }} gg &middot; {{ $planTimeProgress }}%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full" style="background:#EEF4FE;">
                    <div class="h-full rounded-full transition-all" style="width:{{ $planTimeProgress }}%;background:linear-gradient(90deg,#0A2D6F,#3BC8FF);"></div>
                </div>
            </div>
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium" style="color:#566783;">Contenuti completati</span>
                    <span class="text-xs font-bold text-emerald-600">{{ $planItemsDone }}/{{ $planItemsTotal }} &middot; {{ $planOutputProgress }}%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" style="width:{{ $planOutputProgress }}%"></div>
                </div>
            </div>
            <div class="flex gap-3">
                <div class="flex-1 rounded-2xl px-3 py-4 text-center" style="background:#fffbeb;">
                    <p class="text-2xl font-bold" style="color:#b45309;">{{ $planItemsQueued }}</p>
                    <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-wide" style="color:#d97706;">In coda</p>
                </div>
                <div class="flex-1 rounded-2xl px-3 py-4 text-center" style="background:#ecfdf5;">
                    <p class="text-2xl font-bold text-emerald-700">{{ $planItemsDone }}</p>
                    <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-600">Pronti</p>
                </div>
                <div class="flex-1 rounded-2xl px-3 py-4 text-center" style="background:{{ $planItemsError > 0 ? '#fef2f2' : '#f9fafb' }};">
                    <p class="text-2xl font-bold" style="color:{{ $planItemsError > 0 ? '#dc2626' : '#9ca3af' }};">{{ $planItemsError }}</p>
                    <p class="mt-0.5 text-[9px] font-semibold uppercase tracking-wide" style="color:{{ $planItemsError > 0 ? '#ef4444' : '#9ca3af' }};">Errori</p>
                </div>
            </div>
        </div>
        @else
        <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <p class="text-sm" style="color:#566783;">Crea un piano per vedere progressi e copertura del calendario.</p>
            <a href="{{ route('wizard.start') }}" class="btn-primary">Inizia ora</a>
        </div>
        @endif
    </div>

    {{-- Agenda --}}
    <div class="dash-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 px-6 py-6" style="border-bottom:1px solid #D0DCF0;">
            <div class="flex items-center gap-3">
                <span class="accent-icon-box">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>
                    </svg>
                </span>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest" style="color:#1498F3;">Agenda</p>
                    <p class="text-sm font-semibold" style="color:#07183F;">Flusso del giorno</p>
                </div>
            </div>
            <a href="{{ route('calendar') }}" class="btn-secondary shrink-0" style="padding:8px 16px;font-size:.8rem;">Calendario</a>
        </div>

        <div class="px-6 py-6 space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-2xl px-4 py-4" style="background:#EEF4FE;">
                    <p class="text-[9px] font-semibold uppercase tracking-wide" style="color:#566783;">Previsti oggi</p>
                    <p class="mt-1.5 text-2xl font-bold" style="color:#07183F;">{{ $todayItems->count() }}</p>
                </div>
                <div class="rounded-2xl px-4 py-4" style="background:{{ $todayPending > 0 ? '#fffbeb' : '#ecfdf5' }};">
                    <p class="text-[9px] font-semibold uppercase tracking-wide" style="color:{{ $todayPending > 0 ? '#d97706' : '#059669' }};">Da completare</p>
                    <p class="mt-1.5 text-2xl font-bold" style="color:{{ $todayPending > 0 ? '#b45309' : '#047857' }};">{{ $todayPending }}</p>
                </div>
                <div class="rounded-2xl px-4 py-4" style="background:#EEF4FE;">
                    <p class="text-[9px] font-semibold uppercase tracking-wide" style="color:#566783;">Settimana</p>
                    <p class="mt-1.5 text-2xl font-bold" style="color:#07183F;">{{ $weekPlanned }}</p>
                </div>
                <div class="rounded-2xl px-4 py-4" style="background:#ecfdf5;">
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-emerald-600">Pubblicati</p>
                    <p class="mt-1.5 text-2xl font-bold text-emerald-700">{{ $weekPublished }}</p>
                </div>
            </div>

            @if($nextItem?->scheduled_at)
            <div class="flex items-center gap-3 rounded-2xl px-4 py-4" style="background:#EEF4FE;border:1px solid #D0DCF0;">
                <span class="brand-icon-box shrink-0">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-[9px] font-semibold uppercase tracking-wide" style="color:#1498F3;">Prossimo in agenda</p>
                    <p class="mt-0.5 truncate text-sm font-semibold" style="color:#07183F;">{{ $nextItem->title ?: 'Senza titolo' }}</p>
                    <p class="text-xs font-medium" style="color:#0A2D6F;">{{ $nextItem->scheduled_at->format('d/m · H:i') }}</p>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-3 pt-1">
                <a href="{{ route('posts.create') }}" class="btn-primary justify-center">Crea contenuto</a>
                <a href="{{ route('posts.create') }}?preset=reel" class="btn-secondary justify-center">Crea reel</a>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════
     5. ULTIMI CONTENUTI
══════════════════════════════════════ --}}
<div class="anim-4">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold" style="color:#07183F;">Ultimi contenuti</h2>
        <a href="{{ route('posts.index') }}"
           class="inline-flex items-center gap-1 text-xs font-semibold transition hover:opacity-80" style="color:#566783;">
            Vedi tutti
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
            </svg>
        </a>
    </div>

    @if($recentItems->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-[2rem] border border-dashed py-12 text-center" style="border-color:#D0DCF0;background:#fff;">
            <p class="text-sm" style="color:#566783;">Nessun contenuto ancora.</p>
            <a href="{{ route('posts.create') }}" class="btn-primary">Crea il primo</a>
        </div>
    @else
        {{-- Mobile: 1 colonna. sm: 2 col. lg: 3 col. --}}
        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
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
                $pm = $platformMeta[$pKey] ?? ['label'=>strtoupper(substr($pKey?:'?',0,2)), 'bg'=>'#EEF4FE', 'color'=>'#0A2D6F'];

                $preview = is_array($item->media_preview ?? null) ? $item->media_preview : [];
                $vidPath = trim((string) ($preview['video_path'] ?? ''));
                $imgPath = trim((string) ($preview['preview_image_path'] ?? ''));
                $isVideo = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                $hasThumb = $imgPath !== '' || ($isVideo && $vidPath !== '') || !empty($item->ai_image_path);

                $toneStyle = match($aiInfo['tone'] ?? 'neutral') {
                    'success' => 'border-color:#bbf7d0;background:#f0fdf4;color:#15803d;',
                    'info'    => 'border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;',
                    'warning' => 'border-color:#fde68a;background:#fffbeb;color:#b45309;',
                    'danger'  => 'border-color:#fecaca;background:#fef2f2;color:#b91c1c;',
                    default   => 'border-color:#D0DCF0;background:#F4F7FC;color:#566783;',
                };
            @endphp
            <a href="{{ route('posts.edit', $item) }}" class="content-card flex items-center gap-4 p-4">
                {{-- Thumbnail --}}
                <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl" style="background:#EEF4FE;">
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
                        <span class="absolute bottom-1 left-1 rounded px-1 py-px text-[8px] font-bold leading-none"
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
                    <p class="truncate text-sm font-semibold" style="color:#07183F;">{{ $item->title ?: 'Senza titolo' }}</p>
                    <p class="mt-0.5 text-xs" style="color:#566783;">
                        {{ strtoupper((string)$item->format) }}@if($item->scheduled_at) &middot; {{ $item->scheduled_at->format('d/m') }}@endif
                    </p>
                    <span class="mt-2 inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-semibold"
                          style="{{ $toneStyle }}">
                        {{ $aiInfo['label'] }}
                    </span>
                </div>
                <svg class="h-4 w-4 shrink-0" style="color:#b0c4e0;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
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
