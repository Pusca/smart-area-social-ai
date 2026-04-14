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

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-2">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connessioni social</p>
                <span class="text-xs font-semibold {{ $activeSocialAccounts->isNotEmpty() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $activeSocialAccounts->count() }} attivi</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Account collegati per programmazione e pubblicazione automatica.</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Piattaforme attive</p>
                <span class="text-xs font-semibold text-indigo-700">{{ $connectedPlatforms->count() > 0 ? $connectedPlatforms->count() : '0' }}/4</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">{{ $connectedPlatforms->count() > 0 ? 'Connesse: ' . $connectedPlatforms->implode(', ') : 'Nessuna piattaforma ancora collegata.' }}</p>
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

        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Stato ambiente</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Meta OAuth</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $metaReady ? 'Configurato' : 'Da configurare' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Account social attivi</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $activeSocialAccounts->count() > 0 ? $activeSocialAccounts->count() . ' collegati' : 'Nessuno ancora' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Brand Center</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900"><a href="{{ route('profile.brand') }}" class="text-indigo-600 hover:underline">Apri →</a></p>
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

@endsection




