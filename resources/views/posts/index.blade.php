@extends('layouts.app')

@section('content')
@php
    $items = $items ?? collect();
    $isPaginator = $items instanceof \Illuminate\Contracts\Pagination\Paginator
        || $items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    $postItems = $isPaginator ? collect($items->items()) : collect($items ?? []);
    $visibleItems = $postItems->count();
    $totalItems = (int) ($stats['total'] ?? ((is_object($items) && method_exists($items, 'total')) ? $items->total() : $visibleItems));
    $scheduledItems = (int) ($stats['scheduled'] ?? 0);
    $publishedItems = (int) ($stats['published'] ?? 0);
    $pendingItems = max(0, $totalItems - $publishedItems);

    $aiDone    = (int) ($stats['ai_done'] ?? 0);
    $aiQueued  = (int) ($stats['ai_queued'] ?? 0);
    $aiError   = (int) ($stats['ai_error'] ?? 0);

    $aiCompletion   = $totalItems > 0 ? (int) round(($aiDone / $totalItems) * 100) : 0;
    $scheduledRate  = $totalItems > 0 ? (int) round(($scheduledItems / $totalItems) * 100) : 0;
    $publishedRate  = $totalItems > 0 ? (int) round(($publishedItems / $totalItems) * 100) : 0;

    $statusCounts  = is_array($stats['status_counts'] ?? null) ? $stats['status_counts'] : [];
    $todayCount    = isset($todayItems) && $todayItems instanceof \Illuminate\Support\Collection ? $todayItems->count() : 0;
    $workflowLabels = \App\Support\UiStatus::publicationOptions();
@endphp

