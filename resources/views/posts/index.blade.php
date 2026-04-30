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

    $statusCounts = is_array($stats['status_counts'] ?? null) ? $stats['status_counts'] : [];
@endphp

<style>
.lib-card {
    background: #fff;
    border: 1px solid rgba(10,45,111,0.08);
    border-radius: 1.25rem;
    overflow: hidden;
    transition: box-shadow 180ms ease, border-color 180ms ease;
}
.lib-card:hover {
    border-color: rgba(10,45,111,0.18);
    box-shadow: 0 6px 24px rgba(10,45,111,0.07);
}
.lib-thumb {
    position: relative;
    height: 10rem;
    background: linear-gradient(135deg,#e8edf5,#dce6f0);
    overflow: hidden;
}
.lib-thumb img, .lib-thumb video {
    width: 100%; height: 100%; object-fit: cover;
}
.lib-pill {
    display: inline-flex; align-items: center;
    padding: .3rem .85rem;
    border-radius: 999px;
    background: rgba(10,45,111,0.05);
    font-size: .72rem; font-weight: 600;
    color: #64748b;
    cursor: pointer; transition: all 140ms;
    border: none; outline: none;
}
.lib-pill:hover { background: rgba(10,45,111,0.1); color: #0A2D6F; }
.lib-pill.active { background: #0A2D6F; color: #fff; }
</style>

<div
    x-data="libreria()"
    class="w-full px-4 py-6 sm:px-6 lg:px-8"
    style="max-width:1280px;margin:0 auto;"
>

    {{-- ── Header leggero ── --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest" style="color:#94a3b8;letter-spacing:.15em;">Contenuti</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color:#0A2D6F;">Libreria</h1>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" style="background:rgba(10,45,111,0.07);color:#475569;">{{ $totalItems }} totali</span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" style="background:rgba(15,159,110,0.09);color:#0f9f6e;">{{ $publishedItems }} pubblicati</span>
                @if($aiQueued > 0)
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" style="background:rgba(245,158,11,0.1);color:#b45309;">{{ $aiQueued }} in generazione</span>
                @endif
                @if($aiError > 0)
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" style="background:rgba(220,38,38,0.08);color:#dc2626;">{{ $aiError }} errori</span>
                @endif
            </div>
        </div>
        <a href="{{ route('posts.create') }}" class="ui-btn-primary gap-2">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Crea contenuto
        </a>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    {{-- ── Filtri ── --}}
    <div class="mb-3 flex flex-wrap gap-1.5">
        <button class="lib-pill" :class="filter === '' && 'active'" @click="filter = ''">Tutti <span class="ml-1 opacity-50">{{ $totalItems }}</span></button>
        <button class="lib-pill" :class="filter === 'working' && 'active'" @click="filter = 'working'">In lavorazione</button>
        <button class="lib-pill" :class="filter === 'ready' && 'active'" @click="filter = 'ready'">Pronti</button>
        <button class="lib-pill" :class="filter === 'published' && 'active'" @click="filter = 'published'">Pubblicati <span class="ml-1 opacity-50">{{ $publishedItems }}</span></button>
        @if($aiError > 0)
            <button class="lib-pill" :class="filter === 'error' && 'active'" @click="filter = 'error'" style="color:#dc2626;">Errori {{ $aiError }}</button>
        @endif
    </div>

    {{-- ── Ricerca ── --}}
    <div class="mb-5 flex items-center gap-2.5 px-4 py-2.5" style="background:rgba(10,45,111,0.04);border-radius:1rem;">
        <svg class="h-4 w-4 shrink-0" style="color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" x-model="search" placeholder="Cerca per titolo o testo…" class="w-full bg-transparent text-sm outline-none" style="color:#1e3a5f;" />
        <button x-show="search !== ''" @click="search = ''" class="shrink-0 rounded-full p-0.5 transition" style="color:#94a3b8;" x-cloak>
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    @if($isPaginator)
        <div class="mb-3 text-right text-xs" style="color:#94a3b8;">{{ $items->links() }}</div>
    @endif

    {{-- ── Griglia contenuti ── --}}
    @if($visibleItems > 0)
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($postItems as $item)
            @php
                $scheduledAt = $item->scheduled_at ? \Illuminate\Support\Carbon::parse($item->scheduled_at) : null;
                $mediaPreview = is_array($item->media_preview ?? null) ? $item->media_preview : [];
                $videoPath = trim((string) ($mediaPreview['video_path'] ?? ''));
                $previewImagePath = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                $isVideo = (bool) ($mediaPreview['is_video'] ?? ($videoPath !== ''));
                $videoUrl = $videoPath !== '' ? asset('storage/' . ltrim($videoPath, '/')) : '';

                $publicationInfo = \App\Support\UiStatus::publication((string) $item->status);
                $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                $latestFeedback = $item->latestFeedbackEntry;

                $filterKey = match(true) {
                    in_array($item->status, ['published']) => 'published',
                    $item->ai_status === 'error' => 'error',
                    in_array($item->status, ['approved','scheduled']) => 'ready',
                    default => 'working',
                };
                $platformLabel = strtoupper((string) $item->platform);
                $formatLabel = strtoupper((string) $item->format);
                $dotColor = match($item->ai_status) {
                    'done'    => '#10b981',
                    'pending' => '#f59e0b',
                    'error'   => '#ef4444',
                    default   => '#cbd5e1',
                };
            @endphp

            <article
                class="lib-card"
                x-show="matchFilter('{{ $filterKey }}', '{{ addslashes(strtolower($item->title ?: '')) }} {{ addslashes(strtolower(\Illuminate\Support\Str::limit($item->ai_caption ?? '', 80))) }}')"
            >
                {{-- Thumbnail (cliccabile) --}}
                <a href="{{ route('posts.edit', $item) }}" class="lib-thumb block">
                    @if($previewImagePath !== '')
                        <img src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}" alt="" loading="lazy" onerror="this.parentElement.style.background='linear-gradient(135deg,#e8edf5,#dce6f0)'">
                    @elseif(!empty($item->ai_image_path))
                        <img src="{{ asset('storage/' . ltrim($item->ai_image_path, '/')) }}" alt="" loading="lazy" onerror="this.parentElement.style.background='linear-gradient(135deg,#e8edf5,#dce6f0)'">
                    @elseif($isVideo && $videoUrl !== '')
                        <video muted playsinline preload="metadata" class="h-full w-full object-cover">
                            <source src="{{ $videoUrl }}" type="video/mp4">
                        </video>
                    @else
                        <div class="flex h-full items-center justify-center">
                            <svg class="h-9 w-9" style="color:rgba(10,45,111,0.15);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    @endif

                    {{-- Badge piattaforma/formato in basso --}}
                    <div class="absolute inset-x-0 bottom-0 flex items-end justify-between px-2.5 py-2" style="background:linear-gradient(to top,rgba(10,20,50,0.55),transparent);">
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white" style="background:rgba(255,255,255,0.12);">
                            {{ $platformLabel }}@if($formatLabel) · {{ $formatLabel }}@endif
                        </span>
                        @if($isVideo)
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold text-white" style="background:rgba(10,45,111,0.5);">Video</span>
                        @endif
                    </div>

                    {{-- Status dot --}}
                    <div class="absolute right-2.5 top-2.5 h-4 w-4 rounded-full" style="background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.12);">
                        <div class="m-1 h-2 w-2 rounded-full" style="background:{{ $dotColor }};"></div>
                    </div>
                </a>

                {{-- Body --}}
                <div style="padding:.875rem 1rem;">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="line-clamp-1 text-sm font-semibold leading-snug" style="color:#1e3a5f;">
                            {{ $item->title ?: ('Post #' . $item->id) }}
                        </h3>
                        @if($scheduledAt)
                            <span class="shrink-0 text-[11px]" style="color:#94a3b8;">{{ $scheduledAt->format('d/m H:i') }}</span>
                        @endif
                    </div>

                    @if($item->ai_caption || $item->caption)
                        <p class="mt-1 line-clamp-2 text-xs leading-relaxed" style="color:#64748b;">
                            {{ $item->ai_caption ?: $item->caption }}
                        </p>
                    @endif

                    <div class="mt-2.5 flex flex-wrap items-center gap-1">
                        <span class="ui-chip {{ $publicationInfo['badge'] ?? '' }} py-0.5 text-[10px]">{{ $publicationInfo['label'] ?? '' }}</span>
                        <span class="ui-chip {{ $aiInfo['badge'] ?? '' }} py-0.5 text-[10px]">AI {{ $aiInfo['label'] ?? '' }}</span>
                        @if($latestFeedback)
                            <span class="ui-chip py-0.5 text-[10px] {{ $latestFeedback->sentiment === 'like' ? 'ui-badge-green' : 'ui-badge-red' }}">
                                {{ $latestFeedback->sentiment === 'like' ? '👍' : '✏️' }} {{ $latestFeedback->sentiment === 'like' ? 'Ok' : 'Da rivedere' }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-3 flex items-center gap-1.5">
                        <a href="{{ route('posts.edit', $item) }}" class="ui-btn-primary flex-1 justify-center py-1.5 text-xs">Apri</a>
                        <a href="{{ route('posts.edit', $item) }}#feedback-loop" class="ui-btn-secondary px-3 py-1.5 text-xs">Feedback</a>
                    </div>
                </div>
            </article>
            @endforeach
        </div>

        <p x-show="allHidden" class="py-12 text-center text-sm" style="color:#94a3b8;" x-cloak>Nessun contenuto corrisponde al filtro selezionato.</p>

    @else
        <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed py-20 text-center" style="border-color:rgba(10,45,111,0.12);background:rgba(248,250,255,0.6);">
            <div class="flex h-14 w-14 items-center justify-center rounded-full" style="background:rgba(10,45,111,0.07);">
                <svg class="h-7 w-7" style="color:#0A2D6F;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
            <h2 class="mt-4 text-lg font-semibold" style="color:#0A2D6F;">Libreria vuota</h2>
            <p class="mt-1.5 max-w-xs text-sm" style="color:#64748b;">Crea il primo contenuto per iniziare a costruire la tua libreria.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <a href="{{ route('posts.create') }}" class="ui-btn-primary gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Crea contenuto
                </a>
                <a href="{{ route('wizard.start') }}" class="ui-btn-secondary">Piano editoriale</a>
            </div>
        </div>
    @endif

    @if($isPaginator && $items->hasPages())
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</div>

<script>
function libreria() {
    return {
        filter: '',
        search: '',
        get allHidden() {
            return [...document.querySelectorAll('article')].every(el => el.style.display === 'none');
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
