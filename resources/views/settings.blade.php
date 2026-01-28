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
                    Installa l’app e abilita le notifiche push (verranno salvate sul tuo account).
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
                            Se non compare, usa il menu del browser → “Installa app”.
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
        // --- PWA install prompt ---
        let deferredPrompt = null;
        const installBtn = document.getElementById("pwa-install-btn");

        window.addEventListener("beforeinstallprompt", (e) => {
            e.preventDefault();
            deferredPrompt = e;
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

        // --- Push subscribe + test ---
        const pushEnableBtn = document.getElementById("push-enable-btn");
        const pushTestBtn = document.getElementById("push-test-btn");
        const pushStatus = document.getElementById("push-status");

        function setStatus(text) {
            if (pushStatus) pushStatus.textContent = "Stato: " + text;
        }

        async function getCsrf() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute("content") : "";
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
            const raw = atob(base64);
            const output = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i);
            return output;
        }

        async function ensureServiceWorkerReady() {
            if (!("serviceWorker" in navigator)) throw new Error("Service Worker non supportato.");
            return await navigator.serviceWorker.ready;
        }

        async function subscribePush() {
            if (!("Notification" in window)) throw new Error("Notifiche non supportate.");
            const perm = await Notification.requestPermission();
            if (perm !== "granted") throw new Error("Permesso notifiche negato.");

            const reg = await ensureServiceWorkerReady();

            // recupero public key dal backend
            const keyRes = await fetch("/push/public-key", { headers: { "Accept": "application/json" } });
            const keyJson = await keyRes.json();
            const vapidPublicKey = keyJson.publicKey;

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            const csrf = await getCsrf();

            const res = await fetch("/push/subscribe", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": csrf,
                },
                body: JSON.stringify(sub),
            });

            if (!res.ok) {
                const t = await res.text();
                throw new Error("Errore salvataggio subscription: " + t);
            }

            setStatus("attive ✅");
        }

        async function sendTest() {
            const csrf = await getCsrf();
            const res = await fetch("/push/test", {
                method: "POST",
                headers: {
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": csrf,
                },
            });

            const json = await res.json();
            setStatus("test inviato (sent: " + json.sent + ")");
        }

        if (pushEnableBtn) {
            pushEnableBtn.addEventListener("click", async () => {
                try {
                    setStatus("attivazione...");
                    await subscribePush();
                } catch (e) {
                    console.error(e);
                    setStatus("errore ❌ (" + (e?.message || e) + ")");
                }
            });
        }

        if (pushTestBtn) {
            pushTestBtn.addEventListener("click", async () => {
                try {
                    setStatus("invio test...");
                    await sendTest();
                } catch (e) {
                    console.error(e);
                    setStatus("errore ❌ (" + (e?.message || e) + ")");
                }
            });
        }
    </script>
</x-app-layout>
