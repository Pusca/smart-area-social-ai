@extends('layouts.app')

@section('content')
@php
    $statusUrl = route('wizard.progress.plan', $plan);
    $generatingUrl = route('plans.generating', ['contentPlan' => $plan->id, 'context' => $context]);
    $returnUrl = $returnUrl ?? route('posts.index');
    $returnLabel = $returnLabel ?? 'Vai ai contenuti';
    $counts = (array) ($progress['counts'] ?? []);
    $total = (int) ($counts['total'] ?? 0);
    $done = (int) ($counts['done'] ?? 0);
    $queued = (int) (($counts['queued'] ?? 0) + ($counts['pending'] ?? 0));
    $errors = (int) ($counts['error'] ?? 0);
    $doneRate = $total > 0 ? (int) round(($done / $total) * 100) : 0;
    $etaSeconds = max(30, ($queued * 55));
    $activeItems = (array) ($progress['active_items'] ?? []);
    $completed = (bool) ($progress['completed'] ?? false);
    $contextMeta = match ($context) {
        'quickstart' => [
            'heading' => 'Sto completando la demo iniziale',
            'description' => 'I contenuti demo vengono costruiti uno alla volta in background.',
        ],
        'single' => [
            'heading' => 'Genero il contenuto',
            'description' => 'Questo contenuto viene preparato in background e resta monitorabile finche non e pronto.',
        ],
        default => [
            'heading' => 'Genero il piano editoriale',
            'description' => 'I contenuti vengono costruiti uno alla volta in background.',
        ],
    };
