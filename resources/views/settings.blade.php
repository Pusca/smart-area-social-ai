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
    @endphp

    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">App e connessioni</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Impostazioni operative del workspace</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Qui raccogli tutto quello che riguarda dispositivo, notifiche, Meta e collegamenti tecnici dell'app.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Setup dispositivo
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
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Torna alla panoramica
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
                    <a href="{{ route('profile.brand') }}" class="ui-btn-primary justify-center">
                        Brand e asset
                    </a>
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
                <div class="flex items-start justify-between gap-4">
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
                <h2 class="text-lg font-semibold text-gray-900">Aree collegate</h2>
                <p class="mt-1 text-sm text-gray-600">I punti del workspace che di solito seguono la fase di configurazione.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('dashboard') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Panoramica</p>
                        <p class="mt-1 text-xs text-gray-600">Panoramica generale dell'app.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Pianifica</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla calendario, uscite e copertura editoriale.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci post, stati e output AI.</p>
                    </a>
                    <a href="{{ route('profile.brand') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Brand e asset</p>
                        <p class="mt-1 text-xs text-gray-600">Aggiorna riferimenti e materiali che guidano l'AI.</p>
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
