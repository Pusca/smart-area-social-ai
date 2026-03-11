<div id="generation-loader-overlay" class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-950/65 px-4">
    <div class="w-full max-w-xl overflow-hidden rounded-3xl border border-white/10 bg-slate-950 text-white shadow-2xl">
        <div class="bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 px-6 py-1"></div>
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start gap-4">
                <div class="relative mt-1 h-12 w-12 shrink-0">
                    <span class="absolute inset-0 rounded-full border-2 border-white/15"></span>
                    <span class="absolute inset-0 rounded-full border-2 border-transparent border-t-cyan-300 border-r-cyan-300 animate-spin"></span>
                    <span class="absolute inset-[9px] rounded-full border border-indigo-300/50"></span>
                    <span class="absolute inset-[16px] rounded-full bg-cyan-300/80 blur-sm"></span>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-200/80">AI In Lavorazione</p>
                    <h3 id="generation-loader-title" class="mt-2 text-2xl font-semibold text-white">Sto preparando il contenuto</h3>
                    <p id="generation-loader-subtitle" class="mt-2 text-sm text-slate-300">
                        Raccolgo strategia, assets e prompt per costruire un output credibile e pronto da usare.
                    </p>
                </div>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <p id="generation-loader-stage" class="font-semibold text-white">Analisi brief e materiali</p>
                    <p id="generation-loader-eta" class="font-semibold text-cyan-200">Circa 45 sec</p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10">
                    <div id="generation-loader-progress" class="h-full rounded-full bg-gradient-to-r from-cyan-300 via-indigo-400 to-emerald-300 transition-[width] duration-300" style="width: 8%"></div>
                </div>
                <div class="mt-3 flex items-center justify-between text-xs text-slate-300">
                    <span>La macchina sta lavorando</span>
                    <span id="generation-loader-detail">Tempo stimato in aggiornamento</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        if (window.HostupGenerationLoader) {
            return;
        }

        const overlay = document.getElementById('generation-loader-overlay');
        const titleEl = document.getElementById('generation-loader-title');
        const subtitleEl = document.getElementById('generation-loader-subtitle');
        const stageEl = document.getElementById('generation-loader-stage');
        const etaEl = document.getElementById('generation-loader-eta');
        const detailEl = document.getElementById('generation-loader-detail');
        const progressEl = document.getElementById('generation-loader-progress');

        if (!overlay || !titleEl || !subtitleEl || !stageEl || !etaEl || !detailEl || !progressEl) {
            return;
        }

        let timer = null;

        const formatTime = (seconds) => {
            const safe = Math.max(0, Math.round(Number(seconds || 0)));
            if (safe < 60) {
                return `${safe} sec`;
            }

            const minutes = Math.floor(safe / 60);
            const rest = safe % 60;
            return rest > 0 ? `${minutes} min ${rest} sec` : `${minutes} min`;
        };

        const stop = () => {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const hide = () => {
            stop();
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        };

        const show = (options = {}) => {
            stop();

            const estimateSeconds = Math.max(12, Number(options.estimateSeconds || 45));
            const title = String(options.title || 'Sto preparando il contenuto');
            const subtitle = String(options.subtitle || 'Raccolgo strategia, assets e prompt per costruire un output credibile e pronto da usare.');
            const stages = Array.isArray(options.stages) && options.stages.length > 0
                ? options.stages.map((item) => String(item))
                : ['Analisi brief e materiali', 'Scrittura caption e prompt', 'Generazione e rifinitura output'];

            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            titleEl.textContent = title;
            subtitleEl.textContent = subtitle;

            const startedAt = Date.now();
            const update = () => {
                const elapsed = (Date.now() - startedAt) / 1000;
                const progress = Math.min(94, Math.max(8, (elapsed / estimateSeconds) * 100));
                const remaining = Math.max(0, estimateSeconds - elapsed);
                const stageIndex = Math.min(stages.length - 1, Math.floor((progress / 100) * stages.length));

                progressEl.style.width = `${progress}%`;
                stageEl.textContent = stages[stageIndex] || stages[stages.length - 1];
                etaEl.textContent = remaining > 0 ? `Circa ${formatTime(remaining)}` : 'Quasi pronto';
                detailEl.textContent = remaining > 0
                    ? `Tempo stimato totale: ${formatTime(estimateSeconds)}`
                    : 'Sto rifinendo l output finale';
            };

            update();
            timer = window.setInterval(update, 250);
        };

        window.addEventListener('pageshow', hide);
        window.addEventListener('pagehide', stop);

        window.HostupGenerationLoader = { show, hide };
    })();
</script>
