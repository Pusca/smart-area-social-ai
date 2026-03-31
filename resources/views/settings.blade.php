@extends('layouts.app')

@section('content')
<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $socialAccounts = $socialAccounts ?? collect();
        $metaReady = (bool) ($metaReady ?? false);
        $metaScopes = is_array($metaScopes ?? null) ? $metaScopes : [];
        $activeSocialAccounts = $socialAccounts->where('status', 'active');
        $connectedPlatforms = $activeSocialAccounts->pluck('platform')->filter()->unique()->values();
        $fineTuning = is_array($fineTuning ?? null) ? $fineTuning : [];
        $fineTuningRun = $fineTuning['latest_run'] ?? null;
        $fineTuningActiveModel = trim((string) ($fineTuning['active_model'] ?? ''));
        $fineTuningEligible = (int) ($fineTuning['eligible_examples'] ?? 0);
        $fineTuningMinimum = (int) ($fineTuning['minimum_examples'] ?? 12);
        $fineTuningPlatforms = collect((array) ($fineTuning['platforms'] ?? []))->filter()->values();
        $fineTuningFormats = collect((array) ($fineTuning['formats'] ?? []))->filter()->values();
        $canvaSummary = is_array($canvaSummary ?? null) ? $canvaSummary : [];
        $canvaFeatureEnabled = (bool) ($canvaSummary['enabled'] ?? false);
        $canvaConfigured = (bool) ($canvaSummary['configured'] ?? false);
        $canvaConnected = (bool) ($canvaSummary['connected'] ?? false);
        $canvaTemplatesAvailable = (bool) ($canvaSummary['templates_available'] ?? false);
        $canvaAutofillAvailable = (bool) ($canvaSummary['autofill_available'] ?? false);
        $canvaExportAvailable = (bool) ($canvaSummary['export_available'] ?? false);
        $canvaConnection = $canvaSummary['connection'] ?? null;
        $canvaCatalogPreview = collect((array) ($canvaSummary['catalog_preview'] ?? []))->filter(fn ($row) => is_array($row))->values();
        $canvaWorkflowOptions = is_array($canvaWorkflowOptions ?? null) ? $canvaWorkflowOptions : [];
        $canvaTemplateMappings = $canvaTemplateMappings ?? collect();
    @endphp

    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Setup workspace</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Prepara il workspace una volta, poi lavori piu veloce</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Questo e il punto unico in cui sistemi brand, asset, connessioni e contesto tecnico prima di passare a crea, pianifica e libreria.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
