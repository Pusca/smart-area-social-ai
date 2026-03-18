@extends('layouts.app')

@section('content')
@php
    $platformOptions = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'threads' => 'Threads',
    ];

    $formatOptions = [
        'post' => 'Post',
        'reel' => 'Reel',
        'story' => 'Story',
    ];

    $videoProviderOptions = [
        'openai' => 'Sora + GPT (OpenAI)',
        'runway' => 'Runway',
        'kling' => 'Kling (coerenza persona)',
    ];
    $imageProviderOptions = [
        'nanobanana' => 'Nano Banana (consigliato)',
        'openai' => 'GPT Image (OpenAI)',
    ];

    $tz = config('app.timezone', 'Europe/Rome');
    $createPreset = ($createPreset ?? request()->query('preset', 'default'));
    $createPreset = $createPreset === 'reel' ? 'reel' : 'default';
    $isReelPreset = $createPreset === 'reel';
    $reelCreateUrl = route('posts.reels.create');
    $defaultPlatforms = old('platforms', $profile?->default_platforms ?? ['instagram']);
    if (!is_array($defaultPlatforms)) {
        $defaultPlatforms = ['instagram'];
    }

    $defaultFormat = old('format', $isReelPreset
        ? 'reel'
        : ((is_array($profile?->default_formats ?? null) && !empty($profile->default_formats))
            ? (string) $profile->default_formats[0]
            : 'post'));
    $defaultVideoProvider = old('video_provider', $isReelPreset
        ? 'runway'
        : (string) config('generation.video_provider_default', 'openai'));
    if (!array_key_exists($defaultVideoProvider, $videoProviderOptions)) {
        $defaultVideoProvider = 'openai';
    }
    $defaultImageProvider = old('image_provider', (string) config('generation.image_provider_default', 'nanobanana'));
    if (!array_key_exists($defaultImageProvider, $imageProviderOptions)) {
        $defaultImageProvider = 'nanobanana';
    }

    $defaultSchedule = old('scheduled_at', now($tz)->addHour()->startOfHour()->format('Y-m-d\\TH:i'));

    $referenceImages = $referenceImages ?? [];
    if ($referenceImages instanceof \Illuminate\Support\Collection) {
        $referenceImages = $referenceImages->values()->all();
    }
    if (!is_array($referenceImages)) {
        $referenceImages = [];
    }

    $selectedReferenceIds = old('reference_asset_ids', []);
    if (!is_array($selectedReferenceIds)) {
        $selectedReferenceIds = [];
    }
    $selectedReferenceIds = array_values(array_unique(array_map(
        fn ($v) => (int) $v,
        array_filter($selectedReferenceIds, fn ($v) => (int) $v > 0)
    )));

    $assetVariables = is_array($assetVariables ?? null) ? $assetVariables : [];
    $selectedVariableIds = old('asset_variable_ids', []);
    if (!is_array($selectedVariableIds)) {
        $selectedVariableIds = [];
    }
    $selectedVariableIds = array_values(array_unique(array_map(
        fn ($v) => (int) $v,
        array_filter($selectedVariableIds, fn ($v) => (int) $v > 0)
    )));
    $presenterVariables = array_values(array_filter($assetVariables, fn ($var) => (($var['kind'] ?? null) === 'person')));
    $productVariables = array_values(array_filter($assetVariables, fn ($var) => (($var['kind'] ?? null) === 'product')));
    $placeVariables = array_values(array_filter($assetVariables, fn ($var) => (($var['kind'] ?? null) === 'location')));
    $selectedPresenterId = (int) old('presenter_variable_id', 0);
    $selectedProductId = (int) old('product_variable_id', 0);
    $selectedPlaceId = (int) old('place_variable_id', 0);
    $selectedConsistencyMode = old('consistency_mode', 'balanced');
    $seasonalOverlay = old('seasonal_overlay', '');
    $consistencyModeOptions = [
        'strict' => 'Strict',
        'balanced' => 'Balanced',
        'creative' => 'Creative',
    ];
    $briefPlaceholder = $isReelPreset
        ? "Es. Crea un reel per Instagram che apra con un hook forte, mostri due o tre scene reali del locale e chiuda con un payoff visivo coerente col brand."
        : "Es. Crea un post su Jaguar usando l'ultima immagine caricata e il logo sullo sfondo.";
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Area Crea</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                    {{ $isReelPreset ? 'Crea un reel singolo pensato per il feed' : 'Scegli se partire da un contenuto singolo o da un piano editoriale' }}
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    {{ $isReelPreset
                        ? 'Qui lavori su un solo reel alla volta: hook, anchor frame, ritmo visivo e direzione brand vengono preparati per aiutare meglio Runway.'
                        : 'Qui puoi creare un contenuto singolo on demand oppure impostare un piano editoriale con almeno 2 contenuti e una logica di insieme.' }}
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        {{ $isReelPreset ? 'Reel singolo + blueprint video' : 'Contenuto singolo o piano' }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        AI + scheduling + strategy
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                    <a href="{{ $reelCreateUrl }}" class="ui-btn-primary justify-center">
                        Crea reel
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Vai alla pianificazione
                    </a>
                    <a href="{{ route('setup.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri setup
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 {{ $isReelPreset ? 'lg:grid-cols-2' : 'lg:grid-cols-3' }}">
        <article id="single-content-option" class="rounded-3xl border {{ $isReelPreset ? 'border-gray-200 bg-white' : 'border-indigo-200 bg-indigo-50/60' }} p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide {{ $isReelPreset ? 'text-gray-500' : 'text-indigo-700' }}">Contenuto singolo</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">Quando hai gia un'idea precisa e vuoi produrla subito</h2>
                </div>
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $isReelPreset ? 'border-gray-200 bg-white text-gray-600' : 'border-indigo-200 bg-white text-indigo-700' }}">
                    {{ $isReelPreset ? 'Disponibile' : 'Attivo ora' }}
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                Perfetto per un post, un reel o una story specifica. Dai il brief, agganci immagini e variabili, scegli la programmazione e generi subito.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="ui-chip">1 contenuto</span>
                <span class="ui-chip">Piu veloce</span>
                <span class="ui-chip">Controllo puntuale</span>
            </div>
            <div class="mt-5">
                <a href="{{ $isReelPreset ? route('posts.create') . '#single-content-form' : '#single-content-form' }}" class="{{ $isReelPreset ? 'ui-btn-secondary' : 'ui-btn-primary' }}">
                    Continua con contenuto singolo
                </a>
            </div>
        </article>

        <article class="rounded-3xl border {{ $isReelPreset ? 'border-indigo-200 bg-indigo-50/60' : 'border-gray-200 bg-white' }} p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide {{ $isReelPreset ? 'text-indigo-700' : 'text-gray-500' }}">Sezione reel</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">Un solo reel, costruito come contenuto singolo</h2>
                </div>
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $isReelPreset ? 'border-indigo-200 bg-white text-indigo-700' : 'border-gray-200 bg-white text-gray-600' }}">
                    {{ $isReelPreset ? 'Runway image-to-video' : 'Editor reel dedicato' }}
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                La macchina prepara un blueprint breve con hook, anchor frame, progressione degli shot e payoff finale. Poi traduce tutto in un input piu adatto a Runway, che oggi lavora meglio su una clip coerente che su uno storyboard confuso.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="ui-chip">Reel singolo</span>
                <span class="ui-chip">Anchor frame forte</span>
                <span class="ui-chip">Shot plan guidato</span>
            </div>
            <div class="mt-5">
                <a href="{{ $isReelPreset ? '#single-content-form' : $reelCreateUrl . '#single-content-form' }}" class="{{ $isReelPreset ? 'ui-btn-primary' : 'ui-btn-secondary' }}">
                    {{ $isReelPreset ? 'Continua con reel' : 'Apri editor reel' }}
                </a>
            </div>
        </article>

        @unless($isReelPreset)
        <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand">Piano editoriale</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">Quando vuoi creare piu contenuti con logica di insieme</h2>
                </div>
                <span class="inline-flex items-center rounded-full border border-brand bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand">
                    Minimo 2
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                Ideale se vuoi pianificare una mini sequenza o un periodo completo. Qui entra in gioco il piano editoriale, con distribuzione dei contenuti e struttura piu strategica.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="ui-chip">Da 2 contenuti in su</span>
                <span class="ui-chip">Visione d'insieme</span>
                <span class="ui-chip">Calendario strutturato</span>
            </div>
            <div class="mt-5">
                <a href="{{ route('wizard.start') }}" class="ui-btn-secondary">
                    Vai al piano editoriale
                </a>
            </div>
        </article>
        @endunless
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Controlla i campi richiesti:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <form id="single-content-form" method="POST" action="{{ route('posts.store') }}" class="space-y-6">
                @csrf

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $isReelPreset ? 'Crea reel' : 'Contenuto singolo' }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $isReelPreset ? 'Qui stai creando un reel singolo guidato: hook, anchor frame, shot plan e resa social vengono ordinati prima di arrivare a Runway.' : 'Qui stai creando un singolo contenuto on demand.' }}
                    </p>

                    <div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50/50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ $isReelPreset ? 'Modalita reel attiva' : 'Se vuoi un insieme di contenuti' }}</p>
                        <p class="mt-1 text-sm text-gray-700">
                            @if($isReelPreset)
                                Il formato reel parte gia attivo. Descrivi bene hook iniziale, soggetto principale, micro-progressione delle scene e payoff finale: la macchina costruira un blueprint sintetico e lo tradurra in un input piu adatto a Runway.
                            @else
                                Per una sequenza di pubblicazioni o un piano editoriale usa il <a href="{{ route('wizard.start') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">piano editoriale</a>, che lavora da minimo 2 contenuti.
                            @endif
                        </p>
                    </div>

                    <h3 class="mt-5 text-lg font-semibold text-gray-900">Dettagli pubblicazione</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $isReelPreset ? 'Quando pubblicare il reel e con quale motore video lavorare.' : 'Quando pubblicare e in quale formato.' }}</p>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="scheduled_at" class="mb-1 block text-sm font-semibold text-gray-700">Programmazione</label>
                            <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ $defaultSchedule }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                        </div>
                        <div>
                            <label for="format" class="mb-1 block text-sm font-semibold text-gray-700">Formato</label>
                            @if($isReelPreset)
                                <input type="hidden" id="format" name="format" value="reel" />
                                <div class="flex h-[46px] items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-sm font-semibold text-indigo-700">
                                    Reel
                                </div>
                            @else
                                <select id="format" name="format" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                                    @foreach($formatOptions as $key => $label)
                                        <option value="{{ $key }}" @selected($defaultFormat === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        @unless($isReelPreset)
                        <div class="sm:col-span-2">
                            <label for="image_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore immagini</label>
                            <select id="image_provider" name="image_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($imageProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultImageProvider === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                La base resta Nano Banana. Qui puoi fare un test diverso solo per questo contenuto singolo, senza cambiare il resto dell'app.
                            </p>
                        </div>
                        @endunless
                        <div class="sm:col-span-2">
                            <label for="video_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore video (solo reel/story)</label>
                            <select id="video_provider" name="video_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($videoProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultVideoProvider === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Per i post immagine il provider video viene ignorato. La modalita reel continua a partire su Runway, mentre Kling e pensato soprattutto per mantenere piu stabile lo stesso soggetto o la stessa persona nei video.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Social target</h2>
                        <span class="text-xs font-semibold text-gray-500">Selezione multipla</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($platformOptions as $key => $label)
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                <input type="checkbox" name="platforms[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($key, $defaultPlatforms, true)) />
                                <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">Riferimenti foto numerati</h2>
                        <span class="text-xs font-semibold text-gray-500">{{ count($referenceImages) }} disponibili</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        Seleziona una o piu immagini precise (es. 3 foto volto + 1 foto auto). Puoi anche scrivere nel brief: <span class="font-semibold">usa foto #1 #2 #3 e #7</span>.
                    </p>

                    @if(empty($referenceImages))
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                            Nessuna immagine brand disponibile. Caricale in <a href="{{ route('profile.brand') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">Brand Center</a>.
                        </div>
                    @else
                        <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                            @foreach($referenceImages as $img)
                                @php
                                    $assetId = (int) ($img['id'] ?? 0);
                                    $refNumber = (int) ($img['ref_number'] ?? ($loop->index + 1));
                                    $assetPath = (string) ($img['path'] ?? '');
                                    $assetName = trim((string) ($img['original_name'] ?? ''));
                                    $isChecked = $assetId > 0 && in_array($assetId, $selectedReferenceIds, true);
                                @endphp
                                <label class="group relative overflow-hidden rounded-xl border border-gray-200 bg-white transition hover:border-indigo-300 hover:shadow-sm">
                                    <div class="absolute left-2 top-2 z-10 inline-flex items-center rounded-full bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white">
                                        #{{ $refNumber }}
                                    </div>
                                    <div class="absolute right-2 top-2 z-10 rounded-md border border-white/80 bg-white/90 p-1">
                                        <input
                                            type="checkbox"
                                            name="reference_asset_ids[]"
                                            value="{{ $assetId }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @checked($isChecked)
                                        />
                                    </div>
                                    <div class="aspect-square overflow-hidden bg-gray-100">
                                        <img
                                            src="{{ asset('storage/' . ltrim($assetPath, '/')) }}"
                                            alt="Reference #{{ $refNumber }}"
                                            class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]"
                                            loading="lazy"
                                        />
                                    </div>
                                    <div class="border-t border-gray-200 px-2 py-1.5 text-[11px] text-gray-600">
                                        {{ $assetName !== '' ? \Illuminate\Support\Str::limit($assetName, 32, '...') : basename($assetPath) }}
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">Identita da preservare</h2>
                        <span class="text-xs font-semibold text-gray-500">Presenter / product / place</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        Questi slot spingono la generazione verso identita persistenti: stessa persona, stesso prodotto, stesso luogo, con variazioni controllate.
                    </p>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <label for="presenter_variable_id" class="mb-1 block text-sm font-semibold text-gray-700">Presenter</label>
                            <select id="presenter_variable_id" name="presenter_variable_id" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">Nessuno</option>
                                @foreach($presenterVariables as $var)
                                    <option value="{{ (int) ($var['id'] ?? 0) }}" @selected($selectedPresenterId === (int) ($var['id'] ?? 0))>
                                        {{ (string) ($var['name'] ?? 'Presenter') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="product_variable_id" class="mb-1 block text-sm font-semibold text-gray-700">Product</label>
                            <select id="product_variable_id" name="product_variable_id" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">Nessuno</option>
                                @foreach($productVariables as $var)
                                    <option value="{{ (int) ($var['id'] ?? 0) }}" @selected($selectedProductId === (int) ($var['id'] ?? 0))>
                                        {{ (string) ($var['name'] ?? 'Product') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="place_variable_id" class="mb-1 block text-sm font-semibold text-gray-700">Place</label>
                            <select id="place_variable_id" name="place_variable_id" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">Nessuno</option>
                                @foreach($placeVariables as $var)
                                    <option value="{{ (int) ($var['id'] ?? 0) }}" @selected($selectedPlaceId === (int) ($var['id'] ?? 0))>
                                        {{ (string) ($var['name'] ?? 'Place') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="seasonal_overlay" class="mb-1 block text-sm font-semibold text-gray-700">Overlay stagionale o tema</label>
                            <input id="seasonal_overlay" type="text" name="seasonal_overlay" value="{{ $seasonalOverlay }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Es. Natale, back to school, summer drop, promo weekend" />
                        </div>
                        <div class="lg:col-span-2">
                            <label for="consistency_mode" class="mb-1 block text-sm font-semibold text-gray-700">Consistency mode</label>
                            <select id="consistency_mode" name="consistency_mode" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($consistencyModeOptions as $modeValue => $modeLabel)
                                    <option value="{{ $modeValue }}" @selected($selectedConsistencyMode === $modeValue)>{{ $modeLabel }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Strict forza maggiore fedelta all'identita, Balanced lascia piu regia mantenendo il soggetto, Creative allenta il vincolo se vuoi piu variazione.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">Variabili salvate</h2>
                        <span class="text-xs font-semibold text-gray-500">{{ count($assetVariables) }} disponibili</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        Seleziona variabili salvate (persona, ufficio, prodotto) oppure scrivile nel brief: se riconosciute, vengono applicate automaticamente.
                    </p>

                    @if(empty($assetVariables))
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                            Nessuna variabile configurata. Creale in <a href="{{ route('profile.brand') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">Brand Center</a>.
                        </div>
                    @else
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach($assetVariables as $var)
                                @php
                                    $varId = (int) ($var['id'] ?? 0);
                                    $varName = (string) ($var['name'] ?? 'variabile');
                                    $varSlug = (string) ($var['slug'] ?? '');
                                    $varKind = (string) ($var['kind'] ?? 'custom');
                                    $varDesc = trim((string) ($var['description'] ?? ''));
                                    $checked = $varId > 0 && in_array($varId, $selectedVariableIds, true);
                                @endphp
                                <label class="inline-flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                    <input
                                        type="checkbox"
                                        name="asset_variable_ids[]"
                                        value="{{ $varId }}"
                                        class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 js-asset-variable-checkbox"
                                        data-variable-id="{{ $varId }}"
                                        data-variable-name="{{ strtolower($varName) }}"
                                        data-variable-slug="{{ strtolower($varSlug) }}"
                                        @checked($checked)
                                    />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-900">{{ $varName }}</span>
                                        <span class="mt-0.5 inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">{{ $varKind }}</span>
                                        @if($varDesc !== '')
                                            <span class="mt-1 block text-xs text-gray-600">{{ $varDesc }}</span>
                                        @endif
                                        <span class="mt-1 block text-[11px] text-gray-500">token: {{ '@' . $varSlug }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Brief di generazione</h2>
                    <p class="mt-1 text-sm text-gray-600">Scrivi cosa vuoi ottenere. Questo testo verra usato come input AI (non come caption finale).</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="generation_brief" class="mb-1 block text-sm font-semibold text-gray-700">Cosa deve generare l AI</label>
                            <textarea id="generation_brief" name="generation_brief" rows="7" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="{{ $briefPlaceholder }}" required>{{ old('generation_brief', old('caption', old('title', ''))) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Per riferimenti puntuali usa i numeri: "usa foto #1 #2 #3 per il volto e #7 per l auto". Puoi combinarli con la selezione checkbox sopra.</p>
                            <div class="mt-2 rounded-xl border border-indigo-200 bg-indigo-50/50 px-3 py-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Variabili riconosciute nel brief</p>
                                <div id="recognizedAssetVariables" class="mt-1 flex flex-wrap gap-1.5 text-xs text-indigo-700">
                                    <span class="text-gray-500">Nessuna variabile riconosciuta</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="goal_hint" class="mb-1 block text-sm font-semibold text-gray-700">Obiettivo (opzionale)</label>
                            <input id="goal_hint" type="text" name="goal_hint" value="{{ old('goal_hint', $isReelPreset ? ($profile?->default_goal ?: '') : '') }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Es. Lead generation, awareness, engagement" />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Annulla
                    </a>
                    <button type="submit" class="ui-btn-primary justify-center">
                        Crea e genera con AI
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Come funziona</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">1. Brief</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">Descrivi il contenuto che vuoi</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">2. Context</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">AI usa brand profile + memoria contenuti</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">3. Output</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $isReelPreset ? 'Viene creato 1 reel pronto per il feed, allineato a brand e strategia' : 'Viene creato 1 post nel giorno impostato' }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Contesto brand attivo</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Azienda</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $profile?->business_name ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Tone default</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $profile?->default_tone ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">CTA default</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $profile?->cta ?: '-' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@include('partials.generation-loader')
<script>
    (function () {
        const presetMode = @json($createPreset);
        const briefEl = document.getElementById('generation_brief');
        const recognizedWrap = document.getElementById('recognizedAssetVariables');
        const variableCheckboxes = Array.from(document.querySelectorAll('.js-asset-variable-checkbox'));
        const createForm = document.querySelector('form[action="{{ route('posts.store') }}"]');
        const formatEl = document.getElementById('format');
        const videoProviderEl = document.getElementById('video_provider');
        const imageProviderEl = document.getElementById('image_provider');
        let videoProviderTouched = false;

        const normalize = (value) => {
            return (value || '')
                .toString()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9@\s_-]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        };

        const containsToken = (haystack, token) => {
            if (!token) return false;
            if ((' ' + haystack + ' ').includes(' ' + token + ' ')) return true;
            const compact = token.replace(/\s+/g, '');
            if (compact && haystack.includes('@' + compact)) return true;
            return false;
        };

        const renderRecognized = (items) => {
            if (!recognizedWrap) {
                return;
            }

            recognizedWrap.innerHTML = '';
            if (items.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'text-gray-500';
                empty.textContent = 'Nessuna variabile riconosciuta';
                recognizedWrap.appendChild(empty);
                return;
            }

            items.forEach((item) => {
                const chip = document.createElement('span');
                chip.className = 'inline-flex items-center rounded-full border border-indigo-200 bg-white px-2.5 py-1 font-semibold text-indigo-700';
                chip.textContent = item.label;
                recognizedWrap.appendChild(chip);
            });
        };

        const syncRecognition = () => {
            if (!briefEl || !recognizedWrap || variableCheckboxes.length === 0) {
                return;
            }

            const text = normalize(briefEl.value || '');
            const recognized = [];

            variableCheckboxes.forEach((cb) => {
                const name = normalize(cb.getAttribute('data-variable-name') || '');
                const slug = normalize(cb.getAttribute('data-variable-slug') || '');
                const label = (cb.getAttribute('data-variable-name') || '').trim() || slug || 'variabile';
                const isMentioned = (name && containsToken(text, name)) || (slug && containsToken(text, slug));
                if (isMentioned) {
                    recognized.push({ id: cb.value, label });
                    cb.checked = true;
                }
            });

            const selectedManual = variableCheckboxes
                .filter((cb) => cb.checked)
                .map((cb) => ({
                    id: cb.value,
                    label: (cb.getAttribute('data-variable-name') || '').trim() || (cb.getAttribute('data-variable-slug') || '').trim() || 'variabile',
                }));

            const unique = [];
            const seen = new Set();
            [...recognized, ...selectedManual].forEach((row) => {
                const key = String(row.id || row.label);
                if (seen.has(key)) return;
                seen.add(key);
                unique.push(row);
            });

            renderRecognized(unique);
        };

        if (briefEl && recognizedWrap && variableCheckboxes.length > 0) {
            briefEl.addEventListener('input', syncRecognition);
            variableCheckboxes.forEach((cb) => cb.addEventListener('change', syncRecognition));
            syncRecognition();
        }

        if (createForm && window.HostupGenerationLoader) {
            const syncVideoProviderWithFormat = () => {
                if (!formatEl || !videoProviderEl) {
                    return;
                }

                const format = (formatEl.value || 'post').toLowerCase();
                const currentProvider = (videoProviderEl.value || '').toLowerCase();
                if (format !== 'reel') {
                    return;
                }

                if (presetMode === 'reel' || (!videoProviderTouched && (currentProvider === '' || currentProvider === 'openai'))) {
                    videoProviderEl.value = 'runway';
                }
            };

            const estimateSeconds = () => {
                const format = (formatEl?.value || 'post').toLowerCase();
                const videoProvider = (videoProviderEl?.value || 'openai').toLowerCase();
                const imageProvider = (imageProviderEl?.value || 'nanobanana').toLowerCase();

                if (format === 'reel') {
                    if (videoProvider === 'runway') {
                        return 150;
                    }
                    if (videoProvider === 'kling') {
                        return 175;
                    }
                    return 190;
                }
                if (format === 'story') {
                    if (videoProvider === 'runway') {
                        return 120;
                    }
                    if (videoProvider === 'kling') {
                        return 145;
                    }
                    return 150;
                }

                return imageProvider === 'openai' ? 35 : 55;
            };

            const buildStages = () => {
                const format = (formatEl?.value || 'post').toLowerCase();
                if (format === 'reel' || format === 'story') {
                    return [
                        'Analisi brief e strategia',
                        'Scrittura caption e visual direction',
                        'Generazione video e rifinitura finale',
                    ];
                }

                return [
                    'Analisi brief e brand assets',
                    'Scrittura caption e prompt visuale',
                    'Generazione immagine e controllo finale',
                ];
            };

            if (videoProviderEl) {
                videoProviderEl.addEventListener('change', () => {
                    videoProviderTouched = true;
                });
            }
            if (formatEl) {
                formatEl.addEventListener('change', syncVideoProviderWithFormat);
            }
            syncVideoProviderWithFormat();

            createForm.addEventListener('submit', () => {
                const format = (formatEl?.value || 'post').toLowerCase();
                const subtitle = format === 'reel' || format === 'story'
                    ? 'Sto preparando script, shot plan, caption e video finale. I reel richiedono piu tempo dei post statici.'
                    : 'Sto preparando caption, prompt e visual finale in linea con il brand.';

                window.HostupGenerationLoader.show({
                    title: 'Sto generando il contenuto',
                    subtitle,
                    estimateSeconds: estimateSeconds(),
                    stages: buildStages(),
                });
            });
        }
    })();
</script>
@endsection



