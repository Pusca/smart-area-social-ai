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
    $workflowLabels = \App\Support\UiStatus::publicationOptions();
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Libreria contenuti</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Tutti i contenuti in un solo posto</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Qui rivedi cosa e stato creato, cosa sta ancora lavorando e cosa e pronto per andare in calendario o online.
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
                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <a href="{{ route('posts.create') }}" class="ui-btn-primary justify-center">
                        Apri area crea
                    </a>
                    <a href="{{ route('posts.reels.create') }}" class="ui-btn-primary justify-center">
                        Crea reel
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Vai alla pianificazione
                    </a>
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Piano editoriale
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
                <span class="text-xs font-semibold {{ $aiError > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ $aiError }} da verificare</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">{{ $aiQueued }} in lavorazione - {{ $aiDone }} completati</p>
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
                                $mediaPreview = is_array($item->media_preview ?? null) ? $item->media_preview : [];
                                $videoPath = trim((string) ($mediaPreview['video_path'] ?? ''));
                                $previewImagePath = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                                $isVideo = (bool) ($mediaPreview['is_video'] ?? ($videoPath !== ''));
                                $videoUrl = $videoPath !== '' ? asset('storage/' . ltrim($videoPath, '/')) : '';

                                $publicationInfo = \App\Support\UiStatus::publication((string) $item->status);
                                $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                                $latestFeedback = $item->latestFeedbackEntry;
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
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $publicationInfo['badge'] }}">
                                        {{ $publicationInfo['label'] }}
                                    </span>
                                </div>

                                <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                                    <p class="line-clamp-4 text-sm text-gray-600">{{ $item->ai_caption ?: ($item->caption ?: '-') }}</p>
                                </div>

                                @if(!empty($previewImagePath))
                                    <div class="relative mt-3">
                                        <img
                                            src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}"
                                            alt="Preview contenuto"
                                            class="h-28 w-full rounded-xl border border-gray-200 object-cover bg-gray-100"
                                            loading="lazy"
                                            onerror="this.remove();"
                                        >
                                        @if($isVideo)
                                            <span class="absolute right-2 top-2 inline-flex items-center rounded-md bg-black/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                video
                                            </span>
                                        @endif
                                    </div>
                                @elseif(!empty($item->ai_image_path))
                                    <img
                                        src="{{ asset('storage/' . ltrim($item->ai_image_path, '/')) }}"
                                        alt="AI image"
                                        class="mt-3 h-28 w-full rounded-xl border border-gray-200 object-cover"
                                        loading="lazy"
                                        onerror="this.remove();"
                                    >
                                @elseif($isVideo)
                                    <div class="relative mt-3 rounded-xl border border-gray-200 bg-black">
                                        <video
                                            class="h-28 w-full rounded-xl object-cover"
                                            muted
                                            playsinline
                                            preload="metadata"
                                            data-frame-preview
                                        >
                                            <source src="{{ $videoUrl }}" type="video/mp4">
                                        </video>
                                        <span class="absolute right-2 top-2 inline-flex items-center rounded-md bg-black/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                            video
                                        </span>
                                    </div>
                                @endif

                                <div class="mt-3 flex items-center justify-between">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $aiInfo['badge'] }}">
                                            AI {{ $aiInfo['label'] }}
                                        </span>
                                        @if($latestFeedback)
                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $latestFeedback->sentiment === 'like' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                                {{ $latestFeedback->sentiment === 'like' ? 'Piaciuto' : 'Da correggere' }}
                                            </span>
                                        @endif
                                    </div>
                                    <a href="{{ route('posts.edit', $item) }}#feedback-loop" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        Modifica / feedback
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
                        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                            <a href="{{ route('posts.reels.create') }}" class="ui-btn-primary">
                                Crea reel
                            </a>
                            <a href="{{ route('posts.create') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Crea contenuto
                            </a>
                        </div>
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
                        <p class="text-xs text-gray-500">{{ $workflowLabels['draft'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['draft'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">{{ $workflowLabels['review'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['review'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">{{ $workflowLabels['approved'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['approved'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">{{ $workflowLabels['scheduled'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($statusCounts['scheduled'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">{{ $workflowLabels['published'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-emerald-700">{{ (int) ($statusCounts['published'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs text-gray-500">{{ $workflowLabels['failed'] }}</p>
                        <p class="mt-1 text-lg font-semibold {{ ((int) ($statusCounts['failed'] ?? 0)) > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ (int) ($statusCounts['failed'] ?? 0) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Dove andare dopo</h2>
                <p class="mt-1 text-sm text-gray-600">I collegamenti piu utili quando hai finito di rivedere la libreria.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('setup.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Setup workspace</p>
                        <p class="mt-1 text-xs text-gray-600">Sistema brand, asset e connessioni che guideranno le prossime generazioni.</p>
                    </a>
                    <a href="{{ route('posts.create') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Area crea</p>
                        <p class="mt-1 text-xs text-gray-600">Apri il punto di partenza per contenuti singoli, reel o piani.</p>
                    </a>
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Piano editoriale</p>
                        <p class="mt-1 text-xs text-gray-600">Costruisci un insieme di contenuti con logica di periodo.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Pianifica</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla la distribuzione dei contenuti e le prossime uscite.</p>
                    </a>
                    <a href="{{ route('ai') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">AI Lab</p>
                        <p class="mt-1 text-xs text-gray-600">Apri il flusso rapido separato se ti serve un test o un output piu leggero.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