Brand + setup tecnico
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
PWA + Push
                    </span>
                    <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700">
                        Meta {{ $connectedPlatforms->count() > 0 ? $connectedPlatforms->implode(' + ') : 'non collegato' }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('profile.brand') }}" class="ui-btn-primary justify-center">
                        Apri Brand Center
                    </a>
                    <a href="{{ route('posts.create') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri area crea
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Vai alla pianificazione
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                    <a href="{{ route('ai') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri AI Lab
                    </a>
                    @if($metaReady)
                        <a href="{{ route('settings.social.meta.redirect') }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-700 hover:bg-cyan-100">
                            Collega Meta
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

        <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Percorso setup</h2>
                    <p class="mt-1 text-sm text-gray-600">Qui separi cio che si configura di rado da cio che userai tutti i giorni in creazione e pianificazione.</p>
                </div>
                <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                    {{ $setupDone }}/{{ $setupChecks->count() }} aree pronte
                </span>
            </div>

            <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-400 via-cyan-400 to-emerald-400" style="width: {{ $setupRate > 0 ? max(12, $setupRate) : 0 }}%"></div>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <a href="{{ route('profile.brand') }}" class="rounded-2xl border border-gray-200 bg-gray-50/80 p-4 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-white">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Brand Center</p>
                    <p class="mt-2 text-sm font-semibold text-gray-900">{{ $profile?->business_name ?: 'Profilo da completare' }}</p>
                    <p class="mt-1 text-xs text-gray-600">{{ $logosCount }} logo, {{ $imagesCount }} immagini, {{ $knowledgeAssetsCount }} asset di conoscenza, {{ $assetVariablesCount }} variabili attive.</p>
                </a>
                <a href="{{ route('setup.index') }}#meta-connections" class="rounded-2xl border border-gray-200 bg-gray-50/80 p-4 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-white">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connessioni tecniche</p>
                    <p class="mt-2 text-sm font-semibold text-gray-900">{{ $activeSocialAccounts->count() }} account social attivi</p>
                    <p class="mt-1 text-xs text-gray-600">Meta, notifiche browser, PWA e ambiente operativo.</p>
                </a>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Prossimi passi</h2>
            <div class="mt-4 space-y-2">
                @forelse($setupMissing->take(4) as $item)
                    <a href="{{ $item['href'] }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">{{ $item['label'] }}</p>
                        <p class="mt-1 text-xs text-gray-600">{{ $item['hint'] }}</p>
                    </a>
                @empty
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                        <p class="text-sm font-semibold text-emerald-800">Setup ben impostato</p>
                        <p class="mt-1 text-xs text-emerald-700">Puoi passare direttamente a crea, piano editoriale o libreria.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <div id="fine-tuning" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Fine-tuning testo tenant</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">Serve a stabilizzare tono, struttura e tipo di copy del cliente. Foto, video e audio continuano invece a migliorare il grounding dinamico del contenuto.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('settings.fine-tuning.start') }}">
                    @csrf
                    <button type="submit" class="ui-btn-primary justify-center" @disabled($fineTuningEligible < $fineTuningMinimum)>
                        Avvia fine-tuning
                    </button>
                </form>
                <form method="POST" action="{{ route('settings.fine-tuning.sync') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Aggiorna stato
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Esempi utili</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $fineTuningEligible }}</p>
                <p class="mt-1 text-xs text-gray-600">Minimo richiesto: {{ $fineTuningMinimum }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Run piu recente</p>
                <p class="mt-2 text-sm font-semibold text-gray-900">{{ $fineTuningRun?->status ? strtoupper((string) $fineTuningRun->status) : 'NESSUNO' }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ $fineTuningRun?->requested_at ? $fineTuningRun->requested_at->format('d/m/Y H:i') : 'Ancora non avviato' }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Modello attivo</p>
                <p class="mt-2 text-sm font-semibold text-gray-900">{{ $fineTuningActiveModel !== '' ? $fineTuningActiveModel : 'Base model' }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ $fineTuningActiveModel !== '' ? 'Il tenant usa il modello fine-tuned.' : 'Nessun modello personalizzato attivo.' }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mix dataset</p>
                <p class="mt-2 text-sm font-semibold text-gray-900">{{ $fineTuningFormats->isNotEmpty() ? $fineTuningFormats->implode(' / ') : 'Da costruire' }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ $fineTuningPlatforms->isNotEmpty() ? $fineTuningPlatforms->implode(' / ') : 'Nessuna piattaforma rilevata' }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4">
                <p class="text-sm font-semibold text-gray-900">Come viene costruito</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li>Usa contenuti approvati, programmati o pubblicati con caption AI valida.</li>
                    <li>Esclude i contenuti con feedback negativo esplicito.</li>
                    <li>Crea dataset JSONL per tenant e avvia un run OpenAI separato dal runtime standard.</li>
                    <li>Se il run va a buon fine, il modello viene attivato solo per il testo e solo per quel tenant.</li>
                </ul>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-4">
                <p class="text-sm font-semibold text-gray-900">Note operative</p>
                <p class="mt-2 text-sm text-gray-600">Il fine-tuning non sostituisce gli asset reali: migliora il modo in cui il modello scrive. Per video, voce, prodotti, luoghi e realismo visivo continua a contare soprattutto il grounding del Brand Center.</p>
                @if($fineTuningRun?->last_error)
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-700">
                        Ultimo errore: {{ $fineTuningRun->last_error }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"> 
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">PWA installabile</p>
                <span class="text-xs font-semibold text-indigo-700">Web App</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Installazione rapida su desktop/mobile quando supportata.</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Push status</p>
                <span class="text-xs font-semibold text-cyan-700">Browser</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Attivazione subscription e invio notifica test.</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Contesto sicuro</p>
                <span class="text-xs font-semibold text-emerald-700">HTTPS/Local</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Push disponibile solo in secure context.</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Meta</p>
                <span class="text-xs font-semibold {{ $activeSocialAccounts->isNotEmpty() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $activeSocialAccounts->count() }} attivi</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Connessioni social pronte per programmazione e pubblicazione automatica.</p>
        </article>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div id="meta-connections" class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Connessioni Meta</h2>
                        <p class="mt-1 text-sm text-gray-600">Collega gli account del cliente per Facebook Page e Instagram Business.</p>
                    </div>
                    @if($metaReady)
                        <a href="{{ route('settings.social.meta.redirect') }}" class="ui-btn-primary justify-center">
                            Collega con Meta
                        </a>
                    @endif
                </div>

                @if(!$metaReady)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                        Configura `META_APP_ID`, `META_APP_SECRET` e `META_REDIRECT_URI` per attivare l OAuth Meta.
                    </div>
                @endif

                @if($metaReady && !empty($metaScopes))
                    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Scope richiesti</p>
                        <p class="mt-2 text-sm text-gray-700">{{ implode(', ', $metaScopes) }}</p>
                    </div>
                @endif

                <div class="mt-4 space-y-3">
                    @forelse($socialAccounts as $account)
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">
                                        {{ $account->account_name ?: ($account->username ?: 'Account social') }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ strtoupper((string) $account->platform) }}
                                        @if($account->username)
                                            - {{ $account->username }}
                                        @endif
                                        @if($account->account_id)
                                            - ID {{ $account->account_id }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $account->status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-700' }}">
                                        {{ $account->status === 'active' ? 'Attivo' : 'Disconnesso' }}
                                    </span>
                                    @if($account->is_primary)
                                        <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700">
                                            Primario
                                        </span>
                                    @endif
                                    <form method="POST" action="{{ route('settings.social.accounts.disconnect', $account) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                            Disconnetti
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                Ultima sync: {{ optional($account->last_synced_at)->format('d/m/Y H:i') ?: 'mai' }}
                                @if($account->last_error)
                                    - {{ $account->last_error }}
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">
                            Nessun account Meta collegato.
                        </div>
                    @endforelse
                </div>
            </div>

            @if($canvaFeatureEnabled)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Integrazione Canva</h2>
                            <p class="mt-1 text-sm text-gray-600">Social AI resta il brain. Canva serve per layout, template, rifinitura editabile ed export finale.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if($canvaConnected)
                                <form method="POST" action="{{ route('settings.integrations.canva.templates.refresh') }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                        Aggiorna template
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('settings.integrations.canva.disconnect') }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                                        Disconnetti Canva
                                    </button>
                                </form>
                            @elseif($canvaConfigured)
                                <a href="{{ route('settings.integrations.canva.redirect') }}" class="ui-btn-primary justify-center">
                                    Collega Canva
                                </a>
                            @endif
                        </div>
                    </div>

                    @if(!$canvaConfigured)
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                            Configura `CANVA_CLIENT_ID`, `CANVA_CLIENT_SECRET` e `CANVA_REDIRECT_URI` per attivare l OAuth Canva.
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 md:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connected</p>
                            <p class="mt-2 text-sm font-semibold {{ $canvaConnected ? 'text-emerald-700' : 'text-gray-700' }}">{{ $canvaConnected ? 'Si' : 'No' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Autofill</p>
                            <p class="mt-2 text-sm font-semibold {{ $canvaAutofillAvailable ? 'text-emerald-700' : 'text-amber-700' }}">{{ $canvaAutofillAvailable ? 'Disponibile' : 'Manual finishing' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Template APIs</p>
                            <p class="mt-2 text-sm font-semibold {{ $canvaTemplatesAvailable ? 'text-emerald-700' : 'text-amber-700' }}">{{ $canvaTemplatesAvailable ? 'Disponibili' : 'Limitati' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Export</p>
                            <p class="mt-2 text-sm font-semibold {{ $canvaExportAvailable ? 'text-emerald-700' : 'text-amber-700' }}">{{ $canvaExportAvailable ? 'Disponibile' : 'Non pronto' }}</p>
                        </div>
                    </div>

                    @if($canvaConnected)
                        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                            <p class="text-sm font-semibold text-gray-900">{{ $canvaConnection?->canva_display_name ?: 'Utente Canva connesso' }}</p>
                            <p class="mt-1 text-xs text-gray-600">
                                User ID {{ $canvaConnection?->canva_user_id ?: 'n/d' }} · Team {{ $canvaConnection?->canva_team_id ?: 'n/d' }}
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                Scope: {{ collect((array) ($canvaSummary['scopes'] ?? []))->implode(', ') ?: 'n/d' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                Capability: {{ collect((array) ($canvaSummary['capabilities'] ?? []))->implode(', ') ?: 'n/d' }}
                            </p>
                        </div>

                        <div class="mt-4 grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
                            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">Template preview</p>
                                        <p class="mt-1 text-xs text-gray-500">Ultimo refresh: {{ $canvaSummary['catalog_refreshed_at'] ?: 'mai' }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700">
                                        {{ $canvaCatalogPreview->count() }} template
                                    </span>
                                </div>

                                <div class="mt-3 space-y-2">
                                    @forelse($canvaCatalogPreview->take(6) as $template)
                                        <div class="rounded-xl border border-gray-200 px-3 py-3">
                                            <p class="text-sm font-semibold text-gray-900">{{ $template['title'] ?? 'Template' }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ $template['id'] ?? '' }}</p>
                                            @if(!empty($template['create_url']))
                                                <a href="{{ $template['create_url'] }}" target="_blank" rel="noreferrer" class="mt-2 inline-flex text-xs font-semibold text-cyan-700 hover:text-cyan-800">
                                                    Apri template in Canva
                                                </a>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-sm text-gray-600">
                                            Nessun template in preview. Esegui un refresh dopo aver collegato un account con Brand Templates.
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                                <p class="text-sm font-semibold text-gray-900">Workflow mapping</p>
                                <p class="mt-1 text-xs text-gray-500">Per il vertical slice il flusso attivo e `instagram_post`, ma i mapping sono pronti anche per gli altri formati.</p>

                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    @foreach($canvaWorkflowOptions as $workflowKey => $workflow)
                                        @php
                                            $mapping = $canvaTemplateMappings->get($workflowKey);
                                        @endphp
                                        <form method="POST" action="{{ route('settings.integrations.canva.templates.map') }}" class="rounded-xl border border-gray-200 px-4 py-4">
                                            @csrf
                                            <input type="hidden" name="channel_format" value="{{ $workflowKey }}">
                                            <p class="text-sm font-semibold text-gray-900">{{ $workflow['label'] ?? $workflowKey }}</p>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ $mapping?->status === 'active' ? 'Template associato' : 'Nessun template attivo' }}
                                            </p>
                                            <select name="canva_template_id" class="mt-3 w-full rounded-xl border-gray-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                                <option value="">Manual Canva finishing</option>
                                                @foreach($canvaCatalogPreview as $template)
                                                    <option value="{{ $template['id'] }}" @selected((string) $template['id'] === (string) ($mapping?->canva_template_id ?? ''))>
                                                        {{ $template['title'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="mt-3 flex items-center justify-between gap-3">
                                                <span class="text-xs text-gray-500">{{ $mapping?->canva_template_name ?: 'Nessun template' }}</span>
                                                <button type="submit" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                                    Salva
                                                </button>
                                            </div>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Installazione PWA</h2>
                        <p class="mt-1 text-sm text-gray-600">Installa l'app sul dispositivo per un'esperienza full-screen.</p>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p class="text-sm text-gray-700">
                        Se il pulsante non compare, usa il menu del browser e seleziona "Installa app".
                    </p>
                    <button id="pwa-install-btn" type="button" class="ui-btn-primary mt-4 hidden justify-center">
                        Installa app
                    </button>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Notifiche push</h2>
                        <p class="mt-1 text-sm text-gray-600">Attiva la subscription del browser e verifica con una notifica di test.</p>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex flex-wrap gap-2">
                        <button id="push-enable-btn" type="button" class="ui-btn-primary justify-center">
                            Attiva notifiche
                        </button>
                        <button id="push-test-btn" type="button" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Invia test
                        </button>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-amber-500" id="push-status-dot"></span>
                        <p id="push-status" class="text-sm font-medium text-gray-700">Stato: non attive</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Roadmap operativa</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li>Supporto publish multi-account e scelta account primario da UI</li>
                    <li>Retry manuale per pubblicazioni fallite</li>
                    <li>Connessioni TikTok e LinkedIn</li>
                </ul>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Stato ambiente</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">PWA</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">Installazione browser-based</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Push</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">Richiede permesso notifiche</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Security</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">HTTPS o localhost</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Meta OAuth</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $metaReady ? 'Configurato' : 'Da configurare' }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Flussi collegati</h2>
                <p class="mt-1 text-sm text-gray-600">Dopo il setup, questi sono i tre flussi operativi principali e il centro brand.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('dashboard') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Home</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla stato del workspace, approvazioni e priorita.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Pianifica</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla calendario, uscite e copertura editoriale.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci post, stati e output AI.</p>
                    </a>
                    <a href="{{ route('posts.create') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Crea</p>
                        <p class="mt-1 text-xs text-gray-600">Apri contenuti singoli, reel o nuovi piani partendo da un solo punto.</p>
                    </a>
                </div>
            </div>

            <div class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Sessione account</h2>
                <p class="mt-1 text-sm text-gray-600">Chiudi la sessione corrente su questo dispositivo.</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                        Esci dall'account
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
    let deferredPrompt = null;
    const installBtn = document.getElementById("pwa-install-btn");
    const pushEnableBtn = document.getElementById("push-enable-btn");
    const pushTestBtn = document.getElementById("push-test-btn");
    const pushStatus = document.getElementById("push-status");
    const pushStatusDot = document.getElementById("push-status-dot");

    function setStatus(text) {
        if (pushStatus) pushStatus.textContent = "Stato: " + text;
        if (!pushStatusDot) return;
        pushStatusDot.classList.remove("bg-amber-500", "bg-emerald-500", "bg-red-500");
        if (String(text).startsWith("errore")) pushStatusDot.classList.add("bg-red-500");
        else if (String(text).includes("attive") || String(text).includes("pronte") || String(text).includes("test inviato")) pushStatusDot.classList.add("bg-emerald-500");
        else pushStatusDot.classList.add("bg-amber-500");
    }

    function isSecurePushContext() {
        return window.isSecureContext || window.location.hostname === "localhost" || window.location.hostname === "127.0.0.1";
    }

    function hasPushSupport() {
        return "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
    }

    function getCsrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    function urlBase64ToUint8Array(base64String) {
        const clean = String(base64String || "").trim();
        if (!clean) {
            throw new Error("VAPID public key vuota.");
        }
        const padding = "=".repeat((4 - (clean.length % 4)) % 4);
        const base64 = (clean + padding).replace(/-/g, "+").replace(/_/g, "/");
        const raw = atob(base64);
        const output = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
        return output;
    }

    function withTimeout(promise, ms, message) {
        let timeoutId = null;
        const timeout = new Promise((_, reject) => {
            timeoutId = setTimeout(() => reject(new Error(message)), ms);
        });
        return Promise.race([promise, timeout]).finally(() => {
            if (timeoutId) clearTimeout(timeoutId);
        });
    }

    async function fetchJson(url, options = {}) {
        const headers = Object.assign({
            "Accept": "application/json",
            "X-CSRF-TOKEN": getCsrf(),
        }, options.headers || {});

        const res = await fetch(url, Object.assign({ credentials: "same-origin" }, options, { headers }));
        const text = await res.text();
        let json = {};
        try {
            json = text ? JSON.parse(text) : {};
        } catch (_) {}

        if (!res.ok) {
            const msg = json.message || json.error || text || ("HTTP " + res.status);
            throw new Error(msg);
        }
        return json;
    }

    async function ensureServiceWorkerReady() {
        if (!("serviceWorker" in navigator)) {
            throw new Error("Service Worker non supportato.");
        }

        await withTimeout(
            navigator.serviceWorker.register("/sw.js", { scope: "/" }),
            10000,
            "Timeout registrazione Service Worker."
        );

        return await withTimeout(navigator.serviceWorker.ready, 12000, "Timeout attivazione Service Worker.");
    }

    async function saveSubscription(subscription) {
        await fetchJson("/push/subscribe", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(subscription),
        });
    }

    async function subscribePush() {
        if (!isSecurePushContext()) {
            throw new Error("Push richiede HTTPS (o localhost).");
        }
        if (!hasPushSupport()) {
            throw new Error("Push non supportato da questo browser.");
        }

        const permission = await Notification.requestPermission();
        if (permission !== "granted") {
            throw new Error("Permesso notifiche negato.");
        }

        const reg = await ensureServiceWorkerReady();
        setStatus("service worker pronto");

        const keyJson = await fetchJson("/push/public-key");
        const vapidPublicKey = keyJson.publicKey;
        if (!vapidPublicKey) {
            throw new Error("VAPID_PUBLIC_KEY non configurata.");
        }

        let subscription = await reg.pushManager.getSubscription();
        if (!subscription) {
            try {
                setStatus("creazione subscription...");
                subscription = await withTimeout(
                    reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                    }),
                    15000,
                    "Timeout durante subscribe push."
                );
            } catch (error) {
                const name = String(error?.name || "");
                if (name.includes("InvalidStateError") || name.includes("AbortError")) {
                    const existing = await reg.pushManager.getSubscription();
                    if (existing) await existing.unsubscribe();
                    subscription = await withTimeout(
                        reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                        }),
                        15000,
                        "Timeout durante subscribe push (retry)."
                    );
                } else {
                    throw error;
                }
            }
        }

        setStatus("salvataggio subscription...");
        await saveSubscription(subscription.toJSON ? subscription.toJSON() : subscription);
        setStatus("attive");
    }

    async function sendTest() {
        const json = await fetchJson("/push/test", { method: "POST" });
        setStatus("test inviato (sent: " + (json.sent ?? 0) + ", failed: " + (json.failed ?? 0) + ")");
    }

    window.addEventListener("beforeinstallprompt", (event) => {
        event.preventDefault();
        deferredPrompt = event;
        if (installBtn) installBtn.classList.remove("hidden");
    });

    if (installBtn) {
        installBtn.addEventListener("click", async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            installBtn.classList.add("hidden");
        });
    }

    if (!isSecurePushContext()) {
        setStatus("serve HTTPS (tranne localhost)");
    } else if (!hasPushSupport()) {
        setStatus("browser non supportato");
    } else {
        setStatus("pronte");
        ensureServiceWorkerReady().then(async (reg) => {
            const existing = await reg.pushManager.getSubscription();
            if (existing) {
                try {
                    await saveSubscription(existing.toJSON ? existing.toJSON() : existing);
                    setStatus("gia attive");
                } catch (_) {}
            }
        }).catch(() => {});
    }

    if (pushEnableBtn) {
        pushEnableBtn.addEventListener("click", async () => {
            try {
                setStatus("attivazione...");
                await subscribePush();
            } catch (error) {
                console.error(error);
                setStatus("errore (" + (error?.message || error) + ")");
            }
        });
    }

    if (pushTestBtn) {
        pushTestBtn.addEventListener("click", async () => {
            try {
                setStatus("invio test...");
                await sendTest();
            } catch (error) {
                console.error(error);
                setStatus("errore (" + (error?.message || error) + ")");
            }
        });
    }
</script>
@endsection




