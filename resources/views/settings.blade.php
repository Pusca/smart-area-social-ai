<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Impostazioni
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">PWA & Notifiche Push</h3>
                <p class="text-sm text-gray-600 mt-1">
                    Installa l app e abilita le notifiche push (verranno salvate sul tuo account).
                </p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border p-4">
                        <p class="font-semibold text-gray-900">PWA</p>
                        <p class="text-sm text-gray-600 mt-1">Installazione app</p>

                        <button id="pwa-install-btn" type="button"
                            class="mt-3 inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 hidden">
                            Installa App
                        </button>

                        <p class="text-xs text-gray-500 mt-2">
                            Se non compare, usa il menu del browser e scegli "Installa app".
                        </p>
                    </div>

                    <div class="rounded-lg border p-4">
                        <p class="font-semibold text-gray-900">Notifiche Push</p>
                        <p class="text-sm text-gray-600 mt-1">Attiva e salva la subscription</p>

                        <div class="mt-3 flex flex-col gap-2">
                            <button id="push-enable-btn" type="button"
                                class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                Attiva notifiche
                            </button>

                            <button id="push-test-btn" type="button"
                                class="inline-flex justify-center rounded-lg border px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Invia notifica test
                            </button>
                        </div>

                        <p id="push-status" class="text-xs text-gray-500 mt-2">Stato: non attive</p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">Connessioni Social (prossimo step)</h3>
                <ul class="mt-3 list-disc pl-5 text-sm text-gray-700 space-y-1">
                    <li>Meta (Instagram/Facebook)</li>
                    <li>TikTok</li>
                    <li>Gestione token + reconnect</li>
                </ul>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">AI</h3>
                <p class="text-sm text-gray-600 mt-1">
                    In seguito qui scegliamo: tono di voce, lingue, obiettivi e prompt template.
                </p>
            </div>

        </div>
    </div>

    <script>
        let deferredPrompt = null;
        const installBtn = document.getElementById("pwa-install-btn");
        const pushEnableBtn = document.getElementById("push-enable-btn");
        const pushTestBtn = document.getElementById("push-test-btn");
        const pushStatus = document.getElementById("push-status");

        function setStatus(text) {
            if (pushStatus) pushStatus.textContent = "Stato: " + text;
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

            return await withTimeout(
                navigator.serviceWorker.ready,
                12000,
                "Timeout attivazione Service Worker."
            );
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
</x-app-layout>
