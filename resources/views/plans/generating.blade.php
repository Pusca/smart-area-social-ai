@extends('layouts.app')

@section('content')
@php
    $statusUrl = route('wizard.progress.plan', $plan);
    $counts = (array) ($progress['counts'] ?? []);
    $total = (int) ($counts['total'] ?? 0);
    $done = (int) ($counts['done'] ?? 0);
    $queued = (int) (($counts['queued'] ?? 0) + ($counts['pending'] ?? 0));
    $errors = (int) ($counts['error'] ?? 0);
    $doneRate = $total > 0 ? (int) round(($done / $total) * 100) : 0;
    $etaSeconds = max(30, ($queued * 55));
    $contextLabel = $context === 'quickstart' ? 'demo iniziale' : 'piano editoriale';
@endphp

<div class="mx-auto flex min-h-[72vh] w-full max-w-5xl items-center justify-center px-4 py-8 sm:px-6">
    <div class="w-full max-w-3xl overflow-hidden rounded-[32px] border border-app bg-white shadow-[0_24px_80px_rgba(15,23,42,0.10)]">
        <div class="h-1.5 bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400"></div>
        <div class="space-y-6 p-6 sm:p-8">
            <div class="flex items-start gap-4">
                <div class="relative mt-1 h-14 w-14 shrink-0">
                    <span class="absolute inset-0 rounded-full border-2 border-brand/15"></span>
                    <span class="absolute inset-0 animate-spin rounded-full border-2 border-transparent border-t-cyan-400 border-r-cyan-400"></span>
                    <span class="absolute inset-[10px] rounded-full border border-brand/20"></span>
                    <span class="absolute inset-[18px] rounded-full bg-cyan-300/80 blur-sm"></span>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700">AI al lavoro</p>
                    <h1 class="mt-2 text-2xl font-semibold text-gray-950 sm:text-3xl">Sto completando la {{ $contextLabel }}</h1>
                    <p class="mt-2 text-sm leading-6 text-gray-600 sm:text-base">
                        La pagina resta attiva mentre la macchina costruisce i contenuti in background. Ti riporto dove serve appena ha finito.
                    </p>
                </div>
            </div>

            <div class="rounded-[28px] border border-app bg-app/40 p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p id="plan-generation-stage" class="text-sm font-semibold text-gray-950">Generazione progressiva dei contenuti</p>
                        <p id="plan-generation-status" class="mt-1 text-sm text-gray-600">Sto completando copy, prompt e visual uno alla volta.</p>
                    </div>
                    <div class="text-left sm:text-right">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Tempo stimato</p>
                        <p id="plan-generation-eta" class="mt-1 text-lg font-semibold text-cyan-700">{{ $queued > 0 ? $etaSeconds . ' sec' : 'Quasi pronto' }}</p>
                    </div>
                </div>

                <div class="mt-4 h-2 overflow-hidden rounded-full bg-white">
                    <div id="plan-generation-progress" class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 transition-[width] duration-300" style="width: {{ max(10, $doneRate) }}%"></div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Completati</p>
                        <p id="plan-generation-done" class="mt-2 text-2xl font-semibold text-gray-950">{{ $done }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">In coda</p>
                        <p id="plan-generation-queued" class="mt-2 text-2xl font-semibold text-gray-950">{{ $queued }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Da verificare</p>
                        <p id="plan-generation-errors" class="mt-2 text-2xl font-semibold {{ $errors > 0 ? 'text-red-700' : 'text-gray-950' }}">{{ $errors }}</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-600">
                    {{ $context === 'quickstart' ? 'Appena è pronta torni nel Brand Center con la demo visibile.' : 'Appena finisce torni nel piano con tutti i contenuti aggiornati.' }}
                </p>
                <a href="{{ $returnUrl }}" class="inline-flex items-center justify-center rounded-2xl border border-app bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-brand hover:text-brand">
                    Torna ora
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const statusUrl = @json($statusUrl);
        const returnUrl = @json($returnUrl);

        const progressEl = document.getElementById('plan-generation-progress');
        const stageEl = document.getElementById('plan-generation-stage');
        const statusEl = document.getElementById('plan-generation-status');
        const etaEl = document.getElementById('plan-generation-eta');
        const doneEl = document.getElementById('plan-generation-done');
        const queuedEl = document.getElementById('plan-generation-queued');
        const errorsEl = document.getElementById('plan-generation-errors');

        let timer = null;
        let redirecting = false;

        const formatTime = (seconds) => {
            const safe = Math.max(0, Math.round(Number(seconds || 0)));
            if (safe < 60) {
                return `${safe} sec`;
            }

            const minutes = Math.floor(safe / 60);
            const rest = safe % 60;
            return rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
        };

        const redirectToTarget = () => {
            if (redirecting) {
                return;
            }

            redirecting = true;
            window.clearInterval(timer);
            window.location.assign(returnUrl);
        };

        const poll = async () => {
            try {
                const response = await fetch(statusUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                const counts = payload.counts || {};
                const total = Number(counts.total || 0);
                const done = Number(counts.done || 0);
                const queued = Number(counts.queued || 0) + Number(counts.pending || 0);
                const errors = Number(counts.error || 0);
                const doneRate = total > 0 ? Math.round((done / total) * 100) : 0;
                const eta = queued > 0 ? Math.max(30, queued * 55) : 0;

                progressEl.style.width = `${Math.max(10, doneRate)}%`;
                doneEl.textContent = String(done);
                queuedEl.textContent = String(queued);
                errorsEl.textContent = String(errors);
                errorsEl.classList.toggle('text-red-700', errors > 0);
                errorsEl.classList.toggle('text-gray-950', errors === 0);

                stageEl.textContent = queued > 0
                    ? 'Sto completando i contenuti in sequenza'
                    : (errors > 0 ? 'Serve una verifica finale' : 'Piano pronto');
                statusEl.textContent = queued > 0
                    ? 'Aggiorno copy, prompt e visual senza interrompere il tuo flusso.'
                    : (errors > 0 ? 'Ho finito la lavorazione, ma almeno un contenuto va controllato.' : 'Ho finito. Ti riporto alla sezione operativa.');
                etaEl.textContent = queued > 0 ? formatTime(eta) : 'Pronto';

                if (payload.completed) {
                    window.setTimeout(redirectToTarget, 700);
                }
            } catch (error) {
                statusEl.textContent = 'Connessione lenta, continuo a controllare in automatico.';
            }
        };

        poll();
        timer = window.setInterval(poll, 2500);
    })();
</script>
@endsection
