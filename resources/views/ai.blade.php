<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="ui-title-lg">AI Lab</h2>
            <span class="ui-pill badge-info">Assistente contenuti</span>
        </div>
    </x-slot>

    <section class="ui-shell ui-page space-y-5">
        <div class="ui-hero p-6">
            <div class="ui-kicker">Bozza rapida</div>
            <h1 class="mt-2 ui-title-xl">A cosa serve questa pagina?</h1>
            <p class="mt-2 ui-subtitle">E un laboratorio: inserisci argomento e obiettivo per ottenere una prima bozza (hook, caption, hashtag e CTA) prima di salvarla nei tuoi post.</p>
        </div>

        <div class="ui-card p-5 sm:p-6">
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="ui-card-soft border-l-4 p-4" style="border-left-color: var(--primary);">
                    <h3 class="text-base font-semibold text-text">Input</h3>
                    <p class="mt-1 text-sm text-muted">Definisci argomento, tono e obiettivo.</p>

                    <form id="aiForm" class="mt-4 space-y-4">
                        @csrf

                        <div>
                            <label class="ui-label">Argomento</label>
                            <input name="topic" type="text" required class="ui-input" placeholder="Es: Automazioni AI per PMI locali">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="ui-label">Piattaforma</label>
                                <select name="platform" class="ui-input">
                                    <option value="instagram">Instagram</option>
                                    <option value="facebook">Facebook</option>
                                    <option value="tiktok">TikTok</option>
                                </select>
                            </div>
                            <div>
                                <label class="ui-label">Tono</label>
                                <select name="tone" class="ui-input">
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
                                <label class="ui-label">Obiettivo</label>
                                <select name="goal" class="ui-input">
                                    <option value="lead">Lead</option>
                                    <option value="brand">Brand</option>
                                    <option value="engagement">Engagement</option>
                                    <option value="vendita">Vendita</option>
                                </select>
                            </div>
                            <div>
                                <label class="ui-label">Lingua</label>
                                <select name="lang" class="ui-input">
                                    <option value="it">Italiano</option>
                                    <option value="en">English</option>
                                </select>
                            </div>
                        </div>

                        <button id="btnGen" type="submit" class="ui-btn-primary w-full">Genera bozza</button>
                        <p id="status" class="text-sm text-muted"></p>
                    </form>
                </div>

                <div class="ui-card-soft border-l-4 p-4" style="border-left-color: var(--accent);">
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text">Output</h3>
                        <button id="copyAll" type="button" class="ui-btn-secondary !px-2.5 !py-1.5 !text-xs">Copia tutto</button>
                    </div>

                    <div id="outWrap" class="mt-4 hidden space-y-3">
                        <div class="rounded-lg border border-app bg-surface p-3">
                            <div class="text-xs text-muted">Hook</div>
                            <div id="outHook" class="mt-1 font-medium text-text"></div>
                        </div>
                        <div class="rounded-lg border border-app bg-surface p-3">
                            <div class="text-xs text-muted">Caption</div>
                            <div id="outCaption" class="mt-1 whitespace-pre-wrap text-text"></div>
                        </div>
                        <div class="rounded-lg border border-app bg-surface p-3">
                            <div class="text-xs text-muted">Hashtag</div>
                            <div id="outTags" class="mt-1 text-text"></div>
                        </div>
                        <div class="rounded-lg border border-app bg-surface p-3">
                            <div class="text-xs text-muted">CTA</div>
                            <div id="outCta" class="mt-1 text-text"></div>
                        </div>
                        <div class="rounded-lg border border-app bg-surface p-3">
                            <div class="text-xs text-muted">Notes</div>
                            <div id="outNotes" class="mt-1 text-text"></div>
                        </div>
                    </div>

                    <pre id="raw" class="mt-4 hidden overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100"></pre>
                </div>
            </div>
        </div>
    </section>

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

        function setStatus(msg, state = 'normal') {
            const classes = {
                normal: 'text-sm text-muted',
                success: 'text-sm text-success',
                warning: 'text-sm text-warning',
                error: 'text-sm text-danger',
            };

            statusEl.textContent = msg;
            statusEl.className = classes[state] || classes.normal;
        }

        function buildAllText(d) {
            return [
                `HOOK: ${d.hook}`,
                '',
                `CAPTION:\n${d.caption}`,
                '',
                `HASHTAGS:\n${(d.hashtags || []).join(' ')}`,
                '',
                `CTA: ${d.cta}`,
                '',
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
                notes: outNotes.textContent,
            });

            await navigator.clipboard.writeText(text);
            setStatus('Copiato negli appunti.', 'success');
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            btn.disabled = true;
            setStatus('Generazione in corso...');
            outWrap.classList.add('hidden');
            raw.classList.add('hidden');
            raw.textContent = '';

            const fd = new FormData(form);

            try {
                const res = await fetch("{{ route('ai.generate') }}", {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    },
                    body: fd,
                });

                const contentType = res.headers.get('content-type') || '';
                const json = contentType.includes('application/json')
                    ? await res.json()
                    : { ok: false, error: `Risposta non valida (${res.status})` };

                if (!res.ok || !json.ok) {
                    throw new Error(json?.error || `Errore ${res.status}`);
                }

                const d = json.data || {};
                outHook.textContent = d.hook || '';
                outCaption.textContent = d.caption || '';
                outTags.textContent = (d.hashtags || []).join(' ');
                outCta.textContent = d.cta || '';
                outNotes.textContent = d.notes || '';

                outWrap.classList.remove('hidden');
                if (json.fallback) {
                    setStatus('OpenAI non raggiungibile: generata una bozza fallback.', 'warning');
                } else {
                    setStatus('Bozza generata.', 'success');
                }
            } catch (err) {
                raw.textContent = String(err?.message || err);
                raw.classList.remove('hidden');
                setStatus('Errore durante la generazione.', 'error');
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</x-app-layout>
