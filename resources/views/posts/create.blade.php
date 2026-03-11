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
    ];
    $imageProviderOptions = [
        'nanobanana' => 'Nano Banana (consigliato)',
        'openai' => 'GPT Image (OpenAI)',
    ];

    $tz = config('app.timezone', 'Europe/Rome');
    $defaultPlatforms = old('platforms', $profile?->default_platforms ?? ['instagram']);
    if (!is_array($defaultPlatforms)) {
        $defaultPlatforms = ['instagram'];
    }

    $defaultFormat = old('format', (is_array($profile?->default_formats ?? null) && !empty($profile->default_formats))
        ? (string) $profile->default_formats[0]
        : 'post');
    $defaultVideoProvider = old('video_provider', (string) config('generation.video_provider_default', 'openai'));
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
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Area Crea</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Scegli se partire da un contenuto o da un piano</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Da qui puoi creare un contenuto singolo al volo oppure impostare un piano editoriale vero e proprio, con almeno 2 contenuti.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">Singolo o piano</span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        AI + scheduling
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Torna ai contenuti
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri calendario
                    </a>
                    <a href="{{ route('profile.brand') }}" class="ui-btn-primary justify-center">
                        Profilo brand
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <article id="single-content-option" class="rounded-3xl border border-indigo-200 bg-indigo-50/60 p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Contenuto singolo</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">Quando hai gia un'idea precisa e vuoi produrla subito</h2>
                </div>
                <span class="inline-flex items-center rounded-full border border-indigo-200 bg-white px-2.5 py-1 text-xs font-semibold text-indigo-700">
                    Attivo ora
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                Perfetto per un post, un reel o una story specifica. Dai il brief, agganci immagini e variabili, scegli la programmazione e generi subito.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="ui-chip">1 contenuto</span>
                <span class="ui-chip">Più veloce</span>
                <span class="ui-chip">Controllo puntuale</span>
            </div>
            <div class="mt-5">
                <a href="#single-content-form" class="ui-btn-primary">
                    Continua con contenuto singolo
                </a>
            </div>
        </article>

        <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand">Piano editoriale</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">Quando vuoi creare più contenuti con logica di insieme</h2>
                </div>
                <span class="inline-flex items-center rounded-full border border-brand bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand">
                    Minimo 2
                </span>
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-600">
                Ideale se vuoi pianificare una mini sequenza o un periodo completo. Qui entra in gioco il piano editoriale, con distribuzione dei contenuti e struttura più strategica.
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
                    <h2 class="text-lg font-semibold text-gray-900">Contenuto singolo</h2>
                    <p class="mt-1 text-sm text-gray-600">Qui stai creando un singolo contenuto on demand.</p>

                    <div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50/50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Se vuoi un insieme di contenuti</p>
                        <p class="mt-1 text-sm text-gray-700">
                            Per una sequenza di pubblicazioni o un piano editoriale usa il <a href="{{ route('wizard.start') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">piano editoriale</a>, che lavora da minimo 2 contenuti.
                        </p>
                    </div>

                    <h3 class="mt-5 text-lg font-semibold text-gray-900">Dettagli pubblicazione</h3>
                    <p class="mt-1 text-sm text-gray-600">Quando pubblicare e in quale formato.</p>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="scheduled_at" class="mb-1 block text-sm font-semibold text-gray-700">Programmazione</label>
                            <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ $defaultSchedule }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                        </div>
                        <div>
                            <label for="format" class="mb-1 block text-sm font-semibold text-gray-700">Formato</label>
                            <select id="format" name="format" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                                @foreach($formatOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultFormat === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
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
                        <div class="sm:col-span-2">
                            <label for="video_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore video (solo reel/story)</label>
                            <select id="video_provider" name="video_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($videoProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultVideoProvider === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Per i post immagine il provider video viene ignorato. Per reel/story puoi confrontare output Runway vs Sora.
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
                            Nessuna immagine brand disponibile. Caricale in <a href="{{ route('profile.brand') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">Profilo brand</a>.
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
                        <h2 class="text-lg font-semibold text-gray-900">Variabili asset (objects)</h2>
                        <span class="text-xs font-semibold text-gray-500">{{ count($assetVariables) }} disponibili</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        Seleziona variabili salvate (persona, ufficio, prodotto) oppure scrivile nel brief: se riconosciute, vengono applicate automaticamente.
                    </p>

                    @if(empty($assetVariables))
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                            Nessuna variabile configurata. Creale in <a href="{{ route('profile.brand') }}" class="font-semibold text-indigo-700 hover:text-indigo-800">Profilo brand</a>.
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
                            <textarea id="generation_brief" name="generation_brief" rows="7" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Es. Crea un post reel su Jaguar usando l'ultima immagine caricata e il logo sullo sfondo..." required>{{ old('generation_brief', old('caption', old('title', ''))) }}</textarea>
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
                            <input id="goal_hint" type="text" name="goal_hint" value="{{ old('goal_hint') }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Es. Lead generation, awareness, engagement" />
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
                        <p class="mt-1 text-sm font-semibold text-gray-900">Viene creato 1 post nel giorno impostato</p>
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
        const briefEl = document.getElementById('generation_brief');
        const recognizedWrap = document.getElementById('recognizedAssetVariables');
        const variableCheckboxes = Array.from(document.querySelectorAll('.js-asset-variable-checkbox'));
        const createForm = document.querySelector('form[action="{{ route('posts.store') }}"]');
        const formatEl = document.getElementById('format');
        const videoProviderEl = document.getElementById('video_provider');
        const imageProviderEl = document.getElementById('image_provider');

        if (!briefEl || !recognizedWrap || variableCheckboxes.length === 0) {
            return;
        }

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

        briefEl.addEventListener('input', syncRecognition);
        variableCheckboxes.forEach((cb) => cb.addEventListener('change', syncRecognition));
        syncRecognition();

        if (createForm && window.HostupGenerationLoader) {
            const estimateSeconds = () => {
                const format = (formatEl?.value || 'post').toLowerCase();
                const videoProvider = (videoProviderEl?.value || 'openai').toLowerCase();
                const imageProvider = (imageProviderEl?.value || 'nanobanana').toLowerCase();

                if (format === 'reel') {
                    return videoProvider === 'runway' ? 150 : 190;
                }
                if (format === 'story') {
                    return videoProvider === 'runway' ? 120 : 150;
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

            createForm.addEventListener('submit', () => {
                const format = (formatEl?.value || 'post').toLowerCase();
                const subtitle = format === 'reel' || format === 'story'
                    ? 'Sto preparando script, caption, visual e video finale. I reel richiedono piu tempo dei post statici.'
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