@endphp

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
    <div id="gen-card" class="overflow-hidden rounded-[32px] border border-app bg-white shadow-[0_24px_80px_rgba(15,23,42,0.10)]">
        <div class="h-1.5 bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400"></div>

        <div class="space-y-6 p-6 sm:p-8">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <div class="relative mt-1 h-12 w-12 shrink-0">
                        <span class="absolute inset-0 rounded-full border-2 border-brand/15"></span>
                        <span id="gen-spinner" class="absolute inset-0 animate-spin rounded-full border-2 border-transparent border-t-cyan-400 border-r-cyan-400"></span>
                        <span class="absolute inset-[9px] rounded-full border border-brand/20"></span>
                        <span class="absolute inset-[16px] rounded-full bg-cyan-300/80 blur-sm"></span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700">AI al lavoro</p>
                        <h1 class="mt-1 text-xl font-semibold text-gray-950 sm:text-2xl">{{ $contextMeta['heading'] }}</h1>
                        <p class="mt-1 text-sm text-gray-500">{{ $contextMeta['description'] }}</p>
                    </div>
                </div>
                <button id="gen-minimize-btn" type="button" title="Minimizza"
                    class="shrink-0 mt-1 inline-flex items-center gap-1.5 rounded-xl border border-app bg-app/50 px-3 py-2 text-xs font-semibold text-gray-600 transition hover:bg-white hover:text-gray-900">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                    <span class="hidden sm:inline">Minimizza</span>
                </button>
            </div>

            <div class="rounded-[24px] border border-app bg-app/40 p-5 space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p id="gen-stage" class="text-sm font-semibold text-gray-950">
                            {{ $queued > 0 ? 'Generazione progressiva dei contenuti' : ($errors > 0 ? 'Serve una verifica finale' : 'Piano pronto') }}
                        </p>
                        <p id="gen-status" class="mt-1 text-sm text-gray-500">
                            {{ $queued > 0 ? 'Aggiorno copy, prompt e visual senza interrompere il tuo flusso.' : 'Ho finito.' }}
                        </p>
                    </div>
                    <div class="text-left sm:text-right shrink-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Tempo stimato</p>
                        <p id="gen-eta" class="mt-1 text-lg font-semibold text-cyan-700">
                            {{ $queued > 0 ? $etaSeconds . ' sec' : 'Pronto' }}
                        </p>
                    </div>
                </div>

                <div class="h-2 overflow-hidden rounded-full bg-white">
                    <div id="gen-bar" class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 transition-[width] duration-500"
                         style="width: {{ max(6, $doneRate) }}%"></div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-3 text-center">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500">Completati</p>
                        <p id="gen-done" class="mt-1 text-2xl font-semibold text-gray-950">{{ $done }}</p>
                        <p class="text-xs text-gray-400">di {{ $total }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-3 text-center">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500">In coda</p>
                        <p id="gen-queued" class="mt-1 text-2xl font-semibold text-gray-950">{{ $queued }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-3 text-center">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500">Da verificare</p>
                        <p id="gen-errors" class="mt-1 text-2xl font-semibold {{ $errors > 0 ? 'text-red-700' : 'text-gray-950' }}">{{ $errors }}</p>
                    </div>
                </div>
            </div>

            <div>
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.20em] text-gray-500">Contenuti da completare</p>
                <div id="gen-items-list" class="space-y-2 max-h-[40vh] overflow-y-auto pr-1">
                    @forelse($activeItems as $item)
                    <div class="flex items-center gap-3 rounded-2xl border border-app bg-white px-4 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ ($item['ai_status'] ?? '') === 'pending' ? 'bg-cyan-100' : 'bg-amber-100' }}">
                            @if(($item['ai_status'] ?? '') === 'pending')
                            <svg class="h-3.5 w-3.5 text-cyan-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
                            </svg>
                            @else
                            <svg class="h-3.5 w-3.5 text-amber-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5"/>
                            </svg>
                            @endif
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ $item['title'] ?? 'Contenuto' }}</span>
                        <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ strtoupper($item['platform'] ?? '') }} · {{ strtoupper($item['format'] ?? '') }}</span>
                    </div>
                    @empty
                    <div id="gen-items-empty" class="flex items-center gap-3 rounded-2xl border border-dashed border-app px-4 py-4 text-sm text-gray-400">
                        <svg class="h-4 w-4 shrink-0 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        Nessun contenuto in attesa.
                    </div>
                    @endforelse
                </div>
            </div>

            <div id="gen-footer" class="flex items-center justify-between gap-3">
                <p class="text-sm text-gray-500">Puoi minimizzare e continuare a navigare.</p>
                <a id="gen-posts-link" href="{{ $returnUrl }}"
                    class="hidden inline-flex items-center gap-2 rounded-2xl bg-gray-950 px-5 py-3 text-sm font-semibold text-white shadow transition hover:bg-gray-800">
                    {{ $returnLabel }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const STATUS_URL = @json($statusUrl);
    const RETURN_URL = @json($returnUrl);
    const GENERATING_URL = @json($generatingUrl);
    const PLAN_ID = @json($plan->id);
    const LS_KEY = 'socialai:plan-gen';
    const initialActiveItems = @json($activeItems);

    const barEl = document.getElementById('gen-bar');
    const stageEl = document.getElementById('gen-stage');
    const statusEl = document.getElementById('gen-status');
    const etaEl = document.getElementById('gen-eta');
    const doneEl = document.getElementById('gen-done');
    const queuedEl = document.getElementById('gen-queued');
    const errorsEl = document.getElementById('gen-errors');
    const itemsList = document.getElementById('gen-items-list');
    const spinnerEl = document.getElementById('gen-spinner');
    const minimizeBtn = document.getElementById('gen-minimize-btn');
    const postsLink = document.getElementById('gen-posts-link');

    let pollTimer = null;
    let countdownTimer = null;
    let redirecting = false;
    let etaRemaining = {{ $etaSeconds }};
    let isCompleted = @json($completed);

    const tickCountdown = () => {
        if (isCompleted || etaRemaining <= 0) return;
        etaRemaining = Math.max(0, etaRemaining - 1);
        updateEtaDisplay(etaRemaining);
    };

    const updateEtaDisplay = (seconds) => {
        if (seconds <= 0) {
            etaEl.textContent = 'Quasi pronto';
            return;
        }

        if (seconds < 60) {
            etaEl.textContent = `${seconds} sec`;
            return;
        }

        const minutes = Math.floor(seconds / 60);
        const rest = seconds % 60;
        etaEl.textContent = rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
    };

    const esc = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const renderActiveItems = (items) => {
        if (!itemsList) return;

        if (!Array.isArray(items) || items.length === 0) {
            itemsList.innerHTML = `
                <div id="gen-items-empty" class="flex items-center gap-3 rounded-2xl border border-dashed border-app px-4 py-4 text-sm text-gray-400">
                    <svg class="h-4 w-4 shrink-0 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
                    Nessun contenuto in attesa.
                </div>
            `;
            return;
        }

        itemsList.innerHTML = items.map((item) => {
            const platform = String(item?.platform || '').toUpperCase();
            const format = String(item?.format || '').toUpperCase();
            const isPending = String(item?.ai_status || '') === 'pending';
            const iconWrap = isPending ? 'bg-cyan-100' : 'bg-amber-100';
            const icon = isPending
                ? '<svg class="h-3.5 w-3.5 text-cyan-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>'
                : '<svg class="h-3.5 w-3.5 text-amber-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5"/></svg>';

            return `
                <div class="flex items-center gap-3 rounded-2xl border border-app bg-white px-4 py-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${iconWrap}">
                        ${icon}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">${esc(item?.title || 'Contenuto')}</span>
                    <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-gray-400">${esc(platform)} · ${esc(format)}</span>
                </div>
            `;
        }).join('');
    };

    const saveState = (done, total, completed) => {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify({
                planId: PLAN_ID,
                genUrl: GENERATING_URL,
                done,
                total,
                completed,
            }));
        } catch (_) {
        }
    };

    const clearState = () => {
        try {
            localStorage.removeItem(LS_KEY);
        } catch (_) {
        }
    };

    const markDone = () => {
        isCompleted = true;
        clearInterval(pollTimer);
        clearInterval(countdownTimer);
        if (spinnerEl) spinnerEl.classList.remove('animate-spin');
        etaEl.textContent = 'Pronto';
        stageEl.textContent = 'Tutti i contenuti sono pronti';
        statusEl.textContent = 'Redirect automatico in 2 secondi...';
        if (postsLink) postsLink.classList.remove('hidden');
        clearState();

        if (!redirecting) {
            redirecting = true;
            setTimeout(() => { window.location.assign(RETURN_URL); }, 2000);
        }
    };

    const poll = async () => {
        try {
            const res = await fetch(STATUS_URL, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });

            if (!res.ok) return;

            const payload = await res.json();
            const counts = payload.counts || {};
            const total = Number(counts.total || 0);
            const done = Number(counts.done || 0);
            const queued = Number(counts.queued || 0) + Number(counts.pending || 0);
            const errors = Number(counts.error || 0);
            const doneRate = total > 0 ? Math.round((done / total) * 100) : 0;

            const serverEta = queued > 0 ? Math.max(5, queued * 55) : 0;
            if (serverEta > 0 && Math.abs(serverEta - etaRemaining) > 30) {
                etaRemaining = serverEta;
            }

            barEl.style.width = `${Math.max(6, doneRate)}%`;
            doneEl.textContent = String(done);
            queuedEl.textContent = String(queued);
            errorsEl.textContent = String(errors);
            errorsEl.classList.toggle('text-red-700', errors > 0);
            errorsEl.classList.toggle('text-gray-950', errors === 0);

            stageEl.textContent = queued > 0
                ? 'Generazione progressiva dei contenuti'
                : (errors > 0 ? 'Serve una verifica finale' : 'Piano pronto');
            statusEl.textContent = queued > 0
                ? 'Aggiorno copy, prompt e visual senza interrompere il tuo flusso.'
                : (errors > 0 ? 'Ho finito, ma almeno un contenuto va controllato.' : 'Ho finito.');

            renderActiveItems(Array.isArray(payload.active_items) ? payload.active_items : []);
            saveState(done, total, !!payload.completed);

            if (payload.completed) markDone();
        } catch (_) {
            statusEl.textContent = 'Connessione lenta, continuo a controllare.';
        }
    };

    minimizeBtn?.addEventListener('click', () => {
        const done = Number(doneEl.textContent || 0);
        const total = {{ $total }};
        saveState(done, total, isCompleted);
        window.location.assign(document.referrer || '{{ route("dashboard") }}');
    });

    if (isCompleted) {
        markDone();
    } else {
        renderActiveItems(initialActiveItems);
        saveState({{ $done }}, {{ $total }}, false);
        poll();
        pollTimer = setInterval(poll, 3000);
        countdownTimer = setInterval(tickCountdown, 1000);
    }
})();
</script>
@endsection
