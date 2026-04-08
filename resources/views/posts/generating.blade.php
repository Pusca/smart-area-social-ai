@extends('layouts.app')

@section('content')
@php
    $statusUrl = route('posts.generation.status', $contentItem);
    $returnUrl = route('posts.edit', $contentItem);
    $formatLabel = strtolower((string) ($contentItem->format ?? 'post'));
@endphp

<div class="mx-auto flex min-h-[72vh] w-full max-w-4xl items-center justify-center px-4 py-8 sm:px-6">
    <div class="w-full max-w-2xl overflow-hidden rounded-[32px] border border-app bg-white shadow-[0_24px_80px_rgba(15,23,42,0.10)]">
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
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700">Macchina al lavoro</p>
                    <h1 class="mt-2 text-2xl font-semibold text-gray-950 sm:text-3xl">Sto preparando il contenuto</h1>
                    <p class="mt-2 text-sm leading-6 text-gray-600 sm:text-base">
                        Raccolgo strategia, immagini reali e prompt per costruire un {{ $formatLabel }} credibile, pronto da rivedere e usare.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 rounded-[28px] border border-app bg-app/40 p-5 sm:grid-cols-[1.2fr_0.8fr]">
                <div class="space-y-4">
                    <div>
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <p id="single-generation-stage" class="font-semibold text-gray-950">Analisi del brief e del brand</p>
                            <p id="single-generation-eta" class="font-semibold text-cyan-700">Circa {{ max(12, (int) $estimateSeconds) }} sec</p>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                            <div id="single-generation-progress" class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 transition-[width] duration-300" style="width: 8%"></div>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Stato live</p>
                        <p id="single-generation-status-label" class="mt-2 text-lg font-semibold text-gray-950">In lavorazione</p>
                        <p id="single-generation-status-description" class="mt-1 text-sm text-gray-600">La generazione è partita. Ti porto dentro appena è pronta.</p>
                    </div>
                    <div id="single-generation-push-card" class="hidden rounded-2xl border border-cyan-100 bg-cyan-50/80 p-4">
                        <p class="text-sm font-semibold text-cyan-900">Vuoi chiudere la pagina?</p>
                        <p id="single-generation-push-copy" class="mt-1 text-sm text-cyan-800">
                            Attiva le notifiche push e ti avviso io quando il contenuto è pronto.
                        </p>
                        <button id="single-generation-push-enable" type="button" class="mt-3 inline-flex items-center justify-center rounded-2xl bg-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">
                            Attiva notifiche push
                        </button>
                    </div>
                </div>

                <div class="rounded-[26px] border border-white/80 bg-white/90 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Cosa sta facendo ora</p>
                    <div class="mt-3 space-y-3">
                        <div class="rounded-2xl border border-app bg-app/50 px-4 py-3">
                            <p class="text-sm font-semibold text-gray-950">Contenuto #{{ $contentItem->id }}</p>
                            <p class="mt-1 text-sm text-gray-600">Formato {{ strtoupper($formatLabel) }}</p>
                        </div>
                        <div class="rounded-2xl border border-app bg-app/50 px-4 py-3">
                            <p class="text-sm font-semibold text-gray-950">Obiettivo</p>
                            <p id="single-generation-detail" class="mt-1 text-sm text-gray-600">Sto finalizzando il contenuto senza bloccare la pagina.</p>
                        </div>
                    </div>
                    <a href="{{ $returnUrl }}" class="mt-4 inline-flex w-full items-center justify-center rounded-2xl border border-app bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-brand hover:text-brand">
                        Apri il contenuto adesso
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const statusUrl = @json($statusUrl);
        const returnUrl = @json($returnUrl);
        const estimateSeconds = Math.max(12, Number(@json((int) $estimateSeconds)));
        const stages = @json(array_values($stages));

        const progressEl = document.getElementById('single-generation-progress');
        const stageEl = document.getElementById('single-generation-stage');
        const etaEl = document.getElementById('single-generation-eta');
        const detailEl = document.getElementById('single-generation-detail');
        const statusLabelEl = document.getElementById('single-generation-status-label');
        const statusDescriptionEl = document.getElementById('single-generation-status-description');
        const pushCardEl = document.getElementById('single-generation-push-card');
        const pushCopyEl = document.getElementById('single-generation-push-copy');
        const pushEnableBtn = document.getElementById('single-generation-push-enable');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const startedAt = Date.now();
        let pollTimer = null;
        let visualTimer = null;
        let isRedirecting = false;

        const formatTime = (seconds) => {
            const safe = Math.max(0, Math.round(Number(seconds || 0)));
            if (safe < 60) {
                return `${safe} sec`;
            }

            const minutes = Math.floor(safe / 60);
            const rest = safe % 60;
            return rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
        };

        const supportsPush = () => {
            return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        };

        const urlBase64ToUint8Array = (base64String) => {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);

            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }

            return outputArray;
        };

        const fetchJson = async (url, options = {}) => {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                ...options,
            });

            const json = await response.json().catch(() => ({}));
            if (!response.ok || json?.error) {
                throw new Error(json?.message || `HTTP ${response.status}`);
            }

            return json;
        };

        const ensurePushSubscription = async () => {
            const permission = Notification.permission === 'granted'
                ? 'granted'
                : await Notification.requestPermission();

            if (permission !== 'granted') {
                throw new Error('Permesso notifiche non concesso.');
            }

            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const ready = await navigator.serviceWorker.ready;
            const keyJson = await fetchJson('/push/public-key');
            const vapidPublicKey = keyJson.publicKey;
            if (!vapidPublicKey) {
                throw new Error('VAPID public key non disponibile.');
            }

            let subscription = await ready.pushManager.getSubscription();
            if (!subscription) {
                subscription = await ready.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                });
            }

            await fetchJson('/push/subscribe', {
                method: 'POST',
                body: JSON.stringify(subscription.toJSON ? subscription.toJSON() : subscription),
            });
        };

        const renderPushCard = () => {
            if (!pushCardEl || !pushCopyEl || !pushEnableBtn || !supportsPush()) {
                return;
            }

            pushCardEl.classList.remove('hidden');
            if (Notification.permission === 'granted') {
                pushCopyEl.textContent = 'Notifiche attive: puoi lasciare la pagina e ti avviso io quando la generazione finisce.';
                pushEnableBtn.classList.add('hidden');
                ensurePushSubscription().catch(() => {});
            }
        };

        const updateVisualProgress = () => {
            const elapsed = (Date.now() - startedAt) / 1000;
            const progress = Math.min(94, Math.max(8, (elapsed / estimateSeconds) * 100));
            const stageIndex = Math.min(stages.length - 1, Math.floor((progress / 100) * stages.length));
            const remaining = Math.max(0, estimateSeconds - elapsed);

            progressEl.style.width = `${progress}%`;
            stageEl.textContent = stages[stageIndex] || stages[stages.length - 1] || 'Sto finalizzando';
            etaEl.textContent = remaining > 0 ? `Circa ${formatTime(remaining)}` : 'Quasi pronto';
            detailEl.textContent = remaining > 0
                ? `Tempo stimato totale: ${formatTime(estimateSeconds)}`
                : 'Sto salvando l output finale';
        };

        const redirectToContent = () => {
            if (isRedirecting) {
                return;
            }

            isRedirecting = true;
            window.clearInterval(pollTimer);
            window.clearInterval(visualTimer);
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
                const status = payload.status || {};

                if (status.label) {
                    statusLabelEl.textContent = status.label;
                }
                if (status.description) {
                    statusDescriptionEl.textContent = status.description;
                }

                if (payload.completed || payload.error) {
                    progressEl.style.width = '100%';
                    etaEl.textContent = payload.error ? 'Serve una verifica' : 'Pronto';
                    detailEl.textContent = payload.error
                        ? 'Ti riporto nel contenuto per controllare il dettaglio tecnico.'
                        : 'Contenuto pronto. Apro la scheda completa.';
                    window.setTimeout(redirectToContent, 600);
                }
            } catch (error) {
                statusDescriptionEl.textContent = 'Connessione lenta, continuo a controllare in automatico.';
            }
        };

        updateVisualProgress();
        renderPushCard();
        visualTimer = window.setInterval(updateVisualProgress, 300);
        poll();
        pollTimer = window.setInterval(poll, 2500);

        if (pushEnableBtn) {
            pushEnableBtn.addEventListener('click', async () => {
                pushEnableBtn.disabled = true;
                pushEnableBtn.textContent = 'Attivazione...';

                try {
                    await ensurePushSubscription();
                    renderPushCard();
                } catch (error) {
                    pushCopyEl.textContent = error instanceof Error ? error.message : 'Impossibile attivare le notifiche push.';
                    pushEnableBtn.disabled = false;
                    pushEnableBtn.textContent = 'Riprova';
                }
            });
        }
    })();
</script>
@endsection
