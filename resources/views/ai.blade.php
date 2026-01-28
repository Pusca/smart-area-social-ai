<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                AI Lab (Test Generazione Contenuti)
            </h2>
            <span class="text-xs text-gray-500">OpenAI → Laravel</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-5 sm:p-6">
                    <div class="grid gap-6 lg:grid-cols-2">
                        <!-- FORM -->
                        <div class="rounded-xl border p-4">
                            <h3 class="text-base font-semibold text-gray-900">Genera un post</h3>
                            <p class="text-sm text-gray-500 mt-1">Compila e genera: hook, caption, hashtag, CTA.</p>

                            <form id="aiForm" class="mt-4 space-y-4">
                                @csrf

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Argomento</label>
                                    <input name="topic" type="text" required
                                           class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                           placeholder="Es: Automazioni AI per PMI a Venezia (Smartera)">
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Piattaforma</label>
                                        <select name="platform" class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="instagram">Instagram</option>
                                            <option value="facebook">Facebook</option>
                                            <option value="tiktok">TikTok</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Tono</label>
                                        <select name="tone" class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="professionale">Professionale</option>
                                            <option value="amichevole">Amichevole</option>
                                            <option value="ironico">Ironico</option>
                                            <option value="tecnico">Tecnico</option>
                                            <option value="commerciale">Commerciale</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Obiettivo</label>
                                        <select name="goal" class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="lead">Lead</option>
                                            <option value="brand">Brand</option>
                                            <option value="engagement">Engagement</option>
                                            <option value="vendita">Vendita</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Lingua</label>
                                        <select name="lang" class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="it">Italiano</option>
                                            <option value="en">English</option>
                                        </select>
                                    </div>
                                </div>

                                <button id="btnGen" type="submit"
                                        class="inline-flex items-center justify-center w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
                                    Genera con AI
                                </button>

                                <p id="status" class="text-sm text-gray-500"></p>
                            </form>
                        </div>

                        <!-- OUTPUT -->
                        <div class="rounded-xl border p-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900">Output</h3>
                                <button id="copyAll" type="button"
                                        class="text-xs rounded-md border px-2 py-1 hover:bg-gray-50">
                                    Copia tutto
                                </button>
                            </div>

                            <div id="outWrap" class="mt-4 space-y-3 hidden">
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">Hook</div>
                                    <div id="outHook" class="mt-1 font-medium text-gray-900"></div>
                                </div>

                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">Caption</div>
                                    <div id="outCaption" class="mt-1 whitespace-pre-wrap text-gray-900"></div>
                                </div>

                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">Hashtag</div>
                                    <div id="outTags" class="mt-1 text-gray-900"></div>
                                </div>

                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">CTA</div>
                                    <div id="outCta" class="mt-1 text-gray-900"></div>
                                </div>

                                <div class="rounded-lg bg-gray-50 p-3">
                                    <div class="text-xs text-gray-500">Notes</div>
                                    <div id="outNotes" class="mt-1 text-gray-900"></div>
                                </div>
                            </div>

                            <pre id="raw" class="mt-4 text-xs bg-black text-white rounded-lg p-3 overflow-auto hidden"></pre>
                        </div>
                    </div>

                    <div class="mt-6 text-xs text-gray-500">
                        Nota: Structured Outputs richiede modelli compatibili (es. gpt-4o-mini e successivi). :contentReference[oaicite:6]{index=6}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('aiForm');
        const btn = document.getElementById('btnGen');
        const statusEl = document.getElementById('status');

        const outWrap = document.getElementById('outWrap');
        const raw = document.getElementById('raw');

        const outHook = document.getElementById('outHook');
        const outCaption = document.getElementById('outCaption');
        const outTags = document.getElementById('outTags');
        const outCta = document.getElementById('outCta');
        const outNotes = document.getElementById('outNotes');

        const copyAll = document.getElementById('copyAll');

        function setStatus(msg, isError = false) {
            statusEl.textContent = msg;
            statusEl.className = 'text-sm ' + (isError ? 'text-red-600' : 'text-gray-500');
        }

        function buildAllText(d) {
            return [
                `HOOK: ${d.hook}`,
                ``,
                `CAPTION:\n${d.caption}`,
                ``,
                `HASHTAGS:\n${(d.hashtags || []).join(' ')}`,
                ``,
                `CTA: ${d.cta}`,
                ``,
                `NOTES: ${d.notes}`,
            ].join('\n');
        }

        copyAll.addEventListener('click', async () => {
            if (outWrap.classList.contains('hidden')) return;
            const text = buildAllText({
                hook: outHook.textContent,
                caption: outCaption.textContent,
                hashtags: outTags.textContent.split(' ').filter(Boolean),
                cta: outCta.textContent,
                notes: outNotes.textContent
            });
            await navigator.clipboard.writeText(text);
            setStatus('Copiato ✅');
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            btn.disabled = true;
            setStatus('Generazione in corso…');
            outWrap.classList.add('hidden');
            raw.classList.add('hidden');

            const fd = new FormData(form);

            try {
                const res = await fetch("{{ url('/ai/generate') }}", {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                    },
                    body: fd
                });

                const json = await res.json();

                if (!res.ok || !json.ok) {
                    throw new Error(json?.error || 'Errore sconosciuto');
                }

                const d = json.data;

                outHook.textContent = d.hook || '';
                outCaption.textContent = d.caption || '';
                outTags.textContent = (d.hashtags || []).join(' ');
                outCta.textContent = d.cta || '';
                outNotes.textContent = d.notes || '';

                outWrap.classList.remove('hidden');
                setStatus('Fatto ✅');
            } catch (err) {
                raw.textContent = String(err?.message || err);
                raw.classList.remove('hidden');
                setStatus('Errore: controlla raw/log ✅', true);
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</x-app-layout>
