@extends('layouts.app')

@section('content')
@php
    $totalItems = (int) ($itemStats['total'] ?? 0);
    $doneItems = (int) ($itemStats['done'] ?? 0);
    $queuedItems = (int) ($itemStats['queued'] ?? 0);
    $errorItems = (int) ($itemStats['error'] ?? 0);

    $doneRate = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;
    $pendingRate = $totalItems > 0 ? (int) round(($queuedItems / $totalItems) * 100) : 0;
    $estimateForItem = static function ($item): int {
        $format = strtolower((string) ($item->format ?? 'post'));

        return match ($format) {
            'reel' => 180,
            'story' => 140,
            default => 55,
        };
    };
    $estimatedTotalSeconds = $plan
        ? (int) max(30, $plan->items->sum($estimateForItem))
        : (int) max(30, $totalItems * 55);
    $averageItemSeconds = $totalItems > 0 ? (int) max(30, ceil($estimatedTotalSeconds / $totalItems)) : 55;
    $queuedEtaSeconds = (int) max(0, $queuedItems * $averageItemSeconds);
    $formatEta = static function (int $seconds): string {
        $safe = max(0, $seconds);
        if ($safe < 60) {
            return $safe . ' sec';
        }

        $minutes = (int) floor($safe / 60);
        $rest = $safe % 60;

        return $rest > 0 ? $minutes . ' min ' . $rest . ' sec' : $minutes . ' min';
    };

    $planStart = $plan?->start_date ? \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') : '-';
    $planEnd = $plan?->end_date ? \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') : '-';
    $uiAi = fn ($status) => \App\Support\UiStatus::ai((string) $status);
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Piano editoriale</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Riepilogo piano editoriale</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Controlla la situazione del piano, avvia la generazione AI e monitora l'avanzamento in tempo reale.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Step 2 di 2
                    </span>
                    @if($profile)
                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ $profile->business_name }}
                        </span>
                    @endif
                    @if($plan)
                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ $planStart }} - {{ $planEnd }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    @if($canGenerate)
                        @php
                            $wizardImageProviders = \App\Support\ImageProviderResolver::configuredWithLabels();
                            $wizardVideoProviders = \App\Support\VideoProviderResolver::configuredWithLabels();
                            $wizardDefaultImage  = array_key_first($wizardImageProviders) ?? \App\Support\ImageProviderResolver::default();
                            $wizardDefaultVideo  = array_key_first($wizardVideoProviders) ?? \App\Support\VideoProviderResolver::default();
                        @endphp
                        <form method="POST" action="{{ route('wizard.generate') }}" class="js-wizard-generation-submit space-y-3">
                            @csrf
                            @if(count($wizardImageProviders) > 1)
                            <div>
                                <label for="wiz_image_provider" class="mb-1 block text-xs font-semibold text-gray-600">Motore immagini</label>
                                <select id="wiz_image_provider" name="image_provider" class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                                    @foreach($wizardImageProviders as $key => $label)
                                        <option value="{{ $key }}" @selected($wizardDefaultImage === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @else
                            <input type="hidden" name="image_provider" value="{{ $wizardDefaultImage }}">
                            @endif
                            @if(count($wizardVideoProviders) > 1)
                            <div>
                                <label for="wiz_video_provider" class="mb-1 block text-xs font-semibold text-gray-600">Motore video (reel)</label>
                                <select id="wiz_video_provider" name="video_provider" class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                                    @foreach($wizardVideoProviders as $key => $label)
                                        <option value="{{ $key }}" @selected($wizardDefaultVideo === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @else
                            <input type="hidden" name="video_provider" value="{{ $wizardDefaultVideo }}">
                            @endif
                            <button type="submit" class="ui-btn-primary w-full justify-center">
                                Genera piano AI
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Modifica piano
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                    <a href="{{ route('settings') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri setup
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

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Contenuti piano</p>
                <span id="wizard-progress-total" class="text-xs font-semibold text-indigo-700">{{ $totalItems }}</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $totalItems }}</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Completati AI</p>
                <span id="wizard-progress-done-rate" class="text-xs font-semibold text-emerald-700">{{ $doneRate }}%</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-gray-900"><span id="wizard-progress-done">{{ $doneItems }}</span> / <span id="wizard-progress-total-copy">{{ $totalItems }}</span></p>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div id="wizard-progress-fill" class="h-full rounded-full bg-emerald-600" style="width: {{ $doneRate }}%"></div>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">In lavorazione</p>
                <span id="wizard-progress-queued-rate" class="text-xs font-semibold text-amber-700">{{ $pendingRate }}%</span>
            </div>
            <p id="wizard-progress-queued" class="mt-2 text-2xl font-semibold text-gray-900">{{ $queuedItems }}</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Da verificare AI</p>
                <span class="text-xs font-semibold {{ $errorItems > 0 ? 'text-red-700' : 'text-gray-500' }}">
                    {{ $errorItems > 0 ? 'richiede attenzione' : 'ok' }}
                </span>
            </div>
            <p id="wizard-progress-error" class="mt-2 text-2xl font-semibold {{ $errorItems > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $errorItems }}</p>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @if($canGenerate)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Parametri del piano</h2>
                            <p class="mt-1 text-sm text-gray-600">Questi valori verranno usati per creare/aggiornare il piano.</p>
                        </div>
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                            Pronto
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Goal</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">{{ $step1['goal'] ?? '-' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Tone</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">{{ $step1['tone'] ?? '-' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Post nel periodo</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">{{ $step1['posts_per_week'] ?? '-' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('wizard.generate') }}" class="js-wizard-generation-submit">
                            @csrf
                            <button type="submit" class="ui-btn-primary">
                                Genera piano
                            </button>
                        </form>
                        <a href="{{ route('wizard.start') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Modifica dati piano
                        </a>
                    </div>
                </div>
            @endif

            @if($strategy)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Strategia del piano</h2>
                            <p class="mt-1 text-sm text-gray-600">Pilastri e micro campagne generate dalla strategia.</p>
                        </div>
                        <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Apri post
                        </a>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Pilastri</h3>
                            <div class="mt-2 space-y-2">
                                @forelse(($strategy['pillars'] ?? []) as $pillar)
                                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                                        <p class="text-sm font-semibold text-gray-900">{{ $pillar['name'] ?? 'Pilastro' }}</p>
                                        <p class="mt-1 text-xs text-gray-600">Obiettivo: {{ $pillar['objective'] ?? '-' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ implode(', ', array_slice($pillar['topics'] ?? [], 0, 4)) }}</p>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessun pilastro disponibile.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Sequenze</h3>
                            <div class="mt-2 space-y-2">
                                @forelse(array_slice($strategy['campaigns'] ?? [], 0, 3) as $campaign)
                                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                                        <p class="text-sm font-semibold text-gray-900">{{ $campaign['name'] ?? 'Campagna' }}</p>
                                        <div class="mt-2 space-y-1 text-xs text-gray-600">
                                            @foreach(($campaign['steps'] ?? []) as $campaignStep)
                                                <p>Step {{ $campaignStep['step'] ?? '?' }}: {{ $campaignStep['angle'] ?? ($campaignStep['hook'] ?? '-') }}</p>
                                            @endforeach
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessuna campagna disponibile.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Output contenuti</h2>
                        <p class="mt-1 text-sm text-gray-600">Elenco contenuti legati al piano attivo.</p>
                    </div>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Gestisci contenuti
                    </a>
                </div>

                @if($plan && $totalItems > 0)
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach($plan->items as $item)
                            @php
                                $itemAiInfo = $uiAi($item->ai_status);
                            @endphp

                            <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                            {{ strtoupper((string) $item->platform) }} - {{ strtoupper((string) $item->format) }}
                                            @if($item->scheduled_at)
                                                - {{ \Illuminate\Support\Carbon::parse($item->scheduled_at)->format('d/m H:i') }}
                                            @endif
                                        </p>
                                        <p class="mt-1 truncate text-base font-semibold text-gray-900">{{ $item->title ?: 'Senza titolo' }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $itemAiInfo['badge'] }}">
                                        AI {{ $itemAiInfo['label'] }}
                                    </span>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <p class="text-xs text-gray-500">
                                        Aprilo appena vuoi per dire cosa tenere e cosa correggere.
                                    </p>
                                    <a href="{{ route('posts.edit', $item) }}#feedback-loop" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        Apri feedback
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center">
                        <p class="text-sm text-gray-600">Nessun contenuto ancora disponibile per questo piano.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Progress live</h2>
                <p class="mt-1 text-sm text-gray-600">Aggiornamento automatico ogni 5 secondi.</p>

                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Stato elaborazione</p>
                        <p id="wizard-progress-line" class="mt-1 text-sm font-semibold text-gray-900">
                            Totale {{ $totalItems }} - completati {{ $doneItems }} - in lavorazione {{ $queuedItems }} - da verificare {{ $errorItems }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Percentuale completamento</p>
                        <p id="wizard-progress-label" class="mt-1 text-sm font-semibold text-emerald-700">{{ $doneRate }}%</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Tempo stimato residuo</p>
                        <p id="wizard-progress-eta" class="mt-1 text-sm font-semibold text-cyan-700">{{ $queuedItems > 0 ? $formatEta($queuedEtaSeconds) : 'Quasi pronto' }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                            <div id="wizard-progress-activity" class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 transition-[width] duration-300" style="width: {{ $queuedItems > 0 ? max(18, $pendingRate) : 100 }}%"></div>
                        </div>
                        <p id="wizard-progress-stage" class="mt-2 text-xs text-gray-600">
                            {{ $queuedItems > 0 ? 'La macchina sta preparando i contenuti del piano.' : 'Nessuna lavorazione attiva.' }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Dettaglio piano</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Nome piano</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $plan->name ?? '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Periodo</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $planStart }} - {{ $planEnd }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Collegamenti utili</h2>
                <p class="mt-1 text-sm text-gray-600">Aree operative collegate al piano.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Setup workspace</p>
                        <p class="mt-1 text-xs text-gray-600">Rivedi brand, asset e connessioni che sostengono il piano.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Pianifica</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla distribuzione settimanale e prossime uscite.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci output AI e stati post.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

@if($plan)
@include('partials.generation-loader')
<script>
(function () {
    const line = document.getElementById('wizard-progress-line');
    const totalEl = document.getElementById('wizard-progress-total');
    const totalCopyEl = document.getElementById('wizard-progress-total-copy');
    const doneEl = document.getElementById('wizard-progress-done');
    const doneRateEl = document.getElementById('wizard-progress-done-rate');
    const queuedEl = document.getElementById('wizard-progress-queued');
    const queuedRateEl = document.getElementById('wizard-progress-queued-rate');
    const errorEl = document.getElementById('wizard-progress-error');
    const fillEl = document.getElementById('wizard-progress-fill');
    const labelEl = document.getElementById('wizard-progress-label');
    const etaEl = document.getElementById('wizard-progress-eta');
    const activityEl = document.getElementById('wizard-progress-activity');
    const stageEl = document.getElementById('wizard-progress-stage');
    const generateForms = document.querySelectorAll('form.js-wizard-generation-submit');

    if (!line) {
        return;
    }

    const url = '{{ route('wizard.progress.plan', $plan) }}';
    const estimatedTotalSeconds = {{ $estimatedTotalSeconds }};

    const formatTime = (seconds) => {
        const safe = Math.max(0, Math.round(Number(seconds || 0)));
        if (safe < 60) return `${safe} sec`;
        const minutes = Math.floor(safe / 60);
        const rest = safe % 60;
        return rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
    };

    const computeStage = (doneRate, queued) => {
        if (queued <= 0) return 'Nessuna lavorazione attiva.';
        if (doneRate < 20) return 'Sto costruendo la base strategica e i prompt di partenza.';
        if (doneRate < 55) return 'Sto generando i contenuti centrali del piano.';
        if (doneRate < 90) return 'Sto completando gli ultimi output e facendo pulizia finale.';
        return 'Sto rifinendo gli ultimi dettagli.';
    };

    const poll = async () => {
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) {
                return;
            }

            const data = await res.json();
            const counts = data.counts || {};

            const total = Number(counts.total || 0);
            const done = Number(counts.done || 0);
            const queued = Number(counts.queued || 0) + Number(counts.pending || 0);
            const error = Number(counts.error || 0);
            const doneRate = total > 0 ? Math.round((done / total) * 100) : 0;
            const queuedRate = total > 0 ? Math.round((queued / total) * 100) : 0;
            const remainingSeconds = total > 0 ? Math.ceil((queued / total) * estimatedTotalSeconds) : 0;

            line.textContent = `Totale ${total} - completati ${done} - in lavorazione ${queued} - da verificare ${error}`;

            if (totalEl) totalEl.textContent = total;
            if (totalCopyEl) totalCopyEl.textContent = total;
            if (doneEl) doneEl.textContent = done;
            if (queuedEl) queuedEl.textContent = queued;
            if (errorEl) errorEl.textContent = error;
            if (doneRateEl) doneRateEl.textContent = `${doneRate}%`;
            if (queuedRateEl) queuedRateEl.textContent = `${queuedRate}%`;
            if (fillEl) fillEl.style.width = `${doneRate}%`;
            if (labelEl) labelEl.textContent = `${doneRate}%`;
            if (etaEl) etaEl.textContent = queued > 0 ? formatTime(remainingSeconds) : 'Quasi pronto';
            if (activityEl) activityEl.style.width = `${queued > 0 ? Math.max(18, queuedRate) : 100}%`;
            if (stageEl) stageEl.textContent = computeStage(doneRate, queued);
        } catch (e) {
            // noop
        }
    };

    if (generateForms.length > 0 && window.HostupGenerationLoader) {
        generateForms.forEach((form) => {
            form.addEventListener('submit', () => {
                window.HostupGenerationLoader.show({
                    title: 'Sto generando il piano editoriale',
                    subtitle: 'Creo caption, prompt e output per tutti i contenuti previsti. Il tempo dipende dal mix tra post statici e reel.',
                    estimateSeconds: estimatedTotalSeconds,
                    stages: [
                        'Analisi strategia e contenuti del piano',
                        'Generazione progressiva dei singoli contenuti',
                        'Rifinitura finale e salvataggio output',
                    ],
                });
            });
        });
    }

    poll();
    setInterval(poll, 5000);
})();
</script>
@endif
@endsection




