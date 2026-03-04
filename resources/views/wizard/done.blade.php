@extends('layouts.app')

@section('content')
@php
    $totalItems = (int) ($itemStats['total'] ?? 0);
    $doneItems = (int) ($itemStats['done'] ?? 0);
    $queuedItems = (int) ($itemStats['queued'] ?? 0);
    $errorItems = (int) ($itemStats['error'] ?? 0);

    $doneRate = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;
    $pendingRate = $totalItems > 0 ? (int) round(($queuedItems / $totalItems) * 100) : 0;

    $planStart = $plan?->start_date ? \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') : '-';
    $planEnd = $plan?->end_date ? \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') : '-';
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Wizard Output</div>
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
                        <form method="POST" action="{{ route('wizard.generate') }}">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                Genera piano AI
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Modifica dati wizard
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria post
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
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">In coda</p>
                <span id="wizard-progress-queued-rate" class="text-xs font-semibold text-amber-700">{{ $pendingRate }}%</span>
            </div>
            <p id="wizard-progress-queued" class="mt-2 text-2xl font-semibold text-gray-900">{{ $queuedItems }}</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Errori AI</p>
                <span class="text-xs font-semibold {{ $errorItems > 0 ? 'text-red-700' : 'text-gray-500' }}">controllo</span>
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
                            <h2 class="text-lg font-semibold text-gray-900">Parametri generazione</h2>
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
                        <form method="POST" action="{{ route('wizard.generate') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                Genera piano
                            </button>
                        </form>
                        <a href="{{ route('wizard.start') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Modifica dati
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
                                $statusClass = ($item->ai_status === 'done')
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : (($item->ai_status === 'error')
                                        ? 'border-red-200 bg-red-50 text-red-700'
                                        : 'border-amber-200 bg-amber-50 text-amber-700');
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
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $statusClass }}">
                                        AI {{ $item->ai_status ?? 'n/a' }}
                                    </span>
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
                            Totale {{ $totalItems }} - completati {{ $doneItems }} - in coda {{ $queuedItems }} - errori {{ $errorItems }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Percentuale completamento</p>
                        <p id="wizard-progress-label" class="mt-1 text-sm font-semibold text-emerald-700">{{ $doneRate }}%</p>
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
                <p class="mt-1 text-sm text-gray-600">Aree operative collegate al wizard.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('profile.brand') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Profilo Brand</p>
                        <p class="mt-1 text-xs text-gray-600">Aggiorna base strategica e assets.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla distribuzione settimanale.</p>
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

    if (!line) {
        return;
    }

    const url = '{{ route('wizard.progress.plan', $plan) }}';

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

            line.textContent = `Totale ${total} - completati ${done} - in coda ${queued} - errori ${error}`;

            if (totalEl) totalEl.textContent = total;
            if (totalCopyEl) totalCopyEl.textContent = total;
            if (doneEl) doneEl.textContent = done;
            if (queuedEl) queuedEl.textContent = queued;
            if (errorEl) errorEl.textContent = error;
            if (doneRateEl) doneRateEl.textContent = `${doneRate}%`;
            if (queuedRateEl) queuedRateEl.textContent = `${queuedRate}%`;
            if (fillEl) fillEl.style.width = `${doneRate}%`;
            if (labelEl) labelEl.textContent = `${doneRate}%`;
        } catch (e) {
            // noop
        }
    };

    poll();
    setInterval(poll, 5000);
})();
</script>
@endif
@endsection
