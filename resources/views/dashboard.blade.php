@extends('layouts.app')

@section('content')
@php
    $uiPublication = fn ($status) => \App\Support\UiStatus::publication((string) $status);
    $uiAi = fn ($status) => \App\Support\UiStatus::ai((string) $status);
@endphp

<section class="w-full max-w-full space-y-6 overflow-x-hidden px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-[2rem] border border-app bg-white/92 p-6 shadow-panel lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div class="min-w-0">
                <div class="inline-flex items-center gap-2 rounded-full border border-brand bg-brand-soft px-3 py-1 text-xs font-semibold text-brand">
                    <x-application-logo variant="icon" class="h-5 w-5" />
                    Panoramica workspace
                </div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                    Ciao {{ $u?->name }}, ecco cosa si muove oggi
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Contenuti, calendario e pubblicazioni in una vista semplice da leggere, cosi sai subito dove intervenire.
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="{{ route('settings') }}" class="ui-btn-secondary">
                        Apri setup
                    </a>
                    <a href="{{ route('posts.create') }}" class="ui-btn-primary">
                        Apri area crea
                    </a>
                    <a href="{{ route('calendar') }}" class="ui-btn-secondary">
                        Vai alla pianificazione
                    </a>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $scoreBadgeClass }}">
                        Salute workspace {{ $workspaceScore }} / 100 - {{ $scoreLabel }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $doneItems }} pronti
                    </span>
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                        {{ $queuedItems }} in lavorazione
                    </span>
                    @if($errorItems > 0)
                        <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                            {{ $errorItems }} da rivedere
                        </span>
                    @endif
                </div>
            </div>

            <div class="min-w-0 rounded-[1.75rem] border border-brand bg-[var(--gradient-soft)] p-5 shadow-card">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-brand">Flusso del giorno</div>
                        <p class="mt-2 text-sm text-muted">Le azioni piu utili per non perdere ritmo tra creazione e pubblicazione.</p>
                    </div>
                    <x-application-logo variant="icon" class="h-12 w-12" />
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-app bg-white/90 p-3">
                        <div class="text-xs text-gray-500">In calendario</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ $weekPlanned }}</div>
                    </div>
                    <div class="rounded-2xl border border-app bg-white/90 p-3">
                        <div class="text-xs text-gray-500">Pubblicati</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ $weekPublished }}</div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <a href="{{ route('posts.create') }}" class="ui-btn-primary justify-center">
                        Crea contenuto
                    </a>
                    <a href="{{ route('posts.create') }}?preset=reel" class="ui-btn-secondary justify-center">
                        Crea reel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Contenuti generati</p>
                <span class="text-xs font-semibold text-brand">{{ $aiCompletion }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $doneItems }} / {{ $totalItems }}</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full" style="width: {{ $aiCompletion }}%; background: var(--gradient);"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Copertura calendario</p>
                <span class="text-xs font-semibold text-brand">{{ $scheduleCoverage }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $scheduledItems }} / {{ $totalItems }}</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full" style="width: {{ $scheduleCoverage }}%; background: linear-gradient(90deg, #2B6DD2 0%, #53C8E8 100%);"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pubblicazione</p>
                <span class="text-xs font-semibold text-emerald-700">{{ $publishRate }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $publishedItems }} / {{ $totalItems }}</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-emerald-600" style="width: {{ $publishRate }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Criticita</p>
                <span class="text-xs font-semibold {{ $errorRate > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ $errorRate }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $errorItems }} da verificare</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full {{ $errorRate > 0 ? 'bg-red-500' : 'bg-gray-300' }}" style="width: {{ max(4, $errorRate) }}%"></div>
            </div>
        </article>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Anteprima feed</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Vista ordinata dei post in uscita, utile per capire ritmo e coerenza del feed.
                </p>
            </div>
            <div class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">
                {{ $instagramFeedItems->count() }} su {{ $instagramFeedTotal }} post
            </div>
        </div>

        @if($instagramFeedItems->isEmpty())
            <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                <p class="text-sm text-gray-600">Nessun post disponibile per la griglia.</p>
                <a href="{{ route('posts.create') }}" class="ui-btn-primary mt-4">
                    Crea primo post
                </a>
            </div>
        @else
            <div class="mx-auto mt-4 w-full max-w-3xl">
                <div class="grid grid-cols-3 gap-1.5 sm:gap-2">
                    @foreach($instagramFeedItems as $gridItem)
                        @php
                            $gridDate = $gridItem->scheduled_at ?: $gridItem->created_at;
                            $gridDateLabel = $gridDate ? $gridDate->format('d/m H:i') : 'Senza data';
                            $gridPublicationInfo = $uiPublication($gridItem->status);
                            $gridDotClass = $gridPublicationInfo['tone'] === 'success'
                                ? 'bg-emerald-500'
                                : (($gridPublicationInfo['tone'] === 'info')
                                    ? 'bg-indigo-500'
                                    : (($gridPublicationInfo['tone'] === 'danger') ? 'bg-red-500' : 'bg-gray-500'));
                            $gridPreview = is_array($gridItem->media_preview ?? null) ? $gridItem->media_preview : [];
                            $gridVideoPath = trim((string) ($gridPreview['video_path'] ?? ''));
                            $gridPreviewImagePath = trim((string) ($gridPreview['preview_image_path'] ?? ''));
                            $gridIsVideo = (bool) ($gridPreview['is_video'] ?? ($gridVideoPath !== ''));
                        @endphp
                        <a href="{{ route('posts.edit', $gridItem) }}" class="group relative aspect-square overflow-hidden rounded-lg border border-gray-100 bg-gray-100">
                            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-200 to-gray-100 text-[11px] font-semibold text-gray-500">
                                #{{ $loop->iteration }}
                            </div>

                            @if(!empty($gridPreviewImagePath))
                                <img
                                    src="{{ asset('storage/' . ltrim($gridPreviewImagePath, '/')) }}"
                                    alt="{{ $gridItem->title ?: ('Post #' . $gridItem->id) }}"
                                    class="relative z-10 h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]"
                                    loading="lazy"
                                    onerror="this.remove();"
                                >
                            @elseif($gridIsVideo)
                                <video
                                    class="relative z-10 h-full w-full object-cover pointer-events-none"
                                    muted
                                    playsinline
                                    preload="metadata"
                                    data-frame-preview
                                >
                                    <source src="{{ asset('storage/' . ltrim($gridVideoPath, '/')) }}">
                                </video>
                            @elseif(!empty($gridItem->ai_image_path))
                                <img
                                    src="{{ asset('storage/' . ltrim($gridItem->ai_image_path, '/')) }}"
                                    alt="{{ $gridItem->title ?: ('Post #' . $gridItem->id) }}"
                                    class="relative z-10 h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]"
                                    loading="lazy"
                                    onerror="this.remove();"
                                >
                            @endif

                            <div class="absolute left-1.5 top-1.5 z-20 h-2.5 w-2.5 rounded-full {{ $gridDotClass }} ring-1 ring-white/80"></div>
                            @if($gridIsVideo)
                                <div class="absolute right-1.5 top-1.5 z-20 inline-flex items-center rounded bg-black/60 px-1 py-0.5 text-[10px] font-semibold text-white">
                                    video
                                </div>
                            @endif

                            <div class="absolute inset-x-0 bottom-0 z-20 bg-gradient-to-t from-black/70 via-black/30 to-transparent p-1.5 opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                <p class="truncate text-[10px] font-semibold text-white">{{ $gridItem->title ?: ('Post #' . $gridItem->id) }}</p>
                                <p class="text-[10px] text-white/85">{{ $gridDateLabel }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="min-w-0 space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Stato del piano attivo</h2>
                        <p class="mt-1 text-sm text-gray-600">Confronto chiaro tra tempo passato e contenuti gia pronti.</p>
                    </div>
                    @if($latestPlan)
                        <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}">
                            @csrf
                            <button class="ui-btn-primary">
                                Genera i contenuti del piano
                            </button>
                        </form>
                    @endif
                </div>

                @if(!$latestPlan)
                    <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                        <p class="text-sm text-gray-600">Non hai ancora un piano attivo. Creane uno per vedere carico, ritmo e copertura del calendario.</p>
                        <a href="{{ route('wizard.start') }}" class="ui-btn-primary mt-4">
                            Crea piano editoriale
                        </a>
                    </div>
                @else
                    <div class="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Piano attivo</p>
                                <p class="font-semibold text-gray-900">{{ $latestPlan->name }}</p>
                            </div>
                            <div class="text-sm text-gray-600">
                                {{ \Illuminate\Support\Carbon::parse($latestPlan->start_date)->format('d/m/Y') }}
                                -
                                {{ \Illuminate\Support\Carbon::parse($latestPlan->end_date)->format('d/m/Y') }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Progresso temporale</p>
                                <span class="text-xs font-semibold text-indigo-700">{{ $planTimeProgress }}%</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ $planDaysElapsed }} giorni trascorsi su {{ $planDaysTotal }}</p>
                            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-indigo-600" style="width: {{ $planTimeProgress }}%"></div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Output completati</p>
                                <span class="text-xs font-semibold text-emerald-700">{{ $planOutputProgress }}%</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ $planItemsDone }} completati su {{ $planItemsTotal }}</p>
                            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-emerald-600" style="width: {{ $planOutputProgress }}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">In lavorazione</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $planItemsQueued }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Completati</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $planItemsDone }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Da verificare</p>
                            <p class="mt-1 text-lg font-semibold {{ $planItemsError > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $planItemsError }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ultimi contenuti</h2>
                        <p class="mt-1 text-sm text-gray-600">Un colpo d'occhio su cosa e pronto, in lavorazione o da rivedere.</p>
                    </div>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                </div>

                <div class="mt-4 divide-y rounded-xl border border-gray-200">
                    @forelse($recentItems as $item)
                        @php
                            $recentAiInfo = $uiAi($item->ai_status);
                        @endphp
                        <a href="{{ route('posts.edit', $item) }}" class="flex items-start justify-between gap-4 px-4 py-3 hover:bg-gray-50">
                            <div class="min-w-0">
                                <p class="text-xs text-gray-500">
                                    {{ strtoupper((string) $item->platform) }} - {{ strtoupper((string) $item->format) }}
                                    @if($item->scheduled_at)
                                        - {{ optional($item->scheduled_at)->format('d/m H:i') }}
                                    @endif
                                </p>
                                <p class="mt-1 truncate text-sm font-semibold text-gray-900">{{ $item->title ?: 'Senza titolo' }}</p>
                                <p class="mt-1 line-clamp-2 text-xs text-gray-600">{{ $item->ai_caption ?: ($item->caption ?: '-') }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $recentAiInfo['badge'] }}">
                                AI {{ $recentAiInfo['label'] }}
                            </span>
                        </a>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-gray-600">Nessun contenuto recente.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="min-w-0 space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Cosa succede oggi</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Contenuti previsti oggi</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $todayItems->count() }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Da completare oggi</p>
                        <p class="mt-1 text-xl font-semibold {{ $todayPending > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $todayPending }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Prossimo contenuto in agenda</p>
                        @if($nextItem && $nextItem->scheduled_at)
                            <p class="mt-1 text-sm font-semibold text-gray-900">
                                {{ $nextItem->scheduled_at->format('d/m H:i') }} - {{ $nextItem->title ?: 'Senza titolo' }}
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-600">Nessun contenuto pianificato.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Mappa del workspace</h2>
                <p class="mt-1 text-sm text-gray-600">Le aree giuste, nell'ordine in cui di solito ti servono durante la giornata.</p>

                <div class="mt-4 space-y-2">
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Setup workspace</p>
                        <p class="mt-1 text-xs text-gray-600">Sistema brand, asset e connessioni una volta sola, poi lavori piu veloce ovunque.</p>
                    </a>
                    <a href="{{ route('posts.create') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Crea</p>
                        <p class="mt-1 text-xs text-gray-600">Apri l'area per contenuti singoli, reel o nuovi piani.</p>
                    </a>
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Piano editoriale</p>
                        <p class="mt-1 text-xs text-gray-600">Imposti un insieme di contenuti con una logica di periodo.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Pianifica</p>
                        <p class="mt-1 text-xs text-gray-600">Controlli uscite, approvazioni e copertura del calendario.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Rivedi bozze, output AI e stato di pubblicazione.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection


