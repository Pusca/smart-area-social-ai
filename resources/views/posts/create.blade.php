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
    $defaultPlatforms = old('platforms', $profile?->default_platforms ?? ['instagram']);
    if (!is_array($defaultPlatforms)) {
        $defaultPlatforms = ['instagram'];
    }

    $defaultFormat = old('format', (is_array($profile?->default_formats ?? null) && !empty($profile->default_formats))
        ? (string) $profile->default_formats[0]
        : 'post');

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
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Single Content AI</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Crea un contenuto singolo con AI</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Inserisci cosa vuoi generare, scegli i social e imposta lo slot in calendario. Il sistema usa brand profile + AI per generare un solo contenuto pronto.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Flow on demand
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        Scheduling + Generazione
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
                    <a href="{{ route('profile.brand') }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Profilo brand
                    </a>
                </div>
            </div>
        </div>
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
            <form method="POST" action="{{ route('posts.store') }}" class="space-y-6">
                @csrf

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Dettagli pubblicazione</h2>
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
                    <h2 class="text-lg font-semibold text-gray-900">Brief di generazione</h2>
                    <p class="mt-1 text-sm text-gray-600">Scrivi cosa vuoi ottenere. Questo testo verra usato come input AI (non come caption finale).</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="generation_brief" class="mb-1 block text-sm font-semibold text-gray-700">Cosa deve generare l AI</label>
                            <textarea id="generation_brief" name="generation_brief" rows="7" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Es. Crea un post reel su Jaguar usando l'ultima immagine caricata e il logo sullo sfondo..." required>{{ old('generation_brief', old('caption', old('title', ''))) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Per riferimenti puntuali usa i numeri: "usa foto #1 #2 #3 per il volto e #7 per l auto". Puoi combinarli con la selezione checkbox sopra.</p>
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
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
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
@endsection
