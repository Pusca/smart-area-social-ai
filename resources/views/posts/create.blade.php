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

    $tz = config('app.timezone', 'Europe/Rome');
    $createPreset = ($createPreset ?? request()->query('preset', 'default'));
    $createPreset = $createPreset === 'reel' ? 'reel' : 'default';
    $isReelPreset = $createPreset === 'reel';

    $videoProviderOptions = [
        'openai' => 'Sora + GPT (OpenAI)',
        'runway' => $isReelPreset ? 'Runway image-to-video' : 'Runway',
        'google_veo' => 'Google Veo 3.1 (direct)',
        'kling' => 'Kling (coerenza persona)',
    ];
    $imageProviderOptions = [
        'nanobanana' => 'Nano Banana (consigliato)',
        'openai' => 'GPT Image (OpenAI)',
    ];

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
    $overlayPreferences = is_array($profile?->overlay_preferences ?? null) ? $profile->overlay_preferences : [];
    $overlayFonts = (array) config('overlays.fonts', []);
    $overlayPresets = (array) config('overlays.presets', []);
    $overlayUi = (array) config('overlays.ui', []);
    $overlayMode = old('overlay_mode', (bool) ($overlayPreferences['auto_enabled'] ?? true) ? 'auto' : 'off');
    $overlayPreset = old('overlay_preset', (string) ($overlayPreferences['preset'] ?? 'modern_split_caption'));
    $overlayFontFamily = old('overlay_font_family', (string) ($overlayPreferences['font_family'] ?? 'arial'));
    $overlayFontFallback = old('overlay_font_fallback', (string) ($overlayPreferences['fallback_font_family'] ?? 'segoe_ui'));
    $overlayFontPreset = (string) ($overlayPreferences['font_preset'] ?? $overlayPreferences['tone_preset'] ?? 'modern');
    $brandHookIntensity = (string) ($overlayPreferences['preferred_hook_intensity'] ?? 'medium');
    $brandTrendAppetite = (string) ($overlayPreferences['trend_appetite'] ?? 'medium');
    $brandProfessionalismGuardrail = (string) ($overlayPreferences['professionalism_guardrail_level'] ?? 'high');
    $overlayFontWeight = old('overlay_font_weight', '700');
    $overlayFontSizeMode = old('overlay_font_size_mode', $isReelPreset ? 'xl' : 'large');
    $overlayTextCase = old('overlay_text_case', 'sentence');
    $overlayAlignment = old('overlay_alignment', 'left');
    $overlayPosition = old('overlay_position', $isReelPreset ? 'upper_center' : 'upper_left');
    $overlaySafeArea = old('overlay_safe_area', (string) ($overlayPreferences['safe_area'] ?? 'upper_third'));
    $overlayMaxLines = (int) old('overlay_max_lines', $isReelPreset ? 2 : 3);
    $overlayColor = old('overlay_color', '#FFFFFF');
    $overlayStrokeColor = old('overlay_stroke_color', '#111827');
    $overlayShadow = (bool) old('overlay_shadow', true);
    $overlayBackgroundStyle = old('overlay_background_style', $isReelPreset ? 'dark_box' : 'gradient_strip');
    $overlayAnimationStyle = old('overlay_animation_style', $isReelPreset ? 'pop' : 'fade');
    $overlayCtaText = old('overlay_cta_text', '');

    $defaultSchedule = old('scheduled_at', now($tz)->addHour()->startOfHour()->format('Y-m-d\\TH:i'));
    $defaultVideoDurationSeconds = (int) old('video_duration_seconds_requested', $isReelPreset ? 20 : 0);

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
                        ? 'Qui lavori su un solo reel alla volta: hook, anchor frame, ritmo visivo e direzione brand vengono preparati per aiutare il motore video selezionato.'
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
                        {{ $isReelPreset ? 'Editor reel dedicato' : 'Editor reel dedicato' }}
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                La macchina prepara un blueprint breve con hook, anchor frame, progressione degli shot e payoff finale. Poi traduce tutto in un input adatto al motore video selezionato, mantenendo il reel coerente anche quando richiede piu di una clip.
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
                        {{ $isReelPreset ? 'Qui stai creando un reel singolo guidato: hook, anchor frame, shot plan e resa social vengono ordinati prima di arrivare al motore video.' : 'Qui stai creando un singolo contenuto on demand.' }}
                    </p>

                    <div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50/50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ $isReelPreset ? 'Modalita reel attiva' : 'Se vuoi un insieme di contenuti' }}</p>
                        <p class="mt-1 text-sm text-gray-700">
                            @if($isReelPreset)
                                Il formato reel parte gia attivo. Descrivi bene hook iniziale, soggetto principale, micro-progressione delle scene e payoff finale: la macchina costruira un blueprint sintetico e lo tradurra in un input piu adatto al motore video scelto.
                            @else
                                Per una sequenza di pubblicazioni o un piano editoriale usa il <a href="{{ route('wizard.start') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">piano editoriale</a>, che lavora da minimo 2 contenuti.
                            @endif
                        </p>
                    </div>

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
                    </div>

                    {{-- Impostazioni provider AI — collassabili --}}
                    <details class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50" id="provider-settings-details">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-100">
                            <span>Motori AI</span>
                            <span class="text-xs font-normal text-gray-500" id="provider-summary-label">Kling · NanoBanana</span>
                        </summary>
                        <div class="border-t border-gray-200 p-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                @unless($isReelPreset)
                                <div class="sm:col-span-2">
                                    <label for="image_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore immagini</label>
                                    <select id="image_provider" name="image_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                        @foreach($imageProviderOptions as $key => $label)
                                            <option value="{{ $key }}" @selected($defaultImageProvider === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">La base resta NanoBanana. Cambia solo per questo contenuto se vuoi testare un'alternativa.</p>
                                </div>
                                @endunless
                                <div class="sm:col-span-2">
                                    <label for="video_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore video</label>
                                    <select id="video_provider" name="video_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                        @foreach($videoProviderOptions as $key => $label)
                                            <option value="{{ $key }}" @selected($defaultVideoProvider === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">Usato solo per reel e story. Se la durata supera il limite, il sistema genera piu clip e le unisce automaticamente.</p>
                                </div>
                                @if($isReelPreset)
                                <div class="sm:col-span-2">
                                    <label for="video_duration_seconds_requested" class="mb-1 block text-sm font-semibold text-gray-700">Durata video (secondi)</label>
                                    <input id="video_duration_seconds_requested" type="number" name="video_duration_seconds_requested" min="4" max="45" step="1" value="{{ $defaultVideoDurationSeconds > 0 ? $defaultVideoDurationSeconds : 20 }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                    <p class="mt-1 text-xs text-gray-500">Se supera il limite del provider, il reel viene spezzato e ricucito automaticamente.</p>
                                </div>
                                @endif
                            </div>
                        </div>
                    </details>
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

                {{-- Hidden inputs: auto-populated via JS dalle variabili selezionate sotto --}}
                <input type="hidden" name="presenter_variable_id" id="js-presenter-variable-id" value="{{ $selectedPresenterId ?: '' }}">
                <input type="hidden" name="product_variable_id" id="js-product-variable-id" value="{{ $selectedProductId ?: '' }}">
                <input type="hidden" name="place_variable_id" id="js-place-variable-id" value="{{ $selectedPlaceId ?: '' }}">
                <input type="hidden" name="consistency_mode" id="js-consistency-mode" value="{{ $selectedConsistencyMode }}">
                <input type="hidden" name="seasonal_overlay" id="js-seasonal-overlay" value="{{ $seasonalOverlay }}">

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">Asset e identita brand</h2>
                        <span class="text-xs font-semibold text-gray-500">{{ count($assetVariables) }} disponibili</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        Seleziona le identita da includere nel contenuto — persona, prodotto, luogo. Il sistema riconosce anche il nome scritto nel brief.
                    </p>

                    @if(empty($assetVariables))
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                            Nessuna variabile configurata. Creale in <a href="{{ route('profile.brand') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">Brand Center</a>.
                        </div>
                    @else
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach($assetVariables as $var)
                                @php
                                    $varId   = (int) ($var['id'] ?? 0);
                                    $varName = (string) ($var['name'] ?? 'variabile');
                                    $varSlug = (string) ($var['slug'] ?? '');
                                    $varKind = (string) ($var['kind'] ?? 'custom');
                                    $varDesc = trim((string) ($var['description'] ?? ''));
                                    $checked = $varId > 0 && in_array($varId, $selectedVariableIds, true);
                                    $kindColors = [
                                        'person'   => 'border-violet-200 bg-violet-50 text-violet-700',
                                        'product'  => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                        'location' => 'border-amber-200 bg-amber-50 text-amber-700',
                                    ];
                                    $kindLabels = [
                                        'person'   => 'Persona',
                                        'product'  => 'Prodotto',
                                        'location' => 'Luogo',
                                    ];
                                    $kindColor = $kindColors[$varKind] ?? 'border-gray-200 bg-gray-50 text-gray-600';
                                    $kindLabel = $kindLabels[$varKind] ?? ucfirst($varKind);
                                @endphp
                                <label class="inline-flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 transition hover:border-indigo-200 hover:bg-indigo-50/40">
                                    <input
                                        type="checkbox"
                                        name="asset_variable_ids[]"
                                        value="{{ $varId }}"
                                        class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 js-asset-variable-checkbox"
                                        data-variable-id="{{ $varId }}"
                                        data-variable-name="{{ strtolower($varName) }}"
                                        data-variable-slug="{{ strtolower($varSlug) }}"
                                        data-variable-kind="{{ $varKind }}"
                                        @checked($checked)
                                    />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-900">{{ $varName }}</span>
                                        <span class="mt-0.5 inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $kindColor }}">{{ $kindLabel }}</span>
                                        @if($varDesc !== '')
                                            <span class="mt-1 block text-xs text-gray-500">{{ $varDesc }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <div id="js-identity-summary" class="mt-3 hidden rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-700"></div>
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

                <details class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm" id="overlay-settings-details">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-6 hover:bg-gray-50">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Text overlays e typography</h2>
                            <p class="mt-0.5 text-sm text-gray-500">Hook su immagine/video, CTA, font — gestiti in automatico dal brand. Espandi per personalizzare.</p>
                        </div>
                        <span class="ml-4 shrink-0 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">Auto</span>
                    </summary>
                    <div class="border-t border-gray-100 p-6">

                    <div class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Default brand attivi</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-700">
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Font preset: {{ ucfirst($overlayFontPreset) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Trend appetite: {{ ucfirst($brandTrendAppetite) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Hook intensity: {{ ucfirst($brandHookIntensity) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Guardrail: {{ ucfirst($brandProfessionalismGuardrail) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label for="overlay_mode" class="mb-1 block text-sm font-semibold text-gray-700">Overlay</label>
                            <select id="overlay_mode" name="overlay_mode" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['modes'] ?? ['auto', 'manual', 'off']) as $mode)
                                    <option value="{{ $mode }}" @selected($overlayMode === $mode)>{{ ucfirst($mode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_preset" class="mb-1 block text-sm font-semibold text-gray-700">Preset</label>
                            <select id="overlay_preset" name="overlay_preset" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayPresets as $key => $row)
                                    <option value="{{ $key }}" @selected($overlayPreset === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_family" class="mb-1 block text-sm font-semibold text-gray-700">Font</label>
                            <select id="overlay_font_family" name="overlay_font_family" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayFonts as $key => $row)
                                    <option value="{{ $key }}" @selected($overlayFontFamily === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_position" class="mb-1 block text-sm font-semibold text-gray-700">Posizione</label>
                            <select id="overlay_position" name="overlay_position" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['positions'] ?? ['upper_left', 'upper_center', 'center_left', 'center', 'lower_left', 'lower_center']) as $position)
                                    <option value="{{ $position }}" @selected($overlayPosition === $position)>{{ ucfirst(str_replace('_', ' ', $position)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="overlay_text" class="mb-1 block text-sm font-semibold text-gray-700">Testo primario</label>
                            <input id="overlay_text" type="text" name="overlay_text" value="{{ old('overlay_text') }}" placeholder="Lascia vuoto per usare il hook automatico" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-3">
                            <label for="overlay_secondary_text" class="mb-1 block text-sm font-semibold text-gray-700">Testo secondario</label>
                            <input id="overlay_secondary_text" type="text" name="overlay_secondary_text" value="{{ old('overlay_secondary_text') }}" placeholder="Es. authority cue, payoff o micro-contesto" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-3">
                            <label for="overlay_cta_text" class="mb-1 block text-sm font-semibold text-gray-700">CTA finale overlay</label>
                            <input id="overlay_cta_text" type="text" name="overlay_cta_text" value="{{ $overlayCtaText }}" placeholder="Lascia vuoto per usare la CTA automatica del contenuto" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    </div>

                    <details class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white">
                        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900">
                            Opzioni avanzate typography
                        </summary>
                        <div class="border-t border-gray-100 p-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="overlay_font_fallback" class="mb-1 block text-sm font-semibold text-gray-700">Fallback font</label>
                            <select id="overlay_font_fallback" name="overlay_font_fallback" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayFonts as $key => $row)
                                    <option value="{{ $key }}" @selected($overlayFontFallback === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_weight" class="mb-1 block text-sm font-semibold text-gray-700">Peso</label>
                            <select id="overlay_font_weight" name="overlay_font_weight" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['font_weights'] ?? ['400', '500', '600', '700', '800']) as $weight)
                                    <option value="{{ $weight }}" @selected($overlayFontWeight === $weight)>{{ $weight }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_size_mode" class="mb-1 block text-sm font-semibold text-gray-700">Size mode</label>
                            <select id="overlay_font_size_mode" name="overlay_font_size_mode" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['font_size_modes'] ?? ['small', 'medium', 'large', 'xl']) as $sizeMode)
                                    <option value="{{ $sizeMode }}" @selected($overlayFontSizeMode === $sizeMode)>{{ ucfirst($sizeMode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_text_case" class="mb-1 block text-sm font-semibold text-gray-700">Text case</label>
                            <select id="overlay_text_case" name="overlay_text_case" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['text_cases'] ?? ['sentence', 'title', 'uppercase']) as $case)
                                    <option value="{{ $case }}" @selected($overlayTextCase === $case)>{{ ucfirst($case) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_max_lines" class="mb-1 block text-sm font-semibold text-gray-700">Max lines</label>
                            <input id="overlay_max_lines" type="number" min="1" max="4" name="overlay_max_lines" value="{{ $overlayMaxLines }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_alignment" class="mb-1 block text-sm font-semibold text-gray-700">Alignment</label>
                            <select id="overlay_alignment" name="overlay_alignment" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['alignments'] ?? ['left', 'center', 'right']) as $alignment)
                                    <option value="{{ $alignment }}" @selected($overlayAlignment === $alignment)>{{ ucfirst($alignment) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_safe_area" class="mb-1 block text-sm font-semibold text-gray-700">Safe area</label>
                            <select id="overlay_safe_area" name="overlay_safe_area" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['safe_areas'] ?? ['upper_third', 'center_safe', 'lower_third']) as $safeArea)
                                    <option value="{{ $safeArea }}" @selected($overlaySafeArea === $safeArea)>{{ ucfirst(str_replace('_', ' ', $safeArea)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_background_style" class="mb-1 block text-sm font-semibold text-gray-700">Background</label>
                            <select id="overlay_background_style" name="overlay_background_style" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['background_styles'] ?? ['none', 'dark_box', 'light_box', 'gradient_strip']) as $background)
                                    <option value="{{ $background }}" @selected($overlayBackgroundStyle === $background)>{{ ucfirst(str_replace('_', ' ', $background)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_animation_style" class="mb-1 block text-sm font-semibold text-gray-700">Animazione</label>
                            <select id="overlay_animation_style" name="overlay_animation_style" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['animation_styles'] ?? ['none', 'fade', 'pop', 'slide_up']) as $animation)
                                    <option value="{{ $animation }}" @selected($overlayAnimationStyle === $animation)>{{ ucfirst(str_replace('_', ' ', $animation)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_color" class="mb-1 block text-sm font-semibold text-gray-700">Color</label>
                            <input id="overlay_color" type="text" name="overlay_color" value="{{ $overlayColor }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_stroke_color" class="mb-1 block text-sm font-semibold text-gray-700">Stroke</label>
                            <input id="overlay_stroke_color" type="text" name="overlay_stroke_color" value="{{ $overlayStrokeColor }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-700">Shadow</label>
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-700">
                                <input type="hidden" name="overlay_shadow" value="0">
                                <input type="checkbox" name="overlay_shadow" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked($overlayShadow) />
                                Attivo
                            </label>
                        </div>
                        <div>
                            <label for="overlay_timing_start_ms" class="mb-1 block text-sm font-semibold text-gray-700">Start ms</label>
                            <input id="overlay_timing_start_ms" type="number" min="0" max="300000" name="overlay_timing_start_ms" value="{{ old('overlay_timing_start_ms', 0) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    <div>
                            <label for="overlay_timing_end_ms" class="mb-1 block text-sm font-semibold text-gray-700">End ms</label>
                            <input id="overlay_timing_end_ms" type="number" min="0" max="300000" name="overlay_timing_end_ms" value="{{ old('overlay_timing_end_ms', 0) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-2">
                            <label for="overlay_emphasis_words" class="mb-1 block text-sm font-semibold text-gray-700">Emphasis words</label>
                            <input id="overlay_emphasis_words" type="text" name="overlay_emphasis_words" value="{{ old('overlay_emphasis_words') }}" placeholder="Es. strategia, metodo, risultato" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    </div>
                        </div>
                    </details>

                    </div>
                </details>

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
        const presenterIdEl = document.getElementById('js-presenter-variable-id');
        const productIdEl = document.getElementById('js-product-variable-id');
        const placeIdEl = document.getElementById('js-place-variable-id');
        const identitySummaryEl = document.getElementById('js-identity-summary');
        const providerSummaryLabel = document.getElementById('provider-summary-label');
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

        // Auto-wiring: sincronizza il primo checked di ogni kind ai hidden inputs
        const syncIdentitySlots = () => {
            const checked = variableCheckboxes.filter(cb => cb.checked);
            const first = (kind) => checked.find(cb => cb.dataset.variableKind === kind);
            const presenter = first('person');
            const product   = first('product');
            const place     = first('location');
            if (presenterIdEl) presenterIdEl.value = presenter ? presenter.value : '';
            if (productIdEl)   productIdEl.value   = product   ? product.value   : '';
            if (placeIdEl)     placeIdEl.value     = place     ? place.value     : '';

            if (identitySummaryEl) {
                const parts = [];
                if (presenter) parts.push('Persona: ' + (presenter.dataset.variableName || ''));
                if (product)   parts.push('Prodotto: ' + (product.dataset.variableName || ''));
                if (place)     parts.push('Luogo: ' + (place.dataset.variableName || ''));
                if (parts.length > 0) {
                    identitySummaryEl.textContent = parts.join(' · ');
                    identitySummaryEl.classList.remove('hidden');
                } else {
                    identitySummaryEl.classList.add('hidden');
                }
            }
        };

        // Aggiorna la label riassuntiva nel summary del details provider
        const updateProviderSummary = () => {
            if (!providerSummaryLabel) return;
            const video = (videoProviderEl?.options[videoProviderEl.selectedIndex]?.text || 'Kling').split(' ')[0];
            const image = (imageProviderEl?.options[imageProviderEl.selectedIndex]?.text || 'NanoBanana').split(' ')[0];
            providerSummaryLabel.textContent = video + ' · ' + image;
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

        // Identity auto-wiring
        variableCheckboxes.forEach((cb) => cb.addEventListener('change', syncIdentitySlots));
        syncIdentitySlots();

        // Provider summary
        if (videoProviderEl) videoProviderEl.addEventListener('change', updateProviderSummary);
        if (imageProviderEl) imageProviderEl.addEventListener('change', updateProviderSummary);
        updateProviderSummary();

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
                    if (videoProvider === 'google_veo') {
                        return 185;
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
                    if (videoProvider === 'google_veo') {
                        return 140;
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
