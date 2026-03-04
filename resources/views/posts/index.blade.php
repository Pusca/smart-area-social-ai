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

    $aiDone = (int) ($stats['ai_done'] ?? 0);
    $aiQueued = (int) ($stats['ai_queued'] ?? 0);
    $aiError = (int) ($stats['ai_error'] ?? 0);

    $scheduledRate = $totalItems > 0 ? (int) round(($scheduledItems / $totalItems) * 100) : 0;
    $publishedRate = $totalItems > 0 ? (int) round(($publishedItems / $totalItems) * 100) : 0;
    $aiCompletion = $totalItems > 0 ? (int) round(($aiDone / $totalItems) * 100) : 0;

    $statusCounts = is_array($stats['status_counts'] ?? null) ? $stats['status_counts'] : [];
    $todayCount = isset($todayItems) && $todayItems instanceof \Illuminate\Support\Collection ? $todayItems->count() : 0;
@endphp

<style>
    .posts-video-preview {
        display: block;
        width: auto;
        max-width: 100%;
        height: auto;
        max-height: 56vh;
        margin-inline: auto;
        object-fit: contain;
        background: #000;
    }

    @media (min-width: 1024px) {
        .posts-video-preview {
            max-height: 44vh;
        }
    }
</style>

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Content Library</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Gestione contenuti</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Vista operativa su post, avanzamento AI, pubblicazioni e coda da completare.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        {{ $totalItems }} contenuti totali
                    </span>
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        {{ $publishedItems }} pubblicati
                    </span>
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                        {{ $pendingItems }} da completare
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('posts.create') }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Nuovo contenuto
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri calendario
                    </a>
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri wizard
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Avanzamento AI</p>
                <span class="text-xs font-semibold text-indigo-700">{{ $aiCompletion }}%</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $aiDone }} / {{ $totalItems }}</p>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-indigo-600" style="width: {{ $aiCompletion }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Copertura calendario</p>
                <span class="text-xs font-semibold text-cyan-700">{{ $scheduledRate }}%</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $scheduledItems }} / {{ $totalItems }}</p>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-cyan-600" style="width: {{ $scheduledRate }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pubblicazione</p>
                <span class="text-xs font-semibold text-emerald-700">{{ $publishedRate }}%</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $publishedItems }} / {{ $totalItems }}</p>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-emerald-600" style="width: {{ $publishedRate }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Criticita AI</p>
                <span class="text-xs font-semibold {{ $aiError > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ $aiError }} errori</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">{{ $aiQueued }} in coda - {{ $aiDone }} completati</p>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Pipeline contenuti</h2>
                        <p class="mt-1 text-sm text-gray-600">Gestisci copy, stato AI e pubblicazione dei post.</p>
                    </div>
                    <a href="{{ route('content-items.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri galleria
                    </a>
                </div>

                @if($isPaginator)
                    <div class="mt-4">{{ $items->links() }}</div>
                @endif

                @if($visibleItems > 0)
                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
                        @foreach($postItems as $item)
                            @php
                                $scheduledAt = $item->scheduled_at ? \Illuminate\Support\Carbon::parse($item->scheduled_at) : null;
                                $assetRaw = $item->assets ?? [];
                                $assetList = is_string($assetRaw) ? (json_decode($assetRaw, true) ?: []) : (is_array($assetRaw) ? $assetRaw : []);

                                $videoPath = null;
                                foreach ($assetList as $asset) {
                                    if (!is_array($asset)) {
                                        continue;
                                    }
                                    $assetPath = trim((string) ($asset['path'] ?? ''));
                                    if ($assetPath === '') {
                                        continue;
                                    }
                                    $assetType = strtolower(trim((string) ($asset['type'] ?? '')));
                                    $ext = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
                                    if (str_contains($assetType, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
                                        $videoPath = $assetPath;
                                        break;
                                    }
                                }

                                $statusClass = $item->status === 'published'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : (($item->status === 'scheduled')
                                        ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                                        : (($item->status === 'failed')
                                            ? 'border-red-200 bg-red-50 text-red-700'
                                            : 'border-gray-200 bg-gray-50 text-gray-700'));

                                $aiClass = ($item->ai_status === 'done')
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : (($item->ai_status === 'error')
                                        ? 'border-red-200 bg-red-50 text-red-700'
                                        : 'border-amber-200 bg-amber-50 text-amber-700');
                            @endphp

                            <article class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                            {{ strtoupper((string) $item->platform) }} - {{ strtoupper((string) $item->format) }}
                                            @if($scheduledAt)
                                                - {{ $scheduledAt->format('d/m H:i') }}
                                            @endif
                                        </p>
                                        <h3 class="mt-1 truncate text-sm font-semibold text-gray-900">{{ $item->title ?: ('Post #' . $item->id) }}</h3>
                                    </div>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $statusClass }}">
                                        {{ $item->status ?: 'draft' }}
                                    </span>
                                </div>

                                <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                                    <p class="line-clamp-4 text-sm text-gray-600">{{ $item->ai_caption ?: ($item->caption ?: '-') }}</p>
                                </div>

                                @if(!empty($videoPath))
                                    <div class="mt-3 flex justify-center rounded-xl border border-gray-200 bg-black p-1">
                                        <video class="posts-video-preview rounded-lg" controls preload="metadata">
                                            <source src="{{ asset('storage/' . ltrim($videoPath, '/')) }}" type="video/mp4">
                                        </video>
                                    </div>
                                @elseif(!empty($item->ai_image_path))
                                    <img
                                        src="{{ asset('storage/' . ltrim($item->ai_image_path, '/')) }}"
                                        alt="AI image"
                                        class="mt-3 h-28 w-full rounded-xl border border-gray-200 object-cover"
                                        loading="lazy"
                                        onerror="this.remove();"
                                    >
                                @endif

                                <div class="mt-3 flex items-center justify-between">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $aiClass }}">
                                        AI {{ $item->ai_status ?? 'n/a' }}
                                    </span>
                                    <a href="{{ route('posts.edit', $item) }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        Modifica
                                    </a>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if(\Illuminate\Support\Facades\Route::has('ai.content.generate'))
                                        <form action="{{ route('ai.content.generate', $item) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                                                Rigenera AI
                                            </button>
                                        </form>
                                    @endif

                                    <form action="{{ route('posts.destroy', $item) }}" method="POST" onsubmit="return confirm('Confermi eliminazione definitiva di questo post?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                            Elimina
                                        </button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                        <p class="text-sm text-gray-600">Nessun contenuto disponibile. Crea il primo post per attivare il flusso operativo.</p>
                        <a href="{{ route('posts.create') }}" class="mt-4 inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Crea contenuto
                        </a>
                    </div>
                @endif

                @if($isPaginator)
                    <div class="mt-4">{{ $items->links() }}</div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Situazione oggi</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Contenuti previsti oggi</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $todayCount }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Da completare oggi</p>
                        <p class="mt-1 text-xl font-semibold {{ ($todayPending ?? 0) > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $todayPending ?? 0 }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Prossimo slot</p>
                        @if(($nextItem ?? null) && $nextItem->scheduled_at)
                            <p class="mt-1 text-sm font-semibold text-gray-900">
                                {{ \Illuminate\Support\Carbon::parse($nextItem->scheduled_at)->format('d/m H:i') }} - {{ $nextItem->title ?: 'Senza titolo' }}
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-600">Nessun contenuto pianificato.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Stato workflow</h2>
                <p class="mt-1 text-sm text-gray-600">Distribuzione contenuti per stato operativo.</p>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Draft</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['draft'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Review</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['review'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Approved</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['approved'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Scheduled</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['scheduled'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Published</p>
                        <p class="mt-1 text-lg font-semibold text-emerald-700">{{ (int) ($statusCounts['published'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">Failed</p>
                        <p class="mt-1 text-lg font-semibold {{ ((int) ($statusCounts['failed'] ?? 0)) > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ (int) ($statusCounts['failed'] ?? 0) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Collegamenti utili</h2>
                <p class="mt-1 text-sm text-gray-600">Aree collegate alla libreria contenuti.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla pianificazione settimanale.</p>
                    </a>
                    <a href="{{ route('profile.brand') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Profilo Brand</p>
                        <p class="mt-1 text-xs text-gray-600">Aggiorna tono, asset e linee guida.</p>
                    </a>
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Wizard Piano</p>
                        <p class="mt-1 text-xs text-gray-600">Definisci strategia e frequenza.</p>
                    </a>
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Settings</p>
                        <p class="mt-1 text-xs text-gray-600">Configura opzioni operative dell'app.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