<style>
.lib-card {
    background: rgba(255,255,255,0.96);
    border: 1px solid rgba(10,45,111,0.1);
    border-radius: 1.5rem;
    overflow: hidden;
    transition: transform 180ms ease, box-shadow 180ms ease;
}
.lib-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(10,45,111,0.1);
}
.lib-thumb {
    position: relative;
    height: 11rem;
    background: linear-gradient(135deg,#071D4E,#0A2D6F);
    overflow: hidden;
}
.lib-thumb img, .lib-thumb video {
    width: 100%; height: 100%; object-fit: cover;
}
.lib-filter-pill {
    display: inline-flex; align-items: center;
    padding: .35rem .9rem;
    border-radius: 999px;
    border: 1px solid rgba(10,45,111,0.14);
    background: rgba(255,255,255,0.96);
    font-size: .75rem; font-weight: 600;
    color: #475569;
    cursor: pointer; transition: all 150ms;
}
.lib-filter-pill:hover, .lib-filter-pill.active {
    background: linear-gradient(135deg,#0A2D6F,#1498F3);
    border-color: transparent;
    color: #fff;
}
.stat-bar { height: 4px; border-radius: 9999px; margin-top: .5rem; background: rgba(10,45,111,0.08); overflow: hidden; }
.stat-bar-fill { height: 100%; border-radius: 9999px; background: linear-gradient(90deg,#0A2D6F,#3BC8FF); }
</style>

<div
    x-data="libreria()"
    class="w-full space-y-5 px-4 py-6 sm:px-6 lg:px-8"
>

    {{-- ── Header ── --}}
    <div class="overflow-hidden rounded-[2rem]" style="background:linear-gradient(135deg,#071D4E 0%,#0A2D6F 58%,#2DBAF7 100%);">
        <div style="padding:1.4rem 1.5rem;">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:rgba(255,255,255,0.55);letter-spacing:.18em;">Libreria contenuti</p>
                    <h1 class="mt-1 text-2xl font-semibold text-white sm:text-3xl">Tutti i contenuti</h1>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold text-white" style="background:rgba(255,255,255,0.15);">{{ $totalItems }} totali</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:rgba(59,200,255,0.22);color:#b3eeff;">{{ $publishedItems }} pubblicati</span>
                        @if($aiError > 0)
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:rgba(220,38,38,0.22);color:#fca5a5;">{{ $aiError }} errori</span>
                        @endif
                        @if($aiQueued > 0)
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.8);">{{ $aiQueued }} in generazione</span>
                        @endif
                    </div>
                </div>
                <a href="{{ route('posts.create') }}" class="inline-flex items-center gap-2 rounded-2xl px-5 py-2.5 text-sm font-semibold text-white transition" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);" onmouseover="this.style.background='rgba(255,255,255,0.24)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Crea contenuto
                </a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    {{-- ── Stats 2×2 mobile / 4-col desktop ── --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="ui-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.1em;">AI generati</p>
            <p class="mt-2 text-3xl font-bold" style="color:#0A2D6F;">{{ $aiDone }}</p>
            <p class="mt-0.5 text-xs" style="color:#94a3b8;">di {{ $totalItems }} · {{ $aiCompletion }}%</p>
            <div class="stat-bar"><div class="stat-bar-fill" style="width:{{ $aiCompletion }}%;"></div></div>
        </div>
        <div class="ui-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.1em;">Pianificati</p>
            <p class="mt-2 text-3xl font-bold" style="color:#0A2D6F;">{{ $scheduledItems }}</p>
            <p class="mt-0.5 text-xs" style="color:#94a3b8;">{{ $scheduledRate }}% copertura</p>
            <div class="stat-bar"><div class="stat-bar-fill" style="width:{{ $scheduledRate }}%;"></div></div>
        </div>
        <div class="ui-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.1em;">Pubblicati</p>
            <p class="mt-2 text-3xl font-bold" style="color:#0f9f6e;">{{ $publishedItems }}</p>
            <p class="mt-0.5 text-xs" style="color:#94a3b8;">{{ $publishedRate }}% del totale</p>
            <div class="stat-bar" style="background:rgba(15,159,110,0.1);"><div class="stat-bar-fill" style="width:{{ $publishedRate }}%;background:linear-gradient(90deg,#0f9f6e,#34d399);"></div></div>
        </div>
        <div class="ui-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.1em;">Da completare</p>
            <p class="mt-2 text-3xl font-bold" style="color:{{ $pendingItems > 0 ? '#b45309' : '#0A2D6F' }};">{{ $pendingItems }}</p>
            <p class="mt-0.5 text-xs" style="color:#94a3b8;">
                @if($aiError > 0)<span style="color:#dc2626;">{{ $aiError }} errori · </span>@endif
                {{ $aiQueued }} in coda
            </p>
            <div class="stat-bar" style="background:rgba(180,83,9,0.08);"><div class="stat-bar-fill" style="width:{{ $totalItems > 0 ? round(($pendingItems/$totalItems)*100) : 0 }}%;background:linear-gradient(90deg,#b45309,#fbbf24);"></div></div>
        </div>
    </div>

    {{-- ── Layout principale ── --}}
    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ── Colonna contenuti (2/3) ── --}}
        <div class="space-y-4 lg:col-span-2">

            {{-- Filtri + ricerca --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap gap-1.5">
                    <button class="lib-filter-pill active" :class="filter === '' && 'active'" @click="filter = ''" :style="filter === '' ? 'background:linear-gradient(135deg,#0A2D6F,#1498F3);border-color:transparent;color:#fff;' : ''">Tutti</button>
                    <button class="lib-filter-pill" @click="filter = 'working'" :style="filter === 'working' ? 'background:linear-gradient(135deg,#0A2D6F,#1498F3);border-color:transparent;color:#fff;' : ''">In lavorazione</button>
                    <button class="lib-filter-pill" @click="filter = 'ready'" :style="filter === 'ready' ? 'background:linear-gradient(135deg,#0A2D6F,#1498F3);border-color:transparent;color:#fff;' : ''">Pronti</button>
                    <button class="lib-filter-pill" @click="filter = 'published'" :style="filter === 'published' ? 'background:linear-gradient(135deg,#0f9f6e,#34d399);border-color:transparent;color:#fff;' : ''">Pubblicati</button>
                    @if($aiError > 0)
                    <button class="lib-filter-pill" @click="filter = 'error'" :style="filter === 'error' ? 'background:linear-gradient(135deg,#dc2626,#f87171);border-color:transparent;color:#fff;' : ''">Errori · {{ $aiError }}</button>
                    @endif
                </div>
                <div class="ml-auto flex items-center gap-2 rounded-2xl border px-3 py-2" style="border-color:rgba(10,45,111,0.14);background:rgba(255,255,255,0.96);">
                    <svg class="h-4 w-4" style="color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" x-model="search" placeholder="Cerca titolo…" class="w-36 bg-transparent text-sm outline-none" style="color:#0A2D6F;" />
                </div>
            </div>

            @if($isPaginator)
                <div class="text-right text-xs" style="color:#94a3b8;">{{ $items->links() }}</div>
            @endif

            @if($visibleItems > 0)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach($postItems as $item)
                    @php
                        $scheduledAt = $item->scheduled_at ? \Illuminate\Support\Carbon::parse($item->scheduled_at) : null;
                        $mediaPreview = is_array($item->media_preview ?? null) ? $item->media_preview : [];
                        $videoPath = trim((string) ($mediaPreview['video_path'] ?? ''));
                        $previewImagePath = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                        $isVideo = (bool) ($mediaPreview['is_video'] ?? ($videoPath !== ''));
                        $videoUrl = $videoPath !== '' ? asset('storage/' . ltrim($videoPath, '/')) : '';
                        $hasThumb = $previewImagePath !== '' || !empty($item->ai_image_path) || $isVideo;

                        $publicationInfo = \App\Support\UiStatus::publication((string) $item->status);
                        $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                        $latestFeedback = $item->latestFeedbackEntry;

                        // Filtro Alpine
                        $filterKey = match(true) {
                            in_array($item->status, ['published']) => 'published',
                            $item->ai_status === 'error' => 'error',
                            in_array($item->status, ['approved','scheduled']) => 'ready',
                            default => 'working',
                        };
                        $platformLabel = strtoupper((string) $item->platform);
                        $formatLabel = strtoupper((string) $item->format);
                    @endphp

                    <article
                        class="lib-card"
                        data-filter="{{ $filterKey }}"
                        data-title="{{ strtolower($item->title ?: '') }} {{ strtolower($item->ai_caption ?: '') }}"
                        x-show="matchFilter('{{ $filterKey }}', '{{ addslashes(strtolower($item->title ?: '')) }} {{ addslashes(strtolower(Str::limit($item->ai_caption ?? '', 80))) }}')"
                    >
                        {{-- Thumbnail --}}
                        <div class="lib-thumb">
                            @if($previewImagePath !== '')
                                <img src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}" alt="" loading="lazy" onerror="this.parentElement.style.background='linear-gradient(135deg,#071D4E,#0A2D6F)'">
                            @elseif(!empty($item->ai_image_path))
                                <img src="{{ asset('storage/' . ltrim($item->ai_image_path, '/')) }}" alt="" loading="lazy" onerror="this.parentElement.style.background='linear-gradient(135deg,#071D4E,#0A2D6F)'">
                            @elseif($isVideo && $videoUrl !== '')
                                <video muted playsinline preload="metadata" class="h-full w-full object-cover" data-frame-preview>
                                    <source src="{{ $videoUrl }}" type="video/mp4">
                                </video>
                            @else
                                <div class="flex h-full items-center justify-center">
                                    <svg class="h-10 w-10" style="color:rgba(255,255,255,0.2);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </div>
                            @endif

                            {{-- Overlay badges su thumbnail --}}
                            <div class="absolute inset-x-0 bottom-0 flex items-end justify-between px-3 py-2" style="background:linear-gradient(to top, rgba(7,29,78,0.75), transparent);">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white" style="background:rgba(255,255,255,0.15);">
                                    {{ $platformLabel }}@if($formatLabel) · {{ $formatLabel }}@endif
                                </span>
                                @if($isVideo)
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase text-white" style="background:rgba(10,45,111,0.6);">Video</span>
                                @endif
                            </div>

                            {{-- Status dot in alto a destra --}}
                            @php
                                $dotColor = match($item->ai_status) {
                                    'done'    => '#10b981',
                                    'pending' => '#f59e0b',
                                    'error'   => '#ef4444',
                                    default   => '#94a3b8',
                                };
                            @endphp
                            <div class="absolute right-3 top-3 flex h-5 w-5 items-center justify-center rounded-full" style="background:rgba(255,255,255,0.95);">
                                <div class="h-2.5 w-2.5 rounded-full" style="background:{{ $dotColor }};"></div>
                            </div>
                        </div>

                        {{-- Body --}}
                        <div style="padding:1rem;">
                            {{-- Titolo + data --}}
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="line-clamp-1 text-sm font-semibold leading-snug" style="color:#0A2D6F;">
                                    {{ $item->title ?: ('Post #' . $item->id) }}
                                </h3>
                                @if($scheduledAt)
                                    <span class="shrink-0 text-[11px]" style="color:#94a3b8;">{{ $scheduledAt->format('d/m H:i') }}</span>
                                @endif
                            </div>

                            {{-- Caption preview --}}
                            @if($item->ai_caption || $item->caption)
                                <p class="mt-1.5 line-clamp-2 text-xs leading-relaxed" style="color:#64748b;">
                                    {{ $item->ai_caption ?: $item->caption }}
                                </p>
                            @endif

                            {{-- Status badges --}}
                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                <span class="ui-chip {{ $publicationInfo['badge'] ?? '' }} py-0.5 text-[10px]">{{ $publicationInfo['label'] ?? '' }}</span>
                                <span class="ui-chip {{ $aiInfo['badge'] ?? '' }} py-0.5 text-[10px]">AI {{ $aiInfo['label'] ?? '' }}</span>
                                @if($latestFeedback)
                                    <span class="ui-chip py-0.5 text-[10px] {{ $latestFeedback->sentiment === 'like' ? 'ui-badge-green' : 'ui-badge-red' }}">
                                        {{ $latestFeedback->sentiment === 'like' ? '👍 Approvato' : '✏️ Da correggere' }}
                                    </span>
                                @endif
                            </div>

                            {{-- Azioni --}}
                            <div class="mt-3 flex items-center gap-2">
                                <a href="{{ route('posts.edit', $item) }}" class="ui-btn-primary flex-1 justify-center py-2 text-xs">
                                    Apri
                                </a>
                                <a href="{{ route('posts.edit', $item) }}#feedback-loop" class="ui-btn-secondary px-3 py-2 text-xs">
                                    Feedback
                                </a>
                                @if(\Illuminate\Support\Facades\Route::has('ai.content.generate'))
                                    <form action="{{ route('ai.content.generate', $item) }}" method="POST">
                                        @csrf
                                        <button type="submit" title="Rigenera AI" class="ui-btn-secondary px-3 py-2 text-xs">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                    @endforeach
                </div>

                {{-- Empty state filtri --}}
                <p x-show="allHidden" class="py-10 text-center text-sm" style="color:#94a3b8;" x-cloak>Nessun contenuto corrisponde al filtro selezionato.</p>

            @else
                {{-- Empty state assoluto --}}
                <div class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed py-16 text-center" style="border-color:rgba(10,45,111,0.14);background:rgba(239,245,255,0.4);">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full" style="background:linear-gradient(135deg,#0A2D6F,#1498F3);">
                        <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    <h2 class="mt-5 text-xl font-semibold" style="color:#0A2D6F;">Libreria vuota</h2>
                    <p class="mt-2 max-w-sm text-sm" style="color:#64748b;">Crea il primo contenuto per attivare il flusso operativo.</p>
                    <div class="mt-6 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('posts.create') }}" class="ui-btn-primary gap-2">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Crea contenuto
                        </a>
                        <a href="{{ route('wizard.start') }}" class="ui-btn-secondary">Piano editoriale</a>
                    </div>
                </div>
            @endif

            @if($isPaginator && $items->hasPages())
                <div class="mt-2">{{ $items->links() }}</div>
            @endif
        </div>

        {{-- ── Sidebar destra (1/3) ── --}}
        <div class="space-y-4">

            {{-- Oggi --}}
            <div class="ui-card" style="padding:1.25rem;">
                <h2 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Situazione oggi</h2>
                <div class="space-y-2">
                    <div class="flex items-center justify-between rounded-xl px-3 py-2.5" style="background:rgba(239,245,255,0.8);">
                        <p class="text-xs" style="color:#64748b;">Previsti oggi</p>
                        <p class="text-lg font-bold" style="color:#0A2D6F;">{{ $todayCount }}</p>
                    </div>
                    <div class="flex items-center justify-between rounded-xl px-3 py-2.5" style="background:rgba(239,245,255,0.8);">
                        <p class="text-xs" style="color:#64748b;">Da completare</p>
                        <p class="text-lg font-bold" style="color:{{ ($todayPending ?? 0) > 0 ? '#b45309' : '#0f9f6e' }};">{{ $todayPending ?? 0 }}</p>
                    </div>
                    @if(($nextItem ?? null) && $nextItem->scheduled_at)
                    <div class="rounded-xl px-3 py-2.5" style="background:rgba(239,245,255,0.8);">
                        <p class="text-xs" style="color:#64748b;">Prossimo slot</p>
                        <p class="mt-0.5 text-sm font-semibold" style="color:#0A2D6F;">
                            {{ \Illuminate\Support\Carbon::parse($nextItem->scheduled_at)->format('d/m · H:i') }}
                        </p>
                        <p class="text-xs" style="color:#94a3b8;">{{ \Illuminate\Support\Str::limit($nextItem->title ?: 'Senza titolo', 40) }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Stato workflow --}}
            <div class="ui-card" style="padding:1.25rem;">
                <h2 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Workflow</h2>
                <div class="space-y-2">
                    @foreach([
                        ['draft',     $workflowLabels['draft']     ?? 'Bozza',      '#94a3b8'],
                        ['review',    $workflowLabels['review']    ?? 'In review',  '#1498F3'],
                        ['approved',  $workflowLabels['approved']  ?? 'Approvato',  '#0A2D6F'],
                        ['scheduled', $workflowLabels['scheduled'] ?? 'Pianificato','#3BC8FF'],
                        ['published', $workflowLabels['published'] ?? 'Pubblicato', '#0f9f6e'],
                        ['failed',    $workflowLabels['failed']    ?? 'Fallito',    '#dc2626'],
                    ] as [$key, $label, $color])
                    @php $count = (int) ($statusCounts[$key] ?? 0); @endphp
                    @if($count > 0 || in_array($key, ['draft','published']))
                    <div class="flex items-center gap-3 rounded-xl px-3 py-2" style="background:rgba(239,245,255,0.6);">
                        <div class="h-2 w-2 rounded-full shrink-0" style="background:{{ $color }};"></div>
                        <p class="flex-1 text-xs" style="color:#475569;">{{ $label }}</p>
                        <p class="text-sm font-bold" style="color:{{ $color }};">{{ $count }}</p>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>

            {{-- Link rapidi --}}
            <div class="ui-card" style="padding:1.25rem;">
                <h2 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Azioni rapide</h2>
                <div class="space-y-2">
                    @foreach([
                        [route('posts.create'), 'Crea contenuto', 'M12 4v16m8-8H4'],
                        [route('posts.create').'?preset=reel', 'Crea reel', 'M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'],
                        [route('calendar'), 'Calendario', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                        [route('wizard.start'), 'Piano editoriale', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                    ] as [$href, $label, $path])
                    <a href="{{ $href }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition" style="color:#0A2D6F;" onmouseover="this.style.background='rgba(239,245,255,0.9)'" onmouseout="this.style.background='transparent'">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg" style="background:linear-gradient(135deg,#0A2D6F,#1498F3);">
                            <svg class="h-3.5 w-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $path }}"/></svg>
                        </div>
                        <span class="font-medium">{{ $label }}</span>
                        <svg class="ml-auto h-3.5 w-3.5 shrink-0" style="color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function libreria() {
    return {
        filter: '',
        search: '',
        get allHidden() {
            return document.querySelectorAll('[data-filter]') &&
                [...document.querySelectorAll('article[data-filter]')].every(el => el.style.display === 'none');
        },
        matchFilter(filterKey, title) {
            const filterOk = this.filter === '' || this.filter === filterKey;
            const searchOk = this.search === '' || title.includes(this.search.toLowerCase());
            return filterOk && searchOk;
        },
    };
}
</script>
@endsection
