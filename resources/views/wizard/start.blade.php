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
        'reel' => 'Reel / Short video',
        'post' => 'Post immagine / carousel',
        'story' => 'Stories',
        'live' => 'Live',
        'blog' => 'Articolo / long copy',
        'newsletter' => 'Newsletter',
    ];

    $platforms = old('platforms', $step1['platforms'] ?? ['instagram']);
    if (!is_array($platforms)) {
        $platforms = ['instagram'];
    }

    $formats = old('formats', $step1['formats'] ?? ['post']);
    if (!is_array($formats)) {
        $formats = ['post'];
    }

    $tone = old('tone', $step1['tone'] ?? 'professionale');
    $selectedPlatforms = count($platforms);
    $selectedFormats = count($formats);
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-[2rem] border border-app bg-white/92 p-6 shadow-panel lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-brand bg-brand-soft px-3 py-1 text-xs font-semibold text-brand">
                    <x-application-logo variant="icon" class="h-5 w-5" />
                    Piano editoriale guidato
                </div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Imposta il prossimo piano con chiarezza</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Scegli obiettivo, periodo, tono e canali. Qui costruisci un piano editoriale vero e proprio, da minimo 2 contenuti in su.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Step 1 di 2
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $selectedPlatforms }} piattaforme selezionate
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $selectedFormats }} formati selezionati
                    </span>
                </div>
            </div>

            <div class="rounded-[1.75rem] border border-brand bg-[var(--gradient-soft)] p-5 shadow-card">
                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-brand">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('profile.brand') }}" class="ui-btn-secondary justify-center">
                        Apri profilo brand
                    </a>
                    <a href="{{ route('wizard.done') }}" class="ui-btn-secondary justify-center">
                        Vai a riepilogo
                    </a>
                    <a href="{{ route('dashboard') }}" class="ui-btn-primary justify-center">
                        Torna alla panoramica
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

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Controlla i campi del wizard:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <form method="POST" action="{{ route('wizard.store') }}" class="space-y-6">
                @csrf

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Dati base</h2>
                            <p class="mt-1 text-sm text-gray-600">Nome e periodo operativo del piano.</p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-1">
                            <label for="name" class="mb-1 block text-sm font-semibold text-gray-700">Nome piano</label>
                            <input id="name" type="text" name="name" value="{{ old('name', $step1['name'] ?? '') }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                        </div>
                        <div>
                            <label for="start_date" class="mb-1 block text-sm font-semibold text-gray-700">Data inizio</label>
                            <input id="start_date" type="date" name="start_date" value="{{ old('start_date', $step1['start_date'] ?? '') }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                        </div>
                        <div>
                            <label for="end_date" class="mb-1 block text-sm font-semibold text-gray-700">Data fine</label>
                            <input id="end_date" type="date" name="end_date" value="{{ old('end_date', $step1['end_date'] ?? '') }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Strategia</h2>
                    <p class="mt-1 text-sm text-gray-600">Obiettivo, tone of voice e volume di pubblicazione.</p>

                    <div class="mt-4">
                        <label for="goal" class="mb-1 block text-sm font-semibold text-gray-700">Obiettivo</label>
                        <textarea id="goal" name="goal" rows="3" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>{{ old('goal', $step1['goal'] ?? '') }}</textarea>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="tone" class="mb-1 block text-sm font-semibold text-gray-700">Tono della comunicazione</label>
                            <select id="tone" name="tone" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                                <option value="professionale" @selected($tone === 'professionale')>Professionale</option>
                                <option value="amichevole" @selected($tone === 'amichevole')>Amichevole</option>
                                <option value="ironico" @selected($tone === 'ironico')>Ironico</option>
                                <option value="ispirazionale" @selected($tone === 'ispirazionale')>Ispirazionale</option>
                                <option value="tecnico" @selected($tone === 'tecnico')>Tecnico/Esperto</option>
                            </select>
                        </div>

                        <div>
                            <label for="posts_per_week" class="mb-1 block text-sm font-semibold text-gray-700">Contenuti totali del piano</label>
                            <input id="posts_per_week" type="number" min="2" max="31" step="1" name="posts_per_week" value="{{ old('posts_per_week', $step1['posts_per_week'] ?? 5) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                            <p class="mt-1 text-xs text-gray-500">Minimo 2 contenuti per attivare il piano editoriale.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Piattaforme</h2>
                        <span class="text-xs font-semibold text-gray-500">Selezione multipla</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($platformOptions as $key => $label)
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                <input type="checkbox" name="platforms[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($key, $platforms, true)) />
                                <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Formati</h2>
                        <span class="text-xs font-semibold text-gray-500">Selezione multipla</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($formatOptions as $key => $label)
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                <input type="checkbox" name="formats[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($key, $formats, true)) />
                                <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('posts.index') }}" class="ui-btn-secondary">
                        Vai ai contenuti
                    </a>
                    <button type="submit" class="ui-btn-primary">
                        Continua al riepilogo
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Progressione wizard</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
                        <p class="text-xs text-indigo-700">Step 1</p>
                        <p class="mt-1 text-sm font-semibold text-indigo-900">Setup del piano</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Step 2</p>
                        <p class="mt-1 text-sm font-semibold text-gray-800">Generazione e controllo</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Default brand</h2>
                <p class="mt-1 text-sm text-gray-600">Valori precompilati dal profilo azienda.</p>

                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Azienda</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $profile->business_name ?? '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Goal default</p>
                        <p class="mt-1 text-sm text-gray-700">{{ $profile->default_goal ?? '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Tone default</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $profile->default_tone ?? '-' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
