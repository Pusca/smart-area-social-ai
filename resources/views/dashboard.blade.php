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
    .anim-0 { animation: fade-up .38s ease both; }
    .anim-1 { animation: fade-up .38s .07s ease both; }
    .anim-2 { animation: fade-up .38s .14s ease both; }
    .anim-3 { animation: fade-up .38s .21s ease both; }
    .anim-4 { animation: fade-up .38s .28s ease both; }

    .stat-card { transition: box-shadow .18s ease, transform .18s ease; }
    .stat-card:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(15,23,42,.10); }

    .feed-cell { transition: transform .2s ease, box-shadow .2s ease; }
    .feed-cell:hover { transform: scale(1.04); box-shadow: 0 8px 28px rgba(15,23,42,.18); }

    .row-item { transition: background .15s ease; }
    .row-item:hover { background: rgba(241,245,255,.7); }
</style>

<div class="w-full px-4 py-6 sm:px-6 lg:px-8 pb-28" style="max-width:1280px;margin:0 auto;">
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
        <a href="{{ route('posts.create') }}"
           class="inline-flex items-center gap-2 rounded-2xl bg-gray-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-800">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Crea
        </a>
        <a href="{{ route('wizard.start') }}"
           class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:border-gray-300 hover:bg-gray-50">
            Piano
        </a>
    </div>
</div>

{{-- ══════════════════════════════════════
     2. STATS — 4 colonne, 1 riga
══════════════════════════════════════ --}}
<div class="grid grid-cols-4 gap-2 sm:gap-3 anim-1">

    {{-- Generati --}}
    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-3 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-indigo-50 sm:h-8 sm:w-8">
                <svg class="h-3.5 w-3.5 text-indigo-600 sm:h-4 sm:w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
            </span>
            <p class="hidden text-[10px] font-semibold uppercase tracking-widest text-gray-500 sm:block">Generati</p>
        </div>
        <p class="mt-2 text-2xl font-bold text-gray-900 sm:text-3xl">{{ $doneItems }}</p>
        <p class="mt-0.5 hidden text-xs text-gray-500 sm:block">di {{ $totalItems }} totali</p>
        <div class="mt-2.5 hidden sm:block">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-indigo-500 transition-all" style="width:{{ $aiCompletion }}%"></div>
            </div>
        </div>
        <p class="mt-1 text-[9px] font-bold text-indigo-600 sm:hidden">{{ $aiCompletion }}%</p>
    </div>

    {{-- Pubblicati --}}
    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-3 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-emerald-50 sm:h-8 sm:w-8">
                <svg class="h-3.5 w-3.5 text-emerald-600 sm:h-4 sm:w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </span>
            <p class="hidden text-[10px] font-semibold uppercase tracking-widest text-gray-500 sm:block">Pubblicati</p>
        </div>
        <p class="mt-2 text-2xl font-bold text-gray-900 sm:text-3xl">{{ $publishedItems }}</p>
        <p class="mt-0.5 hidden text-xs text-gray-500 sm:block">{{ $publishRate }}% del totale</p>
        <div class="mt-2.5 hidden sm:block">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-emerald-500 transition-all" style="width:{{ $publishRate }}%"></div>
            </div>
        </div>
        <p class="mt-1 text-[9px] font-bold text-emerald-600 sm:hidden">{{ $publishRate }}%</p>
    </div>

    {{-- In coda --}}
    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-3 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-amber-50 sm:h-8 sm:w-8">
                <svg class="h-3.5 w-3.5 text-amber-600 sm:h-4 sm:w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </span>
            <p class="hidden text-[10px] font-semibold uppercase tracking-widest text-gray-500 sm:block">In coda</p>
        </div>
        <p class="mt-2 text-2xl font-bold {{ $queuedItems > 0 ? 'text-amber-600' : 'text-gray-900' }} sm:text-3xl">{{ $queuedItems }}</p>
        <p class="mt-0.5 hidden text-xs text-gray-500 sm:block">In generazione</p>
        <div class="mt-2.5 hidden sm:block">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-amber-400 transition-all" style="width:{{ min(100, $queueRate) }}%"></div>
            </div>
        </div>
        <p class="mt-1 text-[9px] font-bold text-amber-600 sm:hidden">attivi</p>
    </div>

    {{-- Errori --}}
    <div class="stat-card rounded-2xl border border-gray-100 bg-white p-3 shadow-sm sm:rounded-[1.5rem] sm:p-5">
        <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl {{ $errorItems > 0 ? 'bg-red-50' : 'bg-gray-50' }} sm:h-8 sm:w-8">
                <svg class="h-3.5 w-3.5 {{ $errorItems > 0 ? 'text-red-500' : 'text-gray-400' }} sm:h-4 sm:w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </span>
            <p class="hidden text-[10px] font-semibold uppercase tracking-widest text-gray-500 sm:block">Errori</p>
        </div>
        <p class="mt-2 text-2xl font-bold {{ $errorItems > 0 ? 'text-red-600' : 'text-gray-900' }} sm:text-3xl">{{ $errorItems }}</p>
        <p class="mt-0.5 hidden text-xs text-gray-500 sm:block">{{ $errorItems > 0 ? 'Da verificare' : 'Tutto ok' }}</p>
        <div class="mt-2.5 hidden sm:block">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full {{ $errorItems > 0 ? 'bg-red-500' : 'bg-gray-200' }} transition-all" style="width:{{ max(4, $errorRate) }}%"></div>
            </div>
        </div>
        <p class="mt-1 text-[9px] font-bold {{ $errorItems > 0 ? 'text-red-600' : 'text-gray-400' }} sm:hidden">
            {{ $errorItems > 0 ? 'rivedere' : 'ok' }}
        </p>
    </div>

