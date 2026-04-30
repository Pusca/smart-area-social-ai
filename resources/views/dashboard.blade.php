@extends('layouts.app')

@section('content')
@php
    $uiPublication = fn ($status) => \App\Support\UiStatus::publication((string) $status);
    $uiAi          = fn ($status) => \App\Support\UiStatus::ai((string) $status);
@endphp

<style>
    .dash-glass {
        background: rgba(255,255,255,0.88);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .feed-thumb { transition: transform .2s ease, box-shadow .2s ease; }
    .feed-thumb:hover { transform: scale(1.03); box-shadow: 0 8px 24px rgba(15,23,42,.14); }
    @keyframes fade-up {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .anim-fade-up { animation: fade-up .4s ease both; }
</style>

<section class="w-full max-w-full overflow-x-hidden px-4 py-5 sm:px-6 lg:px-8" style="max-width:1280px;margin:0 auto;">
    <div class="space-y-5">

    {{-- ═══════════════════════════════════════════════════════
         1. HEADER
    ═══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between anim-fade-up">
        <div class="min-w-0">
            <div class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $scoreBadgeClass }}">
                <x-application-logo variant="icon" class="h-4 w-4" />
                Workspace {{ $scoreLabel }}
            </div>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 sm:text-3xl">
                Ciao {{ $u?->name }}
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
               class="inline-flex items-center gap-2 rounded-2xl border border-app bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:border-brand hover:text-brand">
                Piano
            </a>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         2. STATS — 1 riga sia mobile che desktop
    ═══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-4 gap-2 sm:gap-3 anim-fade-up" style="animation-delay:.05s">
        {{-- Generati --}}
        <div class="group rounded-[1.5rem] border border-app bg-white p-3 shadow-sm transition hover:shadow-md sm:p-4">
            <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-400 sm:text-[10px]">Generati</p>
            <p class="mt-1 text-xl font-bold text-gray-950 sm:text-3xl">{{ $doneItems }}</p>
            <p class="mt-0.5 hidden text-xs text-gray-400 sm:block">di {{ $totalItems }} totali</p>
            <div class="mt-2 hidden h-1.5 overflow-hidden rounded-full bg-gray-100 sm:block">
                <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 to-indigo-500" style="width:{{ $aiCompletion }}%"></div>
            </div>
            <p class="mt-1 text-[10px] font-semibold text-indigo-600 sm:hidden">{{ $aiCompletion }}%</p>
        </div>

        {{-- Pubblicati --}}
        <div class="group rounded-[1.5rem] border border-app bg-white p-3 shadow-sm transition hover:shadow-md sm:p-4">
            <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-400 sm:text-[10px]">Pubblicati</p>
            <p class="mt-1 text-xl font-bold text-gray-950 sm:text-3xl">{{ $publishedItems }}</p>
            <p class="mt-0.5 hidden text-xs text-gray-400 sm:block">{{ $publishRate }}% del totale</p>
            <div class="mt-2 hidden h-1.5 overflow-hidden rounded-full bg-gray-100 sm:block">
                <div class="h-full rounded-full bg-emerald-500" style="width:{{ $publishRate }}%"></div>
            </div>
            <p class="mt-1 text-[10px] font-semibold text-emerald-600 sm:hidden">{{ $publishRate }}%</p>
        </div>

        {{-- In coda --}}
        <div class="group rounded-[1.5rem] border border-app bg-white p-3 shadow-sm transition hover:shadow-md sm:p-4">
            <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-400 sm:text-[10px]">In coda</p>
            <p class="mt-1 text-xl font-bold {{ $queuedItems > 0 ? 'text-amber-600' : 'text-gray-950' }} sm:text-3xl">{{ $queuedItems }}</p>
            <p class="mt-0.5 hidden text-xs text-gray-400 sm:block">In generazione</p>
            <div class="mt-2 hidden h-1.5 overflow-hidden rounded-full bg-gray-100 sm:block">
                <div class="h-full rounded-full bg-amber-400" style="width:{{ min(100, $queueRate) }}%"></div>
            </div>
            <p class="mt-1 text-[10px] font-semibold text-amber-500 sm:hidden">attivi</p>
        </div>

        {{-- Errori --}}
        <div class="group rounded-[1.5rem] border border-app bg-white p-3 shadow-sm transition hover:shadow-md sm:p-4">
            <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-400 sm:text-[10px]">Errori</p>
            <p class="mt-1 text-xl font-bold {{ $errorItems > 0 ? 'text-red-600' : 'text-gray-950' }} sm:text-3xl">{{ $errorItems }}</p>
            <p class="mt-0.5 hidden text-xs text-gray-400 sm:block">Da verificare</p>
            <div class="mt-2 hidden h-1.5 overflow-hidden rounded-full bg-gray-100 sm:block">
                <div class="h-full rounded-full {{ $errorItems > 0 ? 'bg-red-500' : 'bg-gray-200' }}" style="width:{{ max(4, $errorRate) }}%"></div>
            </div>
            <p class="mt-1 text-[10px] font-semibold {{ $errorItems > 0 ? 'text-red-500' : 'text-gray-300' }} sm:hidden">
                {{ $errorItems > 0 ? 'rivedere' : 'ok' }}
            </p>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         3. FEED — in alto, visivo e prominente
    ═══════════════════════════════════════════════════════ --}}
    <div class="overflow-hidden rounded-[2rem] border border-app bg-white shadow-panel anim-fade-up" style="animation-delay:.1s">
        {{-- Header gradiente --}}
        <div class="bg-gradient-to-r from-cyan-500 via-indigo-600 to-purple-600 px-5 py-4">
            <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.24em] text-white/70">Feed preview</p>
                    <p class="mt-0.5 text-base font-semibold text-white">Anteprima del piano visivo</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold text-white">
                        {{ $instagramFeedItems->count() }} post
                    </span>
                    <a href="{{ route('posts.index') }}"
                       class="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold text-white transition hover:bg-white/30">
                        Libreria →
                    </a>
                </div>
            </div>
        </div>

        {{-- Grid --}}
        <div class="p-3 sm:p-4">
            @if($instagramFeedItems->isEmpty())
                <div class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-gray-200 bg-gray-50 py-12 text-center">
                    <div class="h-12 w-12 rounded-2xl bg-gradient-to-br from-cyan-100 to-indigo-100 flex items-center justify-center">
                        <svg class="h-6 w-6 text-indigo-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                    </div>
                    <p class="text-sm text-gray-500">Nessun contenuto ancora.</p>
                    <a href="{{ route('posts.create') }}" class="rounded-xl bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Crea il primo</a>
                </div>
            @else
                <div class="grid grid-cols-3 gap-1 sm:gap-1.5">
                    @foreach($instagramFeedItems as $gridItem)
                    @php
                        $gridDate = $gridItem->scheduled_at ?: $gridItem->created_at;
                        $gridDateLabel = $gridDate ? $gridDate->format('d/m H:i') : '';
                        $gridPubInfo = $uiPublication($gridItem->status);
                        $dotColor = match($gridPubInfo['tone'] ?? 'neutral') {
                            'success' => 'bg-emerald-400',
                            'info'    => 'bg-indigo-400',
                            'danger'  => 'bg-red-400',
                            default   => 'bg-gray-400',
                        };
                        $preview = is_array($gridItem->media_preview ?? null) ? $gridItem->media_preview : [];
                        $vidPath = trim((string) ($preview['video_path'] ?? ''));
                        $imgPath = trim((string) ($preview['preview_image_path'] ?? ''));
                        $isVideo = (bool) ($preview['is_video'] ?? ($vidPath !== ''));
                        $hasImage = $imgPath !== '' || (!$isVideo && !empty($gridItem->ai_image_path));
                    @endphp
                    <a href="{{ route('posts.edit', $gridItem) }}"
                       class="feed-thumb group relative aspect-square overflow-hidden rounded-xl bg-gradient-to-br from-gray-100 to-gray-50">
                        {{-- Placeholder number --}}
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

                        {{-- Status dot --}}
                        <span class="absolute left-1.5 top-1.5 z-10 h-2 w-2 rounded-full {{ $dotColor }} ring-1 ring-white/80"></span>

                        @if($isVideo)
                            <span class="absolute right-1.5 top-1.5 z-10 rounded bg-black/60 px-1 py-0.5 text-[9px] font-semibold text-white">▶</span>
                        @endif

                        {{-- Hover overlay --}}
                        <div class="absolute inset-0 z-10 flex flex-col justify-end bg-gradient-to-t from-black/70 via-black/20 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
                            <p class="truncate text-[10px] font-semibold leading-tight text-white">{{ $gridItem->title ?: 'Post #'.$gridItem->id }}</p>
                            @if($gridDateLabel)
                                <p class="text-[9px] text-white/75">{{ $gridDateLabel }}</p>
                            @endif
                        </div>
                    </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         4. PIANO + AGENDA — griglia 2 col su desktop
    ═══════════════════════════════════════════════════════ --}}
    <div class="grid gap-4 lg:grid-cols-2 anim-fade-up" style="animation-delay:.15s">

        {{-- Piano attivo --}}
        <div class="rounded-[2rem] border border-app bg-white p-5 shadow-panel sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400">Piano attivo</p>
                    <h2 class="mt-1 text-base font-semibold text-gray-950">
                        {{ $latestPlan ? $latestPlan->name : 'Nessun piano' }}
                    </h2>
                </div>
                @if($latestPlan)
                    <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}">
                        @csrf
                        <button class="inline-flex items-center rounded-xl bg-gray-950 px-3 py-2 text-xs font-semibold text-white transition hover:bg-gray-800">
                            Genera
                        </button>
                    </form>
                @else
                    <a href="{{ route('wizard.start') }}"
                       class="inline-flex items-center rounded-xl border border-app bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:text-brand">
                        Crea piano
                    </a>
                @endif
            </div>

            @if($latestPlan)
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-between gap-2 rounded-2xl border border-app bg-app/50 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase tracking-wide text-gray-400">Avanzamento tempo</p>
                            <p class="mt-0.5 text-sm font-semibold text-gray-900">{{ $planDaysElapsed }} / {{ $planDaysTotal }} giorni</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-indigo-500" style="width:{{ $planTimeProgress }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-indigo-600">{{ $planTimeProgress }}%</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-2 rounded-2xl border border-app bg-app/50 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase tracking-wide text-gray-400">Output completati</p>
                            <p class="mt-0.5 text-sm font-semibold text-gray-900">{{ $planItemsDone }} / {{ $planItemsTotal }} contenuti</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-emerald-500" style="width:{{ $planOutputProgress }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-emerald-600">{{ $planOutputProgress }}%</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="rounded-xl border border-app bg-app/50 px-3 py-2.5 text-center">
                            <p class="text-[9px] uppercase tracking-wide text-gray-400">In coda</p>
                            <p class="mt-0.5 text-lg font-bold {{ $planItemsQueued > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ $planItemsQueued }}</p>
                        </div>
                        <div class="rounded-xl border border-app bg-app/50 px-3 py-2.5 text-center">
                            <p class="text-[9px] uppercase tracking-wide text-gray-400">Pronti</p>
                            <p class="mt-0.5 text-lg font-bold text-emerald-600">{{ $planItemsDone }}</p>
                        </div>
                        <div class="rounded-xl border border-app bg-app/50 px-3 py-2.5 text-center">
                            <p class="text-[9px] uppercase tracking-wide text-gray-400">Errori</p>
                            <p class="mt-0.5 text-lg font-bold {{ $planItemsError > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $planItemsError }}</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-4 rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-5 py-8 text-center">
                    <p class="text-sm text-gray-500">Crea un piano per vedere progressi e copertura del calendario.</p>
                </div>
            @endif
        </div>

        {{-- Agenda + flusso --}}
        <div class="rounded-[2rem] border border-app bg-white p-5 shadow-panel sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400">Oggi</p>
                    <h2 class="mt-1 text-base font-semibold text-gray-950">Flusso del giorno</h2>
                </div>
                <a href="{{ route('calendar') }}"
                   class="inline-flex items-center rounded-xl border border-app bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:text-brand">
                    Calendario
                </a>
            </div>

            <div class="mt-4 space-y-2.5">
                <div class="flex items-center justify-between rounded-2xl border border-app bg-app/50 px-4 py-3">
                    <p class="text-sm text-gray-600">Previsti oggi</p>
                    <p class="text-lg font-bold text-gray-950">{{ $todayItems->count() }}</p>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-app bg-app/50 px-4 py-3">
                    <p class="text-sm text-gray-600">Da completare oggi</p>
                    <p class="text-lg font-bold {{ $todayPending > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $todayPending }}</p>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-app bg-app/50 px-4 py-3">
                    <p class="text-sm text-gray-600">In calendario questa settimana</p>
                    <p class="text-lg font-bold text-gray-950">{{ $weekPlanned }}</p>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-app bg-app/50 px-4 py-3">
                    <p class="text-sm text-gray-600">Pubblicati questa settimana</p>
                    <p class="text-lg font-bold text-emerald-600">{{ $weekPublished }}</p>
                </div>
                @if($nextItem?->scheduled_at)
                <div class="rounded-2xl border border-brand/20 bg-brand-soft px-4 py-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand">Prossimo in agenda</p>
                    <p class="mt-1 truncate text-sm font-semibold text-gray-900">{{ $nextItem->title ?: 'Senza titolo' }}</p>
                    <p class="mt-0.5 text-xs text-brand">{{ $nextItem->scheduled_at->format('d/m H:i') }}</p>
                </div>
                @endif
            </div>

            {{-- Quick CTAs --}}
            <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="{{ route('posts.create') }}"
                   class="inline-flex items-center justify-center rounded-2xl bg-gray-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800">
                    Crea contenuto
                </a>
                <a href="{{ route('posts.create') }}?preset=reel"
                   class="inline-flex items-center justify-center rounded-2xl border border-app bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-brand hover:text-brand">
                    Crea reel
                </a>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         5. ULTIMI CONTENUTI — in fondo
    ═══════════════════════════════════════════════════════ --}}
    <div class="overflow-hidden rounded-[2rem] border border-app bg-white shadow-panel anim-fade-up" style="animation-delay:.2s">
        <div class="flex items-center justify-between gap-3 border-b border-app px-5 py-4 sm:px-6">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400">Libreria</p>
                <h2 class="mt-0.5 text-base font-semibold text-gray-950">Ultimi contenuti</h2>
            </div>
            <a href="{{ route('posts.index') }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-app bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:text-brand">
                Vedi tutti
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>

        @forelse($recentItems as $item)
        @php $aiInfo = $uiAi($item->ai_status); @endphp
        <a href="{{ route('posts.edit', $item) }}"
           class="flex items-center gap-4 border-b border-app/60 px-5 py-3.5 transition last:border-0 hover:bg-app/40 sm:px-6">
            {{-- Colored icon placeholder --}}
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xs font-bold"
                 style="background: linear-gradient(135deg, rgba(59,200,255,.15) 0%, rgba(99,102,241,.15) 100%); color: var(--primary);">
                {{ strtoupper(substr($item->platform ?? 'IG', 0, 2)) }}
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
</section>
@endsection
