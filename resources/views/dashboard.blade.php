@extends('layouts.app')

@section('content')
@php
    $u = auth()->user();
    $tenantId = $u?->tenant_id;

    $latestPlan = \App\Models\ContentPlan::where('tenant_id', $tenantId)->latest('id')->first();

    $totalItems = \App\Models\ContentItem::where('tenant_id', $tenantId)->count();
    $queuedItems = \App\Models\ContentItem::where('tenant_id', $tenantId)->whereIn('ai_status', ['queued', 'pending'])->count();
    $doneItems = \App\Models\ContentItem::where('tenant_id', $tenantId)->where('ai_status', 'done')->count();
    $errorItems = \App\Models\ContentItem::where('tenant_id', $tenantId)->where('ai_status', 'error')->count();
    $scheduledItems = \App\Models\ContentItem::where('tenant_id', $tenantId)->whereNotNull('scheduled_at')->count();
    $publishedItems = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->where(function ($q) {
            $q->where('status', 'published')->orWhereNotNull('published_at');
        })
        ->count();

    $weekStart = now()->startOfWeek();
    $weekEnd = now()->endOfWeek();
    $weekPlanned = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->whereBetween('scheduled_at', [$weekStart, $weekEnd])
        ->count();
    $weekPublished = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->whereBetween('published_at', [$weekStart, $weekEnd])
        ->count();

    $todayStart = now()->startOfDay();
    $todayEnd = now()->endOfDay();
    $todayItems = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
        ->orderBy('scheduled_at')
        ->get();
    $todayPending = $todayItems->where('status', '!=', 'published')->count();

    $nextItem = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '>=', now())
        ->orderBy('scheduled_at')
        ->first();

    $recentItems = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
        ->orderBy('scheduled_at')
        ->orderByDesc('id')
        ->limit(6)
        ->get();

    $instagramFeedTotal = \App\Models\ContentItem::where('tenant_id', $tenantId)->count();
    $instagramFeedItems = \App\Models\ContentItem::where('tenant_id', $tenantId)
        ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
        ->orderBy('scheduled_at')
        ->orderBy('id')
        ->limit(24)
        ->get();

    $aiCompletion = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;
    $scheduleCoverage = $totalItems > 0 ? (int) round(($scheduledItems / $totalItems) * 100) : 0;
    $publishRate = $totalItems > 0 ? (int) round(($publishedItems / $totalItems) * 100) : 0;
    $errorRate = $totalItems > 0 ? (int) round(($errorItems / $totalItems) * 100) : 0;
    $queueRate = $totalItems > 0 ? (int) round(($queuedItems / $totalItems) * 100) : 0;

    $workspaceScore = (int) round(max(0, min(100, 100 - ($errorRate * 1.5) - ($queueRate * 0.5) + ($publishRate * 0.3))));
    $scoreLabel = $workspaceScore >= 80
        ? 'Ottimo'
        : ($workspaceScore >= 60
            ? 'Buono'
            : ($workspaceScore >= 40 ? 'Attenzione' : 'Critico'));
    $scoreBadgeClass = $workspaceScore >= 80
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : ($workspaceScore >= 60
            ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
            : ($workspaceScore >= 40
                ? 'border-amber-200 bg-amber-50 text-amber-700'
                : 'border-red-200 bg-red-50 text-red-700'));

    $planItemsTotal = 0;
    $planItemsDone = 0;
    $planItemsQueued = 0;
    $planItemsError = 0;
    $planTimeProgress = 0;
    $planOutputProgress = 0;
    $planDaysElapsed = 0;
    $planDaysTotal = 0;

    if ($latestPlan) {
        $planItemsTotal = \App\Models\ContentItem::where('content_plan_id', $latestPlan->id)->count();
        $planItemsDone = \App\Models\ContentItem::where('content_plan_id', $latestPlan->id)->where('ai_status', 'done')->count();
        $planItemsQueued = \App\Models\ContentItem::where('content_plan_id', $latestPlan->id)->whereIn('ai_status', ['queued', 'pending'])->count();
        $planItemsError = \App\Models\ContentItem::where('content_plan_id', $latestPlan->id)->where('ai_status', 'error')->count();

        $start = \Illuminate\Support\Carbon::parse($latestPlan->start_date)->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($latestPlan->end_date)->endOfDay();
        $planDaysTotal = max(1, $start->diffInDays($end) + 1);
        $planDaysElapsed = max(0, min($planDaysTotal, $start->diffInDays(now()->endOfDay()) + 1));
        $planTimeProgress = (int) round(($planDaysElapsed / $planDaysTotal) * 100);
        $planOutputProgress = $planItemsTotal > 0 ? (int) round(($planItemsDone / $planItemsTotal) * 100) : 0;
    }
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Workspace Overview</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                    Ciao {{ $u?->name }}, questa e la situazione generale
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Avanzamento AI, copertura calendario, pubblicazioni e criticita in una sola vista operativa.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $scoreBadgeClass }}">
                        Health Score {{ $workspaceScore }} / 100 - {{ $scoreLabel }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $doneItems }} completati
                    </span>
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                        {{ $queuedItems }} in coda
                    </span>
                    @if($errorItems > 0)
                        <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                            {{ $errorItems }} errori da controllare
                        </span>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Settimana corrente</div>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">Pianificati</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ $weekPlanned }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <div class="text-xs text-gray-500">Pubblicati</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ $weekPublished }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Avanzamento AI</p>
                <span class="text-xs font-semibold text-indigo-700">{{ $aiCompletion }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $doneItems }} / {{ $totalItems }}</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-indigo-600" style="width: {{ $aiCompletion }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Copertura calendario</p>
                <span class="text-xs font-semibold text-indigo-700">{{ $scheduleCoverage }}%</span>
            </div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $scheduledItems }} / {{ $totalItems }}</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-cyan-600" style="width: {{ $scheduleCoverage }}%"></div>
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
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $errorItems }} errori</div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full {{ $errorRate > 0 ? 'bg-red-500' : 'bg-gray-300' }}" style="width: {{ max(4, $errorRate) }}%"></div>
            </div>
        </article>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Griglia pubblicazioni</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Vista in stile Instagram ordinata per data pianificata e ordine post.
                </p>
            </div>
            <div class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">
                {{ $instagramFeedItems->count() }} su {{ $instagramFeedTotal }} post
            </div>
        </div>

        @if($instagramFeedItems->isEmpty())
            <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                <p class="text-sm text-gray-600">Nessun post disponibile per la griglia.</p>
                <a href="{{ route('posts.create') }}" class="mt-4 inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
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
                            $gridDotClass = $gridItem->status === 'published'
                                ? 'bg-emerald-500'
                                : (($gridItem->status === 'scheduled')
                                    ? 'bg-indigo-500'
                                    : (($gridItem->status === 'failed') ? 'bg-red-500' : 'bg-gray-500'));

                            $assetRaw = $gridItem->assets ?? [];
                            $assetList = is_string($assetRaw) ? (json_decode($assetRaw, true) ?: []) : (is_array($assetRaw) ? $assetRaw : []);
                            $gridVideoPath = null;
                            $gridVideoThumbPath = trim((string) data_get($gridItem->ai_meta, 'video_generation.thumbnail_path', ''));
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
                                    $gridVideoPath = $assetPath;
                                    break;
                                }
                                if ($gridVideoThumbPath === '' && (str_contains($assetType, 'thumbnail') || str_contains($assetType, 'thumb'))) {
                                    $gridVideoThumbPath = $assetPath;
                                }
                            }
                        @endphp
                        <a href="{{ route('posts.edit', $gridItem) }}" class="group relative aspect-square overflow-hidden rounded-lg border border-gray-100 bg-gray-100">
                            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-200 to-gray-100 text-[11px] font-semibold text-gray-500">
                                #{{ $loop->iteration }}
                            </div>

                            @if(!empty($gridVideoPath))
                                <video
                                    class="relative z-10 h-full w-full object-cover pointer-events-none"
                                    muted
                                    playsinline
                                    preload="metadata"
                                    data-feed-video-preview
                                    @if(!empty($gridVideoThumbPath))
                                        poster="{{ asset('storage/' . ltrim($gridVideoThumbPath, '/')) }}"
                                    @endif
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
                            @if(!empty($gridVideoPath))
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
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Stato piano attivo</h2>
                        <p class="mt-1 text-sm text-gray-600">Confronto tra avanzamento temporale e output generati.</p>
                    </div>
                    @if($latestPlan)
                        <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                Genera AI piano
                            </button>
                        </form>
                    @endif
                </div>

                @if(!$latestPlan)
                    <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                        <p class="text-sm text-gray-600">Nessun piano disponibile. Crea il primo piano per attivare il monitoraggio.</p>
                        <a href="{{ route('wizard.start') }}" class="mt-4 inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
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
                            <p class="text-xs text-gray-500">In coda</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $planItemsQueued }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Completati</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $planItemsDone }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Errori</p>
                            <p class="mt-1 text-lg font-semibold {{ $planItemsError > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $planItemsError }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ultimi contenuti</h2>
                        <p class="mt-1 text-sm text-gray-600">Controllo rapido su stato e output recenti.</p>
                    </div>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                </div>

                <div class="mt-4 divide-y rounded-xl border border-gray-200">
                    @forelse($recentItems as $item)
                        @php
                            $badgeClass = ($item->ai_status === 'done')
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : (($item->ai_status === 'error')
                                    ? 'border-red-200 bg-red-50 text-red-700'
                                    : 'border-amber-200 bg-amber-50 text-amber-700');
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
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">
                                {{ $item->ai_status ?: 'n/a' }}
                            </span>
                        </a>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-gray-600">Nessun contenuto recente.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Situazione oggi</h2>
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
                <h2 class="text-lg font-semibold text-gray-900">Funzioni principali</h2>
                <p class="mt-1 text-sm text-gray-600">Accesso rapido alle aree operative.</p>

                <div class="mt-4 space-y-2">
                    <a href="{{ route('profile.brand') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Profilo Brand</p>
                        <p class="mt-1 text-xs text-gray-600">Aggiorna dati azienda, tone e assets.</p>
                    </a>
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Nuovo Piano</p>
                        <p class="mt-1 text-xs text-gray-600">Imposta strategia e calendario.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla uscite e copertura settimanale.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria Contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci bozze, output AI e pubblicazione.</p>
                    </a>
                    <a href="{{ route('posts.create') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Nuovo Contenuto</p>
                        <p class="mt-1 text-xs text-gray-600">Crea manualmente un item operativo.</p>
                    </a>
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Settings</p>
                        <p class="mt-1 text-xs text-gray-600">Configura notifiche e opzioni app.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('video[data-feed-video-preview]').forEach(function (video) {
        const trySeek = function () {
            try {
                const t = Math.min(0.12, Math.max(0.02, (video.duration || 1) * 0.02));
                video.currentTime = t;
            } catch (_) {}
        };

        video.addEventListener('loadedmetadata', trySeek, { once: true });
        video.addEventListener('loadeddata', trySeek, { once: true });
        video.addEventListener('canplay', trySeek, { once: true });
        video.addEventListener('seeked', function () {
            try { video.pause(); } catch (_) {}
        }, { once: true });
    });
});
</script>
@endsection
