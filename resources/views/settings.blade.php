@extends('layouts.app')

@section('content')
<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">System Settings</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Impostazioni app</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Gestisci installazione PWA, notifiche push e configurazioni operative di base.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Setup dispositivo
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        PWA + Push
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Torna dashboard
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Vai ai contenuti
                    </a>
                    <a href="{{ route('profile.brand') }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Apri profilo brand
                    </a>
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
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Roadmap</p>
                <span class="text-xs font-semibold text-amber-700">Next</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">Integrazioni social e automazioni avanzate.</p>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
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
                    <button id="pwa-install-btn" type="button" class="mt-4 hidden inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
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
                        <button id="push-enable-btn" type="button" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
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
                    <li>Connessioni Meta, TikTok, LinkedIn</li>
                    <li>Gestione token e reconnect automatico</li>
                    <li>Preset AI per tono, obiettivi e formati</li>
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
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Collegamenti utili</h2>
                <p class="mt-1 text-sm text-gray-600">Aree collegate alla configurazione applicativa.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('dashboard') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Dashboard</p>
                        <p class="mt-1 text-xs text-gray-600">Panoramica generale dell'app.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla pianificazione editoriale.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci post, stati e output AI.</p>
                    </a>
                </div>
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