</div>

{{-- ══════════════════════════════════════
     3. FEED — in alto, visivo e prominente
══════════════════════════════════════ --}}
<div class="overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-sm anim-2">
    {{-- Header scuro con contrasto garantito --}}
    <div class="flex items-center justify-between gap-4 bg-gray-950 px-5 py-4">
        <div class="min-w-0 flex items-center gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white/10">
                <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z"/></svg>
            </span>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/50">Feed preview</p>
                <p class="text-sm font-semibold text-white">Anteprima piano visivo</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/80">
                {{ $instagramFeedItems->count() }} post
            </span>
            <a href="{{ route('posts.index') }}"
               class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white transition hover:bg-white/25">
                Libreria →
            </a>
        </div>
    </div>

    {{-- Grid foto --}}
    <div class="p-3 sm:p-4">
        @if($instagramFeedItems->isEmpty())
            <div class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-gray-200 bg-gray-50 py-12 text-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100">
                    <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                </div>
                <p class="text-sm font-medium text-gray-600">Nessun contenuto ancora.</p>
                <a href="{{ route('posts.create') }}" class="rounded-xl bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Crea il primo</a>
            </div>
        @else
            <div class="grid grid-cols-3 gap-1 sm:gap-1.5">
                @foreach($instagramFeedItems as $gridItem)
                @php
                    $gridDate     = $gridItem->scheduled_at ?: $gridItem->created_at;
                    $gridDateLabel = $gridDate ? $gridDate->format('d/m H:i') : '';
                    $gridPubInfo  = $uiPublication($gridItem->status);
                    $dotColor     = match($gridPubInfo['tone'] ?? 'neutral') {
                        'success' => 'bg-emerald-400',
                        'info'    => 'bg-indigo-400',
                        'danger'  => 'bg-red-400',
                        default   => 'bg-gray-300',
                    };
                    $preview  = is_array($gridItem->media_preview ?? null) ? $gridItem->media_preview : [];
                    $vidPath  = trim((string) ($preview['video_path'] ?? ''));
                    $imgPath  = trim((string) ($preview['preview_image_path'] ?? ''));
                    $isVideo  = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                @endphp
                <a href="{{ route('posts.edit', $gridItem) }}"
                   class="feed-cell group relative aspect-square overflow-hidden rounded-xl bg-gray-100">
                    <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold text-gray-300">
                        {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </div>

                    @if($imgPath !== '')
                        <img src="{{ asset('storage/' . ltrim($imgPath, '/')) }}"
                             alt="{{ $gridItem->title }}"
                             class="absolute inset-0 h-full w-full object-cover"
                             loading="lazy" onerror="this.remove();">
                    @elseif($isVideo && $vidPath !== '')
                        <video class="absolute inset-0 h-full w-full object-cover pointer-events-none"
                               muted playsinline preload="metadata">
                            <source src="{{ asset('storage/' . ltrim($vidPath, '/')) }}">
                        </video>
                    @elseif(!empty($gridItem->ai_image_path))
                        <img src="{{ asset('storage/' . ltrim($gridItem->ai_image_path, '/')) }}"
                             alt="{{ $gridItem->title }}"
                             class="absolute inset-0 h-full w-full object-cover"
                             loading="lazy" onerror="this.remove();">
                    @endif

                    <span class="absolute left-1.5 top-1.5 z-10 h-2 w-2 rounded-full {{ $dotColor }} ring-1 ring-white/80"></span>

                    @if($isVideo)
                        <span class="absolute right-1.5 top-1.5 z-10 rounded-md bg-black/60 px-1 py-0.5 text-[9px] font-bold text-white">▶</span>
                    @endif

                    <div class="absolute inset-0 z-10 flex flex-col justify-end bg-gradient-to-t from-black/75 via-black/20 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
                        <p class="truncate text-[10px] font-semibold leading-tight text-white">{{ $gridItem->title ?: 'Post #'.$gridItem->id }}</p>
                        @if($gridDateLabel)
                            <p class="text-[9px] text-white/70">{{ $gridDateLabel }}</p>
                        @endif
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
        {{-- Header colorato --}}
        <div class="border-b border-gray-100 bg-gradient-to-r from-indigo-50 to-violet-50 px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600">
                        <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5"/></svg>
                    </span>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-indigo-600">Piano attivo</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $latestPlan ? $latestPlan->name : 'Nessun piano' }}</p>
                    </div>
                </div>
                @if($latestPlan)
                    <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}">
                        @csrf
                        <button class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700">
                            Genera
                        </button>
                    </form>
                @else
                    <a href="{{ route('wizard.start') }}"
                       class="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50">
                        Crea piano
                    </a>
                @endif
            </div>
        </div>

        <div class="p-5">
            @if($latestPlan)
                <div class="space-y-3">
                    {{-- Avanzamento tempo --}}
                    <div class="rounded-2xl bg-gray-50 px-4 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-medium text-gray-600">Avanzamento tempo</p>
                            <span class="text-xs font-bold text-indigo-600">{{ $planTimeProgress }}%</span>
                        </div>
                        <p class="mt-0.5 text-sm font-semibold text-gray-900">{{ $planDaysElapsed }} / {{ $planDaysTotal }} giorni</p>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-indigo-500 transition-all" style="width:{{ $planTimeProgress }}%"></div>
                        </div>
                    </div>

                    {{-- Output completati --}}
                    <div class="rounded-2xl bg-gray-50 px-4 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-medium text-gray-600">Output completati</p>
                            <span class="text-xs font-bold text-emerald-600">{{ $planOutputProgress }}%</span>
                        </div>
                        <p class="mt-0.5 text-sm font-semibold text-gray-900">{{ $planItemsDone }} / {{ $planItemsTotal }} contenuti</p>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-emerald-500 transition-all" style="width:{{ $planOutputProgress }}%"></div>
                        </div>
                    </div>

                    {{-- Mini-stats --}}
                    <div class="grid grid-cols-3 gap-2">
                        <div class="rounded-xl bg-amber-50 px-3 py-2.5 text-center">
                            <p class="text-[9px] font-semibold uppercase tracking-wide text-amber-700">In coda</p>
                            <p class="mt-0.5 text-lg font-bold text-amber-700">{{ $planItemsQueued }}</p>
                        </div>
                        <div class="rounded-xl bg-emerald-50 px-3 py-2.5 text-center">
                            <p class="text-[9px] font-semibold uppercase tracking-wide text-emerald-700">Pronti</p>
                            <p class="mt-0.5 text-lg font-bold text-emerald-700">{{ $planItemsDone }}</p>
                        </div>
                        <div class="rounded-xl {{ $planItemsError > 0 ? 'bg-red-50' : 'bg-gray-50' }} px-3 py-2.5 text-center">
                            <p class="text-[9px] font-semibold uppercase tracking-wide {{ $planItemsError > 0 ? 'text-red-700' : 'text-gray-500' }}">Errori</p>
                            <p class="mt-0.5 text-lg font-bold {{ $planItemsError > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ $planItemsError }}</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-5 py-10 text-center">
                    <p class="text-sm text-gray-500">Crea un piano per vedere progressi e copertura del calendario.</p>
                    <a href="{{ route('wizard.start') }}"
                       class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                        Inizia ora
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Agenda --}}
    <div class="overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-sm">
        {{-- Header colorato --}}
        <div class="border-b border-gray-100 bg-gradient-to-r from-sky-50 to-cyan-50 px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-600">
                        <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    </span>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-sky-600">Agenda</p>
                        <p class="text-sm font-semibold text-gray-900">Flusso del giorno</p>
                    </div>
                </div>
                <a href="{{ route('calendar') }}"
                   class="rounded-xl border border-sky-200 bg-white px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-50">
                    Calendario
                </a>
            </div>
        </div>

        <div class="p-5">
            <div class="space-y-2">
                {{-- Riga --}}
                <div class="row-item flex items-center justify-between rounded-2xl px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-2 w-2 rounded-full bg-gray-300"></span>
                        <p class="text-sm text-gray-600">Previsti oggi</p>
                    </div>
                    <p class="text-base font-bold text-gray-900">{{ $todayItems->count() }}</p>
                </div>
                <div class="row-item flex items-center justify-between rounded-2xl px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-2 w-2 rounded-full {{ $todayPending > 0 ? 'bg-amber-400' : 'bg-emerald-400' }}"></span>
                        <p class="text-sm text-gray-600">Da completare oggi</p>
                    </div>
                    <p class="text-base font-bold {{ $todayPending > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $todayPending }}</p>
                </div>
                <div class="row-item flex items-center justify-between rounded-2xl px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-2 w-2 rounded-full bg-indigo-300"></span>
                        <p class="text-sm text-gray-600">In calendario questa settimana</p>
                    </div>
                    <p class="text-base font-bold text-gray-900">{{ $weekPlanned }}</p>
                </div>
                <div class="row-item flex items-center justify-between rounded-2xl px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                        <p class="text-sm text-gray-600">Pubblicati questa settimana</p>
                    </div>
                    <p class="text-base font-bold text-emerald-600">{{ $weekPublished }}</p>
                </div>

                @if($nextItem?->scheduled_at)
                <div class="mt-1 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-indigo-600">Prossimo in agenda</p>
                    <p class="mt-1 truncate text-sm font-semibold text-gray-900">{{ $nextItem->title ?: 'Senza titolo' }}</p>
                    <p class="mt-0.5 text-xs font-medium text-indigo-700">{{ $nextItem->scheduled_at->format('d/m H:i') }}</p>
                </div>
                @endif
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="{{ route('posts.create') }}"
                   class="inline-flex items-center justify-center rounded-2xl bg-gray-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800">
                    Crea contenuto
                </a>
                <a href="{{ route('posts.create') }}?preset=reel"
                   class="inline-flex items-center justify-center rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50">
                    Crea reel
                </a>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════
     5. ULTIMI CONTENUTI
