@extends('layouts.app')

@section('content')
@php
    /** @var \App\Models\TenantProfile|null $profile */
    /** @var \Illuminate\Support\Collection|\App\Models\BrandAsset[] $assets */
    /** @var \Illuminate\Support\Collection|\App\Models\AssetVariable[] $assetVariables */
    $profile = $profile ?? null;
    $assets = $assets ?? collect();
    if (!($assets instanceof \Illuminate\Support\Collection)) {
        $assets = collect($assets);
    }
    $assetVariables = $assetVariables ?? collect();
    if (!($assetVariables instanceof \Illuminate\Support\Collection)) {
        $assetVariables = collect($assetVariables);
    }
    $assetVariableCatalog = is_array($assetVariableCatalog ?? null) ? $assetVariableCatalog : [];
    /** @var \App\Models\ContentPlan|null $demoPlan */
    $demoPlan = $demoPlan ?? null;
    $isOnboardingPending = (bool) ($isOnboardingPending ?? false);
    $quickstartDismissed = (bool) ($quickstartDismissed ?? false);
    $shouldShowQuickstart = (bool) ($shouldShowQuickstart ?? (!$quickstartDismissed && ($isOnboardingPending || $demoPlan !== null)));

    $byKind = $assets->groupBy('kind');
    $logos = $byKind['logo'] ?? collect();
    $images = $byKind['image'] ?? collect();
    $variableCandidateAssets = $assets->whereIn('kind', ['image', 'logo'])->values();
    $selectedVariableAssetIds = old('asset_ids', []);
    if (!is_array($selectedVariableAssetIds)) {
        $selectedVariableAssetIds = [];
    }
    $selectedVariableAssetIds = array_values(array_unique(array_map(
        fn ($id) => (int) $id,
        array_filter($selectedVariableAssetIds, fn ($id) => (int) $id > 0)
    )));
    /** @var \App\Models\EditorialStrategy|null $strategy */
    $strategy = $strategy ?? null;
    $analysis = (array) ($strategy?->analysis_framework ?? []);
    $visual = (array) ($strategy?->visual_system ?? []);
    $publishing = (array) ($strategy?->publishing_system ?? []);
    $strategyLocked = (bool) old('strategy_locked', $strategy?->is_locked ?? false);

    $defaultPlatforms = old('default_platforms', $profile?->default_platforms ?? ['instagram', 'facebook']);
    if (!is_array($defaultPlatforms)) {
        $defaultPlatforms = [];
    }

    $defaultFormats = old('default_formats', $profile?->default_formats ?? ['reel', 'post']);
    if (!is_array($defaultFormats)) {
        $defaultFormats = [];
    }

    $tone = old('default_tone', $profile?->default_tone ?? 'professionale');

    $profileChecks = [
        old('business_name', $profile?->business_name ?? ''),
        old('services', $profile?->services ?? ''),
        old('target', $profile?->target ?? ''),
        old('cta', $profile?->cta ?? ''),
        old('default_goal', $profile?->default_goal ?? ''),
    ];
    $filledCount = collect($profileChecks)->filter(fn ($v) => filled($v))->count();
    $completionRate = (int) round(($filledCount / max(1, count($profileChecks))) * 100);
    $strategyUpdatedAt = $strategy?->updated_at ? $strategy->updated_at->format('d/m/Y H:i') : '-';
    $strategyStatus = $strategyLocked ? 'LOCKED' : 'AUTO-REFRESH';
    $hasDemoPlan = $demoPlan !== null;
    $demoItems = $demoPlan?->items ?? collect();
    if (!($demoItems instanceof \Illuminate\Support\Collection)) {
        $demoItems = collect($demoItems);
    }
    $quickstartTone = old('default_tone', $profile?->default_tone ?? 'professionale');
    $quickstartNeedsImages = $images->isEmpty();
    $demoRangeLabel = $hasDemoPlan && $demoPlan?->start_date && $demoPlan?->end_date
        ? $demoPlan->start_date->format('d/m') . ' - ' . $demoPlan->end_date->format('d/m')
        : '7 giorni';
    $quickstartBadgeText = $hasDemoPlan ? 'pronta' : ($quickstartDismissed ? 'chiusa' : 'da creare');
    $quickstartBadgeClass = $hasDemoPlan
        ? 'border-cyan-200 bg-cyan-50 text-cyan-700'
        : ($quickstartDismissed ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-700');

    $brandReadinessItems = collect([
        [
            'label' => 'Profilo azienda',
            'ready' => filled($profile?->business_name) && filled($profile?->industry) && filled($profile?->services),
            'hint' => 'Descrivi bene attivita, settore e servizi principali.',
            'href' => '#brand-profile-section',
        ],
        [
            'label' => 'Target e obiettivo',
            'ready' => filled($profile?->target) && filled($profile?->default_goal) && filled($profile?->cta),
            'hint' => 'Più l AI capisce chi vuoi raggiungere, più i contenuti risultano centrati.',
            'href' => '#brand-defaults-section',
        ],
        [
            'label' => 'Direzione strategica',
            'ready' => filled($profile?->vision) && filled($profile?->values) && filled(data_get($analysis, 'primary_goal')),
            'hint' => 'Dai alla strategia più contesto su posizionamento e tono del brand.',
            'href' => '#brand-strategy-section',
        ],
        [
            'label' => 'Materiali visual',
            'ready' => $images->count() >= 3 && $logos->count() >= 1,
            'hint' => 'Logo e immagini reali aiutano l output a restare coerente e credibile.',
            'href' => '#brand-assets-section',
        ],
        [
            'label' => 'Variabili riutilizzabili',
            'ready' => count($assetVariableCatalog) > 0,
            'hint' => 'Crea riferimenti utili per persone, luoghi, prodotti e ambienti ricorrenti.',
            'href' => '#brand-variables-section',
        ],
    ])->values();
    $brandReadinessDone = $brandReadinessItems->filter(fn ($item) => (bool) ($item['ready'] ?? false))->count();
    $brandReadinessRate = (int) round(($brandReadinessDone / max(1, $brandReadinessItems->count())) * 100);
    $brandReadinessMissing = $brandReadinessItems->filter(fn ($item) => !($item['ready'] ?? false))->values();

    $inputClass = 'block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200';
    $labelClass = 'mb-1 block text-sm font-semibold text-gray-700';
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Brand Center</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                    {{ $isOnboardingPending ? 'Attiva la tua prova guidata' : 'Profilo azienda e asset' }}
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    {{ $isOnboardingPending
                        ? 'Ti chiediamo poche informazioni utili e almeno un immagine reale: poi l app prepara una settimana demo con 2 post immagine e 1 reel, pronta da esplorare o rigenerare.'
                        : 'Gestisci profilo brand, setup rapido iniziale, asset visual e strategia che alimentano i contenuti dell app.' }}
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Completamento {{ $completionRate }}%
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $logos->count() }} logo
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $images->count() }} immagini
                    </span>
                    <span class="inline-flex items-center rounded-full border {{ $strategyLocked ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }} px-2.5 py-1 text-xs font-semibold">
                        Strategia {{ $strategyStatus }}
                    </span>
                    <span class="inline-flex items-center rounded-full border {{ $quickstartBadgeClass }} px-2.5 py-1 text-xs font-semibold">
                        Quickstart {{ $quickstartBadgeText }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri calendario
                    </a>
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri wizard piano
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri contenuti
                    </a>
                    <a href="{{ route('dashboard') }}" class="ui-btn-primary justify-center">
                        Torna alla dashboard
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
            <p class="font-semibold">Controlla i campi richiesti prima di continuare:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($shouldShowQuickstart)
        @if(!$hasDemoPlan)
        <div class="overflow-hidden rounded-3xl border border-cyan-200 bg-gradient-to-br from-cyan-50 via-white to-emerald-50 p-6 shadow-sm">
            <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-cyan-700">Quickstart</div>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">Crea una prova credibile in pochi minuti</h2>
                    <p class="mt-3 max-w-2xl text-sm text-gray-600">
                        Questa e una prova guidata, pensata per farti vedere subito il valore del prodotto.
                        Ti basta inserire i dati base del brand, caricare almeno 1 immagine reale e, se vuoi,
                        definire gia una variabile visiva. La demo poi puo essere rigenerata o eliminata.
                    </p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/80 bg-white/80 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Step 1</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">Dati essenziali</p>
                            <p class="mt-1 text-xs text-gray-600">Nome, settore, servizi, target e tono.</p>
                        </div>
                        <div class="rounded-2xl border border-white/80 bg-white/80 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Step 2</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">1 logo + immagini</p>
                            <p class="mt-1 text-xs text-gray-600">Il logo e facoltativo, le immagini reali servono per una prova piu credibile.</p>
                        </div>
                        <div class="rounded-2xl border border-white/80 bg-white/80 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Output</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">7 giorni di demo</p>
                            <p class="mt-1 text-xs text-gray-600">3 contenuti: 2 post immagine e 1 reel su Instagram e Facebook.</p>
                        </div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        I motori AI e i provider restano selezionabili nei singoli contenuti: qui stai solo attivando la prova iniziale.
                    </div>
                </div>

                <form
                    method="POST"
                    action="{{ route('profile.brand.quickstart.store') }}"
                    enctype="multipart/form-data"
                    class="js-quickstart-generation-submit rounded-3xl border border-gray-200 bg-white p-5 shadow-sm"
                    data-loader-title="Sto preparando la tua demo iniziale"
                    data-loader-subtitle="Raccolgo brand, immagini e variabili per creare i primi contenuti e mostrarti subito una presenza social credibile."
                    data-loader-estimate="165"
                >
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label for="quick_business_name" class="{{ $labelClass }}">Nome attivita</label>
                            <input id="quick_business_name" type="text" name="business_name" value="{{ old('business_name', $profile?->business_name ?? '') }}" class="{{ $inputClass }}" required>
                        </div>
                        <div>
                            <label for="quick_industry" class="{{ $labelClass }}">Settore</label>
                            <input id="quick_industry" type="text" name="industry" value="{{ old('industry', $profile?->industry ?? '') }}" placeholder="Es. ristorante, studio dentistico, ecommerce, consulenza" class="{{ $inputClass }}" required>
                        </div>
                        <div>
                            <label for="quick_services" class="{{ $labelClass }}">Cosa vendi o fai</label>
                            <textarea id="quick_services" name="services" rows="3" class="{{ $inputClass }}" placeholder="Es. brunch, aperitivi, eventi privati oppure consulenza fiscale, pratiche, supporto aziende" required>{{ old('services', $profile?->services ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="quick_target" class="{{ $labelClass }}">Chi vuoi raggiungere</label>
                            <textarea id="quick_target" name="target" rows="3" class="{{ $inputClass }}" placeholder="Es. famiglie in zona, professionisti, PMI, coppie, clienti premium" required>{{ old('target', $profile?->target ?? '') }}</textarea>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="quick_cta" class="{{ $labelClass }}">Invito all azione</label>
                                <input id="quick_cta" type="text" name="cta" value="{{ old('cta', $profile?->cta ?? '') }}" placeholder="Es. Scrivici su WhatsApp" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label for="quick_tone" class="{{ $labelClass }}">Tono consigliato</label>
                                <select id="quick_tone" name="default_tone" class="{{ $inputClass }}">
                                    <option value="professionale" @selected($quickstartTone === 'professionale')>Professionale</option>
                                    <option value="amichevole" @selected($quickstartTone === 'amichevole')>Amichevole</option>
                                    <option value="ironico" @selected($quickstartTone === 'ironico')>Ironico</option>
                                    <option value="ispirazionale" @selected($quickstartTone === 'ispirazionale')>Ispirazionale</option>
                                    <option value="tecnico" @selected($quickstartTone === 'tecnico')>Tecnico</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="quick_notes" class="{{ $labelClass }}">Dettagli utili per la prova</label>
                            <textarea id="quick_notes" name="notes" rows="3" class="{{ $inputClass }}" placeholder="Es. punti forti, fascia prezzo, zone servite, promozioni attive, stile da evitare">{{ old('notes', $profile?->notes ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="quick_logo" class="{{ $labelClass }}">Logo (facoltativo)</label>
                            <input id="quick_logo" type="file" name="logo" accept="image/*" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="quick_images" class="{{ $labelClass }}">Immagini di riferimento {{ $quickstartNeedsImages ? '(almeno 1 richiesta)' : '(puoi aggiungerne altre)' }}</label>
                            <input id="quick_images" type="file" name="images[]" accept="image/*" multiple class="{{ $inputClass }}" {{ $quickstartNeedsImages ? 'required' : '' }}>
                            <p class="mt-1 text-xs text-gray-500">Vanno bene foto di prodotti, locale, team, lavori svolti o ambienti reali.</p>
                        </div>

                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">Variabile visiva rapida</h3>
                                    <p class="mt-1 text-xs text-gray-600">Facoltativa. Se la compili, l app raggruppa gli asset caricati in una variabile gia pronta per i prompt.</p>
                                </div>
                                <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600">Opzionale</span>
                            </div>
                            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="quick_var_name" class="{{ $labelClass }}">Nome variabile</label>
                                    <input id="quick_var_name" type="text" name="quickstart_variable_name" value="{{ old('quickstart_variable_name') }}" placeholder="Es. Chef Marco, showroom, collezione premium" class="{{ $inputClass }}">
                                </div>
                                <div>
                                    <label for="quick_var_kind" class="{{ $labelClass }}">Tipo</label>
                                    <select id="quick_var_kind" name="quickstart_variable_kind" class="{{ $inputClass }}">
                                        <option value="person" @selected(old('quickstart_variable_kind') === 'person')>Persona</option>
                                        <option value="location" @selected(old('quickstart_variable_kind') === 'location')>Luogo</option>
                                        <option value="product" @selected(old('quickstart_variable_kind') === 'product')>Prodotto</option>
                                        <option value="custom" @selected(old('quickstart_variable_kind', 'custom') === 'custom')>Custom</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="quick_var_description" class="{{ $labelClass }}">Descrizione breve</label>
                                    <input id="quick_var_description" type="text" name="quickstart_variable_description" value="{{ old('quickstart_variable_description') }}" placeholder="Es. volto del brand o prodotto di punta" class="{{ $inputClass }}">
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                            <p class="text-xs text-gray-500">La demo usa Instagram e Facebook, con 2 post immagine e 1 reel.</p>
                            <button type="submit" class="ui-btn-primary justify-center">
                                Crea la prova guidata
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        @else
        <div class="overflow-hidden rounded-3xl border border-cyan-200 bg-gradient-to-br from-white via-cyan-50 to-emerald-50 p-6 shadow-sm">
            <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-cyan-700">Demo iniziale</div>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">La tua prova e gia pronta</h2>
                    <p class="mt-3 max-w-2xl text-sm text-gray-600">
                        Hai gia una demo attiva per {{ $demoRangeLabel }}. Ora puoi decidere se tenerla nel workspace,
                        rigenerarla con i dati attuali oppure eliminarla e chiudere il quickstart.
                    </p>
                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full border border-cyan-200 bg-white px-3 py-1 text-xs font-semibold text-cyan-700">
                            {{ $demoItems->count() }} contenuti
                        </span>
                        <span class="inline-flex items-center rounded-full border border-cyan-200 bg-white px-3 py-1 text-xs font-semibold text-cyan-700">
                            {{ $demoRangeLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-cyan-200 bg-white px-3 py-1 text-xs font-semibold text-cyan-700">
                            Instagram + Facebook
                        </span>
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        @foreach($demoItems as $demoItem)
                            <article class="rounded-2xl border border-white/80 bg-white/80 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ strtoupper((string) $demoItem->format) }}</p>
                                <h3 class="mt-2 text-sm font-semibold text-gray-900">{{ $demoItem->title }}</h3>
                                <p class="mt-1 text-xs text-gray-600">{{ optional($demoItem->scheduled_at)->format('d/m H:i') ?: 'Data da definire' }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('profile.brand.quickstart.save') }}">
                            @csrf
                            <button type="submit" class="ui-btn-primary w-full justify-center">
                                Salva i contenuti nel workspace
                            </button>
                        </form>
                        <a href="{{ route('calendar') }}" class="ui-btn-primary w-full justify-center">
                            Apri calendario demo
                        </a>
                        <a href="{{ route('posts.index') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Apri contenuti generati
                        </a>
                        <form
                            method="POST"
                            action="{{ route('profile.brand.quickstart.regenerate') }}"
                            class="js-quickstart-generation-submit"
                            data-loader-title="Sto rigenerando la tua demo iniziale"
                            data-loader-subtitle="Aggiorno il setup e ricreo i contenuti demo usando le informazioni brand più recenti."
                            data-loader-estimate="150"
                        >
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-700 hover:bg-cyan-100">
                                Rigenera demo
                            </button>
                        </form>
                        <form method="POST" action="{{ route('profile.brand.quickstart.destroy') }}" onsubmit="return confirm('Eliminare la demo iniziale?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                                Elimina demo
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endif
    @endif

    @if(!$shouldShowQuickstart)
        <div class="overflow-hidden rounded-3xl border border-indigo-200 bg-gradient-to-br from-white via-indigo-50/60 to-cyan-50/60 p-6 shadow-sm">
            <div class="grid gap-5 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Prossimo passo</div>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">Completa il Brand Center e dai più contesto alla macchina</h2>
                    <p class="mt-3 max-w-2xl text-sm text-gray-600">
                        Più dettagli aggiungi qui, più Social AI riesce a costruire strategie, prompt e contenuti vicini alla tua attività.
                        Non serve fare tutto subito: basta completare un blocco alla volta e salvare.
                    </p>

                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-white/80">
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-400 via-cyan-400 to-emerald-400" style="width: {{ max(14, $brandReadinessRate) }}%"></div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-white px-3 py-1 text-xs font-semibold text-indigo-700">
                            Brand readiness {{ $brandReadinessRate }}%
                        </span>
                        <span class="inline-flex items-center rounded-full border border-white/90 bg-white/80 px-3 py-1 text-xs font-semibold text-gray-700">
                            {{ $brandReadinessDone }}/{{ $brandReadinessItems->count() }} aree già coperte
                        </span>
                        @if($brandReadinessMissing->count() > 0)
                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                {{ $brandReadinessMissing->count() }} aree da rinforzare
                            </span>
                        @endif
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @forelse($brandReadinessMissing->take(4) as $item)
                        <a href="{{ $item['href'] }}" class="rounded-2xl border border-white/90 bg-white/90 p-4 transition hover:-translate-y-0.5 hover:shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Da completare</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">{{ $item['label'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-gray-600">{{ $item['hint'] }}</p>
                        </a>
                    @empty
                        <div class="sm:col-span-2 rounded-2xl border border-emerald-200 bg-white/90 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Profilo ben compilato</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">Hai già dato alla macchina una base solida.</p>
                            <p class="mt-1 text-xs leading-5 text-gray-600">Puoi rifinire i dettagli quando vuoi, ma strategia e contenuti hanno già un contesto utile da cui partire.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <form method="POST" action="{{ route('profile.brand.store') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                <input type="hidden" name="strategy_action" id="strategy_action" value="save">

                <div id="brand-profile-section" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Profilo completo azienda</h2>
                    <p class="mt-1 text-sm text-gray-600">Qui completi e rifinisci i dati del brand usati dalla strategia editoriale.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="business_name" class="{{ $labelClass }}">Nome attivita</label>
                            <input id="business_name" type="text" name="business_name" value="{{ old('business_name', $profile?->business_name ?? '') }}" class="{{ $inputClass }}" required />
                        </div>
                        <div>
                            <label for="industry" class="{{ $labelClass }}">Settore</label>
                            <input id="industry" type="text" name="industry" value="{{ old('industry', $profile?->industry ?? '') }}" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="website" class="{{ $labelClass }}">Sito web</label>
                            <input id="website" type="text" name="website" value="{{ old('website', $profile?->website ?? '') }}" placeholder="https://..." class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="cta" class="{{ $labelClass }}">CTA principale</label>
                            <input id="cta" type="text" name="cta" value="{{ old('cta', $profile?->cta ?? '') }}" placeholder="Es. Scrivici su WhatsApp" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="services" class="{{ $labelClass }}">Servizi principali</label>
                            <textarea id="services" name="services" rows="3" class="{{ $inputClass }}" placeholder="Es. siti web, web app, marketing, chatbot AI...">{{ old('services', $profile?->services ?? '') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Elenca 3-6 servizi separati da virgole.</p>
                        </div>
                        <div>
                            <label for="target" class="{{ $labelClass }}">Target ideale</label>
                            <textarea id="target" name="target" rows="3" class="{{ $inputClass }}" placeholder="Es. PMI e attivita locali che vogliono piu clienti">{{ old('target', $profile?->target ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="notes" class="{{ $labelClass }}">Note</label>
                            <textarea id="notes" name="notes" rows="3" class="{{ $inputClass }}" placeholder="Extra info su posizionamento e differenziatori">{{ old('notes', $profile?->notes ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="vision" class="{{ $labelClass }}">Vision / Mission</label>
                            <textarea id="vision" name="vision" rows="3" class="{{ $inputClass }}" placeholder="Es. diventare riferimento locale">{{ old('vision', $profile?->vision ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="values" class="{{ $labelClass }}">Valori brand</label>
                            <textarea id="values" name="values" rows="3" class="{{ $inputClass }}" placeholder="Es. trasparenza, rapidita, qualita">{{ old('values', $profile?->values ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="business_hours" class="{{ $labelClass }}">Orari business (opzionale)</label>
                            <textarea id="business_hours" name="business_hours" rows="2" class="{{ $inputClass }}" placeholder="Lun-Ven 9:00-18:00">{{ old('business_hours', $profile?->business_hours ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="brand_palette" class="{{ $labelClass }}">Palette brand (opzionale)</label>
                            <input id="brand_palette" type="text" name="brand_palette" value="{{ old('brand_palette', $profile?->brand_palette ?? '') }}" placeholder="Es. #0F172A, #2563EB, #F59E0B" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="seasonal_offers" class="{{ $labelClass }}">Offerte/promozioni periodo (opzionale)</label>
                            <textarea id="seasonal_offers" name="seasonal_offers" rows="3" class="{{ $inputClass }}" placeholder="Es. promo mese, bundle, sconto primo mese">{{ old('seasonal_offers', $profile?->seasonal_offers ?? '') }}</textarea>
                        </div>
                    </div>
                </div>

                <div id="brand-defaults-section" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Default contenuti</h2>
                    <p class="mt-1 text-sm text-gray-600">Precompilazione automatica quando apri un nuovo piano editoriale.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="default_goal" class="{{ $labelClass }}">Obiettivo default</label>
                            <textarea id="default_goal" name="default_goal" rows="2" class="{{ $inputClass }}" placeholder="Es. Lead + Awareness + Autorita">{{ old('default_goal', $profile?->default_goal ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="default_tone" class="{{ $labelClass }}">Tone default</label>
                            <select id="default_tone" name="default_tone" class="{{ $inputClass }}">
                                <option value="professionale" @selected($tone === 'professionale')>Professionale</option>
                                <option value="amichevole" @selected($tone === 'amichevole')>Amichevole</option>
                                <option value="ironico" @selected($tone === 'ironico')>Ironico</option>
                                <option value="ispirazionale" @selected($tone === 'ispirazionale')>Ispirazionale</option>
                                <option value="tecnico" @selected($tone === 'tecnico')>Tecnico/Esperto</option>
                            </select>
                        </div>
                        <div>
                            <label for="default_posts_per_week" class="{{ $labelClass }}">Post/settimana default</label>
                            <input id="default_posts_per_week" type="number" min="1" max="21" step="1" name="default_posts_per_week" value="{{ old('default_posts_per_week', $profile?->default_posts_per_week ?? 5) }}" class="{{ $inputClass }}" />
                        </div>

                        <div>
                            <label class="{{ $labelClass }}">Piattaforme default</label>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ([
                                    'instagram' => 'Instagram',
                                    'facebook' => 'Facebook',
                                    'tiktok' => 'TikTok',
                                    'linkedin' => 'LinkedIn',
                                    'youtube' => 'YouTube',
                                    'threads' => 'Threads',
                                ] as $k => $label)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                        <input type="checkbox" name="default_platforms[]" value="{{ $k }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($k, $defaultPlatforms, true)) />
                                        <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="{{ $labelClass }}">Formati default</label>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ([
                                    'reel' => 'Reel / Short video',
                                    'post' => 'Post immagine / carousel',
                                    'story' => 'Stories',
                                    'live' => 'Live',
                                    'blog' => 'Articolo / long copy',
                                    'newsletter' => 'Newsletter',
                                ] as $k => $label)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                        <input type="checkbox" name="default_formats[]" value="{{ $k }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($k, $defaultFormats, true)) />
                                        <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div id="brand-strategy-section" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Strategy Studio</h2>
                            <p class="mt-1 text-sm text-gray-600">Strategia base usata in ogni prompt (singolo e piano). Aggiornata: {{ $strategyUpdatedAt }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full border {{ $strategyLocked ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }} px-2.5 py-1 text-xs font-semibold">
                            {{ $strategyStatus }}
                        </span>
                    </div>

                    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <input type="hidden" name="strategy_locked" value="0">
                            <input type="checkbox" name="strategy_locked" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked($strategyLocked)>
                            Lock manuale strategia (blocca auto-rigenerazione)
                        </label>
                        <p class="mt-1 text-xs text-gray-500">Con lock ON la strategia non viene rigenerata automaticamente su update brand/assets.</p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <label for="strategy_goal_primary" class="{{ $labelClass }}">Goal primario</label>
                            <input id="strategy_goal_primary" type="text" name="strategy_goal_primary" value="{{ old('strategy_goal_primary', data_get($analysis, 'primary_goal', $profile?->default_goal ?? 'Awareness + Lead')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_goal_secondary" class="{{ $labelClass }}">Goal secondario</label>
                            <input id="strategy_goal_secondary" type="text" name="strategy_goal_secondary" value="{{ old('strategy_goal_secondary', data_get($analysis, 'secondary_goal', 'Engagement + Fiducia')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_kpi_primary" class="{{ $labelClass }}">KPI primario</label>
                            <input id="strategy_kpi_primary" type="text" name="strategy_kpi_primary" value="{{ old('strategy_kpi_primary', data_get($analysis, 'kpi_primary', 'Copertura qualificata')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_kpi_secondary" class="{{ $labelClass }}">KPI secondario</label>
                            <input id="strategy_kpi_secondary" type="text" name="strategy_kpi_secondary" value="{{ old('strategy_kpi_secondary', data_get($analysis, 'kpi_secondary', 'Interazioni utili e conversione contatti')) }}" class="{{ $inputClass }}">
                        </div>
                        <div class="lg:col-span-2">
                            <label for="strategy_audience_focus" class="{{ $labelClass }}">Focus audience</label>
                            <textarea id="strategy_audience_focus" name="strategy_audience_focus" rows="2" class="{{ $inputClass }}">{{ old('strategy_audience_focus', data_get($analysis, 'audience_focus', $profile?->target ?? '')) }}</textarea>
                        </div>
                        <div class="lg:col-span-2">
                            <label for="strategy_offer_focus" class="{{ $labelClass }}">Focus offerta</label>
                            <textarea id="strategy_offer_focus" name="strategy_offer_focus" rows="2" class="{{ $inputClass }}">{{ old('strategy_offer_focus', data_get($analysis, 'offer_focus', $profile?->seasonal_offers ?? '')) }}</textarea>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <label for="strategy_visual_style" class="{{ $labelClass }}">Stile visual</label>
                            <input id="strategy_visual_style" type="text" name="strategy_visual_style" value="{{ old('strategy_visual_style', data_get($visual, 'style', 'Pulito, moderno, realistico, orientato conversione')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_visual_mood" class="{{ $labelClass }}">Mood visual</label>
                            <input id="strategy_visual_mood" type="text" name="strategy_visual_mood" value="{{ old('strategy_visual_mood', data_get($visual, 'mood', 'Professionale con energia positiva')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_visual_palette" class="{{ $labelClass }}">Palette operativa</label>
                            <input id="strategy_visual_palette" type="text" name="strategy_visual_palette" value="{{ old('strategy_visual_palette', implode(', ', (array) data_get($visual, 'palette', []))) }}" placeholder="#2563EB, #22D3EE, #0F172A" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_palette_mode" class="{{ $labelClass }}">Modalita palette</label>
                            <input id="strategy_palette_mode" type="text" name="strategy_palette_mode" value="{{ old('strategy_palette_mode', data_get($visual, 'palette_mode', 'brand_palette')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_logo_rule" class="{{ $labelClass }}">Regola logo</label>
                            <input id="strategy_logo_rule" type="text" name="strategy_logo_rule" value="{{ old('strategy_logo_rule', data_get($visual, 'logo_rule', 'Usa solo loghi reali caricati in assets.')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_visual_do" class="{{ $labelClass }}">Visual do</label>
                            <input id="strategy_visual_do" type="text" name="strategy_visual_do" value="{{ old('strategy_visual_do', data_get($visual, 'visual_do', 'Composizioni leggibili e coerenti.')) }}" class="{{ $inputClass }}">
                        </div>
                        <div class="lg:col-span-2">
                            <label for="strategy_visual_dont" class="{{ $labelClass }}">Visual dont</label>
                            <input id="strategy_visual_dont" type="text" name="strategy_visual_dont" value="{{ old('strategy_visual_dont', data_get($visual, 'visual_dont', 'No watermark e no testo inventato.')) }}" class="{{ $inputClass }}">
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <label for="strategy_posts_per_week" class="{{ $labelClass }}">Frequenza post/settimana</label>
                            <input id="strategy_posts_per_week" type="number" min="1" max="31" name="strategy_posts_per_week" value="{{ old('strategy_posts_per_week', data_get($publishing, 'posts_per_week', $profile?->default_posts_per_week ?? 5)) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_best_days" class="{{ $labelClass }}">Giorni migliori</label>
                            <input id="strategy_best_days" type="text" name="strategy_best_days" value="{{ old('strategy_best_days', data_get($publishing, 'best_days', 'Lun-Mar-Gio')) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_best_times" class="{{ $labelClass }}">Orari migliori</label>
                            <input id="strategy_best_times" type="text" name="strategy_best_times" value="{{ old('strategy_best_times', implode(', ', (array) data_get($publishing, 'best_times', ['11:00', '15:00', '19:00']))) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_channel_priority" class="{{ $labelClass }}">Priorita canali</label>
                            <input id="strategy_channel_priority" type="text" name="strategy_channel_priority" value="{{ old('strategy_channel_priority', data_get($publishing, 'channel_priority', implode(', ', $defaultPlatforms))) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_format_priority" class="{{ $labelClass }}">Priorita formati</label>
                            <input id="strategy_format_priority" type="text" name="strategy_format_priority" value="{{ old('strategy_format_priority', data_get($publishing, 'format_priority', implode(', ', $defaultFormats))) }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="strategy_cadence_rule" class="{{ $labelClass }}">Regola pubblicazione</label>
                            <input id="strategy_cadence_rule" type="text" name="strategy_cadence_rule" value="{{ old('strategy_cadence_rule', data_get($publishing, 'cadence_rule', 'Alterna rubriche e formati evitando ripetizioni.')) }}" class="{{ $inputClass }}">
                        </div>
                        <div class="lg:col-span-2">
                            <label for="strategy_notes" class="{{ $labelClass }}">Note strategiche libere</label>
                            <textarea id="strategy_notes" name="strategy_notes" rows="3" class="{{ $inputClass }}">{{ old('strategy_notes', $strategy?->strategy_notes ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button type="button" id="strategyRegenerateBtn" class="inline-flex items-center rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100">
                            Rigenera strategia da brand/assets
                        </button>
                        <p class="text-xs text-gray-500">La rigenerazione mantiene i campi manuali inviati in questo form.</p>
                    </div>
                </div>

                <div id="brand-assets-section" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Upload assets</h2>
                    <p class="mt-1 text-sm text-gray-600">Carica logo e immagini di riferimento per guidare output e stile.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="logo" class="{{ $labelClass }}">Logo (opzionale)</label>
                            <input id="logo" type="file" name="logo" accept="image/*" class="{{ $inputClass }}" />
                            <p class="mt-1 text-xs text-gray-500">PNG consigliato, trasparente se possibile.</p>
                        </div>
                        <div>
                            <label for="images" class="{{ $labelClass }}">Immagini (multiple)</label>
                            <input id="images" type="file" name="images[]" accept="image/*" multiple class="{{ $inputClass }}" />
                            <p class="mt-1 text-xs text-gray-500">Esempi: prodotti, progetti, mood, palette.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Nuovo piano editoriale
                    </a>
                    <button type="submit" class="ui-btn-primary justify-center">
                        Salva profilo completo
                    </button>
                </div>
            </form>

            <div id="brand-variables-section" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Assets caricati</h2>
                        <p class="mt-1 text-sm text-gray-600">Seleziona asset e usa elimina multipla o elimina singolo.</p>
                    </div>

                    @if($assets->count() > 0)
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" id="selectAllAssets" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Seleziona tutti</button>
                            <button type="button" id="clearAllAssets" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Deseleziona</button>
                            <button type="button" id="bulkDeleteBtn" data-bulk-url="{{ route('profile.brand.assets.destroy') }}" class="inline-flex items-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Elimina selezionati</button>
                        </div>
                    @endif
                </div>

                @if($assets->count() === 0)
                    <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center text-sm text-gray-600">
                        Nessun asset caricato.
                    </div>
                @else
                    <div class="mt-6 space-y-8">
                        <div>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Logo</h3>
                                <span class="text-xs text-gray-500">{{ $logos->count() }} file</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @forelse($logos as $a)
                                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <div class="border-b border-gray-200 bg-gray-50 p-2">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $a->id }}" data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="flex aspect-square items-center justify-center p-2">
                                            <img src="{{ asset('storage/' . ltrim($a->path, '/')) }}" class="max-h-full max-w-full" alt="logo">
                                        </div>

                                        <div class="truncate px-2 py-1 text-[11px] text-gray-600">{{ $a->original_name ?? $a->path }}</div>

                                        <div class="px-2 pb-2">
                                            <form method="POST" action="{{ route('profile.brand.asset.destroy', $a->id) }}" onsubmit="return confirm('Eliminare questo logo?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Elimina</button>
                                            </form>
                                        </div>
                                    </article>
                                @empty
                                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessun logo caricato.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Immagini</h3>
                                <span class="text-xs text-gray-500">{{ $images->count() }} file</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @forelse($images as $a)
                                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <div class="border-b border-gray-200 bg-gray-50 p-2">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $a->id }}" data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="aspect-square overflow-hidden">
                                            <img src="{{ asset('storage/' . ltrim($a->path, '/')) }}" class="h-full w-full object-cover" alt="image">
                                        </div>

                                        <div class="truncate px-2 py-1 text-[11px] text-gray-600">{{ $a->original_name ?? $a->path }}</div>

                                        <div class="px-2 pb-2">
                                            <form method="POST" action="{{ route('profile.brand.asset.destroy', $a->id) }}" onsubmit="return confirm('Eliminare questa immagine?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Elimina</button>
                                            </form>
                                        </div>
                                    </article>
                                @empty
                                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessuna immagine caricata.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Variabili asset (Object Library)</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Raggruppa immagini/logo in variabili riutilizzabili (es. persona: Manuel, location: Ufficio, prodotto: Jaguar).
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        {{ count($assetVariableCatalog) }} variabili attive
                    </span>
                </div>

                <form method="POST" action="{{ route('profile.brand.variables.store') }}" class="mt-4 space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                    @csrf
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div>
                            <label for="var_name" class="{{ $labelClass }}">Nome variabile</label>
                            <input id="var_name" type="text" name="name" value="{{ old('name') }}" placeholder="Es. Manuel" class="{{ $inputClass }}" required>
                        </div>
                        <div>
                            <label for="var_kind" class="{{ $labelClass }}">Tipo</label>
                            <select id="var_kind" name="kind" class="{{ $inputClass }}">
                                <option value="person" @selected(old('kind') === 'person')>Persona</option>
                                <option value="location" @selected(old('kind') === 'location')>Luogo/Ufficio</option>
                                <option value="product" @selected(old('kind') === 'product')>Prodotto</option>
                                <option value="custom" @selected(old('kind') === 'custom')>Custom</option>
                            </select>
                        </div>
                        <div class="lg:col-span-1">
                            <label for="var_description" class="{{ $labelClass }}">Descrizione breve</label>
                            <input id="var_description" type="text" name="description" value="{{ old('description') }}" placeholder="Es. Dipendente commerciale" class="{{ $inputClass }}">
                        </div>
                    </div>

                    @if($variableCandidateAssets->isEmpty())
                        <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-4 text-sm text-gray-600">
                            Nessun asset disponibile: carica prima immagini/logo dal blocco Upload assets.
                        </div>
                    @else
                        <div>
                            <p class="mb-2 text-sm font-semibold text-gray-700">Seleziona asset da associare</p>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @foreach($variableCandidateAssets as $asset)
                                    @php
                                        $assetId = (int) $asset->id;
                                        $isSelectedForVariable = in_array($assetId, $selectedVariableAssetIds, true);
                                    @endphp
                                    <label class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <div class="border-b border-gray-200 bg-gray-50 p-2">
                                            <span class="inline-flex items-center gap-2 text-xs text-gray-600">
                                                <input
                                                    type="checkbox"
                                                    name="asset_ids[]"
                                                    value="{{ $assetId }}"
                                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    @checked($isSelectedForVariable)
                                                >
                                                #{{ $assetId }}
                                            </span>
                                        </div>
                                        <div class="aspect-square overflow-hidden">
                                            <img src="{{ asset('storage/' . ltrim($asset->path, '/')) }}" class="h-full w-full object-cover" alt="asset {{ $assetId }}">
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end">
                        <button type="submit" class="ui-btn-primary">
                            Crea variabile
                        </button>
                    </div>
                </form>

                @if($assetVariables->isEmpty())
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                        Nessuna variabile creata. Crea la prima per usare riferimenti oggetto/persone nei prompt.
                    </div>
                @else
                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach($assetVariableCatalog as $variable)
                            @php
                                $variableId = (int) ($variable['id'] ?? 0);
                                $varKind = (string) ($variable['kind'] ?? 'custom');
                                $varName = (string) ($variable['name'] ?? 'variabile');
                                $varDesc = trim((string) ($variable['description'] ?? ''));
                                $varSlug = (string) ($variable['slug'] ?? '');
                                $varAssets = is_array($variable['assets'] ?? null) ? $variable['assets'] : [];
                            @endphp
                            <article class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $varKind }}</p>
                                        <h3 class="mt-1 text-sm font-semibold text-gray-900">{{ $varName }}</h3>
                                        <p class="mt-1 text-xs text-indigo-700">Token: {{ '@' . $varSlug }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('profile.brand.variables.destroy', $variableId) }}" onsubmit="return confirm('Eliminare questa variabile asset?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                            Elimina
                                        </button>
                                    </form>
                                </div>
                                @if($varDesc !== '')
                                    <p class="mt-2 text-xs text-gray-600">{{ $varDesc }}</p>
                                @endif
                                <div class="mt-3 grid grid-cols-4 gap-2">
                                    @foreach(array_slice($varAssets, 0, 4) as $assetPreview)
                                        @php
                                            $assetPath = (string) ($assetPreview['path'] ?? '');
                                        @endphp
                                        @if($assetPath !== '')
                                            <div class="aspect-square overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                                <img src="{{ asset('storage/' . ltrim($assetPath, '/')) }}" class="h-full w-full object-cover" alt="variable asset">
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Stato profilo</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Setup iniziale</p>
                        <p class="mt-1 text-sm font-semibold {{ $isOnboardingPending ? 'text-amber-700' : 'text-emerald-700' }}">
                            {{ $isOnboardingPending ? 'Da completare' : 'Attivo' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Demo iniziale</p>
                        <p class="mt-1 text-sm font-semibold {{ $hasDemoPlan ? 'text-cyan-700' : ($quickstartDismissed ? 'text-emerald-700' : 'text-gray-900') }}">
                            {{ $hasDemoPlan ? 'Disponibile' : ($quickstartDismissed ? 'Chiusa' : 'Non presente') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Completamento campi chiave</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $completionRate }}%</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Asset totali</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $assets->count() }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Piattaforme default</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ max(0, count($defaultPlatforms)) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Stato strategia</p>
                        <p class="mt-1 text-sm font-semibold {{ $strategyLocked ? 'text-amber-700' : 'text-emerald-700' }}">{{ $strategyStatus }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Ultimo update strategia</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $strategyUpdatedAt }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Collegamenti utili</h2>
                <p class="mt-1 text-sm text-gray-600">Aree principali collegate al profilo brand.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Wizard Piano</p>
                        <p class="mt-1 text-xs text-gray-600">Imposta strategia e periodo contenuti.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla distribuzione uscite.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci post e output AI.</p>
                    </a>
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Settings</p>
                        <p class="mt-1 text-xs text-gray-600">Configura opzioni applicazione.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

@include('partials.generation-loader')

<script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const checkboxes = () => Array.from(document.querySelectorAll('.asset-checkbox'));
        const selected = () => checkboxes().filter(cb => cb.checked);

        const profileForm = document.querySelector('form[action="{{ route('profile.brand.store') }}"]');
        const strategyActionInput = document.getElementById('strategy_action');
        const strategyRegenerateBtn = document.getElementById('strategyRegenerateBtn');
        const btnSelectAll = document.getElementById('selectAllAssets');
        const btnClearAll = document.getElementById('clearAllAssets');
        const btnBulkDelete = document.getElementById('bulkDeleteBtn');
        const quickstartForms = document.querySelectorAll('form.js-quickstart-generation-submit');

        if (quickstartForms.length > 0 && window.HostupGenerationLoader) {
            quickstartForms.forEach((form) => {
                form.addEventListener('submit', () => {
                    window.HostupGenerationLoader.show({
                        title: form.dataset.loaderTitle || 'Sto preparando la demo iniziale',
                        subtitle: form.dataset.loaderSubtitle || 'Sto raccogliendo contesto brand, asset e strategia per costruire i primi contenuti.',
                        estimateSeconds: Number(form.dataset.loaderEstimate || 160),
                        stages: [
                            'Analisi brand e materiali caricati',
                            'Costruzione del mini piano editoriale',
                            'Generazione e salvataggio dei contenuti demo',
                        ],
                    });
                });
            });
        }

        if (strategyRegenerateBtn && profileForm && strategyActionInput) {
            strategyRegenerateBtn.addEventListener('click', () => {
                strategyActionInput.value = 'regenerate';
                profileForm.submit();
            });
        }

        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', () => {
                checkboxes().forEach(cb => {
                    cb.checked = true;
                });
            });
        }

        if (btnClearAll) {
            btnClearAll.addEventListener('click', () => {
                checkboxes().forEach(cb => {
                    cb.checked = false;
                });
            });
        }

        if (btnBulkDelete) {
            btnBulkDelete.addEventListener('click', async () => {
                const items = selected();
                if (!items.length) {
                    alert('Seleziona almeno un asset da eliminare.');
                    return;
                }

                if (!confirm(`Eliminare ${items.length} asset selezionati?`)) {
                    return;
                }

                btnBulkDelete.disabled = true;
                btnBulkDelete.textContent = 'Eliminazione...';

                try {
                    const bulkUrl = btnBulkDelete.getAttribute('data-bulk-url');
                    if (!bulkUrl) {
                        throw new Error('Bulk URL missing');
                    }

                    const form = new FormData();
                    form.append('_method', 'DELETE');
                    for (const cb of items) {
                        form.append('asset_ids[]', cb.value);
                    }

                    const res = await fetch(bulkUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: form,
                    });

                    if (!res.ok) {
                        throw new Error(`Bulk delete failed: ${res.status}`);
                    }

                    window.location.reload();
                } catch (e) {
                    console.error(e);
                    alert('Errore durante eliminazione multipla. Controlla console/log.');
                } finally {
                    btnBulkDelete.disabled = false;
                    btnBulkDelete.textContent = 'Elimina selezionati';
                }
            });
        }
    })();
</script>
@endsection