══════════════════════════════════════ --}}
<div class="overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-sm anim-4">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6">
        <div class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-gray-950">
                <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z"/></svg>
            </span>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400">Libreria</p>
                <h2 class="text-sm font-semibold text-gray-900">Ultimi contenuti</h2>
            </div>
        </div>
        <a href="{{ route('posts.index') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50">
            Vedi tutti
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
        </a>
    </div>

    @forelse($recentItems as $item)
    @php
        $aiInfo = $uiAi($item->ai_status);
        $platformColors = [
            'instagram' => ['bg' => 'bg-pink-100',   'text' => 'text-pink-700'],
            'facebook'  => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700'],
            'tiktok'    => ['bg' => 'bg-gray-900',   'text' => 'text-white'],
            'youtube'   => ['bg' => 'bg-red-100',    'text' => 'text-red-700'],
            'linkedin'  => ['bg' => 'bg-sky-100',    'text' => 'text-sky-700'],
            'twitter'   => ['bg' => 'bg-sky-100',    'text' => 'text-sky-700'],
            'pinterest' => ['bg' => 'bg-rose-100',   'text' => 'text-rose-700'],
        ];
        $pKey   = strtolower(trim((string)($item->platform ?? '')));
        $pColor = $platformColors[$pKey] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-600'];
        $pLabel = strtoupper(substr($pKey ?: 'IG', 0, 2));
    @endphp
    <a href="{{ route('posts.edit', $item) }}"
       class="flex items-center gap-4 border-b border-gray-50 px-5 py-3.5 transition last:border-0 hover:bg-gray-50/70 sm:px-6">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xs font-bold {{ $pColor['bg'] }} {{ $pColor['text'] }}">
            {{ $pLabel }}
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900">{{ $item->title ?: 'Senza titolo' }}</p>
            <p class="mt-0.5 text-xs text-gray-400">
                {{ strtoupper((string) $item->format) }}
                @if($item->scheduled_at) · {{ $item->scheduled_at->format('d/m H:i') }}@endif
            </p>
        </div>
        <span class="shrink-0 inline-flex items-center rounded-full border px-2.5 py-1 text-[10px] font-semibold {{ $aiInfo['badge'] }}">
            {{ $aiInfo['label'] }}
        </span>
    </a>
    @empty
    <div class="flex flex-col items-center gap-3 px-6 py-12 text-center">
        <p class="text-sm text-gray-500">Nessun contenuto ancora.</p>
        <a href="{{ route('posts.create') }}" class="rounded-xl bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Crea il primo</a>
    </div>
    @endforelse
</div>

</div>{{-- end space-y-5 --}}
</div>{{-- end container --}}
@endsection
