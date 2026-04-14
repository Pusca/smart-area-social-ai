<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Benvenuto — {{ config('app.name', 'Social AI') }}</title>
    <meta name="theme-color" content="#0A2D6F">
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-app text-text antialiased">

{{-- Sfondo decorativo --}}
<div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
    <div class="absolute -left-24 -top-16 h-96 w-96 rounded-full blur-3xl" style="background: rgba(59,200,255,0.10);"></div>
    <div class="absolute right-0 bottom-0 h-80 w-80 rounded-full blur-3xl" style="background: rgba(10,45,111,0.07);"></div>
</div>

<div
    class="mx-auto min-h-screen max-w-2xl px-4 py-12 sm:px-6"
    x-data="onboarding()"
    x-init="init()"
>
    {{-- Logo + titolo --}}
    <div class="mb-10 text-center">
        <x-application-logo variant="full" class="mx-auto h-10 w-auto" />
        <p class="mt-3 text-sm text-muted">Configuriamo il tuo workspace in 4 passaggi</p>
    </div>

    {{-- Step indicator --}}
    <div class="mb-10 flex items-center justify-center gap-0">
        <template x-for="(label, i) in steps" :key="i">
            <div class="flex items-center">
                <div class="flex flex-col items-center">
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold transition-all"
                        :class="i < currentStep
                            ? 'border-brand bg-brand text-white'
                            : i === currentStep
                                ? 'border-brand bg-white text-brand shadow-md'
                                : 'border-gray-200 bg-white text-gray-400'"
                    >
                        <template x-if="i < currentStep">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </template>
                        <template x-if="i >= currentStep">
                            <span x-text="i + 1"></span>
                        </template>
                    </div>
                    <span
                        class="mt-1.5 hidden text-[10px] font-medium sm:block"
                        :class="i === currentStep ? 'text-brand' : 'text-gray-400'"
                        x-text="label"
                    ></span>
                </div>
                <template x-if="i < steps.length - 1">
                    <div class="mb-4 h-px w-10 transition-all sm:w-16" :class="i < currentStep ? 'bg-brand' : 'bg-gray-200'"></div>
                </template>
            </div>
        </template>
    </div>

    {{-- Pannello card --}}
    <div class="overflow-hidden rounded-[2rem] border border-app bg-white/92 shadow-panel">

        {{-- ── STEP 0: Brand basics ─────────────────────────────────────── --}}
        <div x-show="currentStep === 0" x-cloak class="p-8">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Il tuo brand</h2>
                <p class="mt-1 text-sm text-muted">Tre informazioni che guidano ogni contenuto generato dall'AI.</p>
            </div>

            <form @submit.prevent="saveBrand" class="space-y-5">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">Nome brand / azienda <span class="text-red-500">*</span></label>
                    <input
                        type="text"
                        x-model="form.brand_name"
                        placeholder="es. Pizzeria Da Mario"
                        class="ui-input"
                        required
                        maxlength="120"
                    >
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">Settore / industria <span class="text-red-500">*</span></label>
                    <input
                        type="text"
                        x-model="form.industry"
                        placeholder="es. Ristorazione, Moda, Consulenza, Benessere"
                        class="ui-input"
                        required
                        maxlength="120"
                    >
                    <p class="mt-1 text-xs text-muted">Scrivi in italiano il settore in cui opera il tuo brand.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">Tono di voce <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <template x-for="t in tones" :key="t.value">
                            <button
                                type="button"
                                @click="form.tone = t.value"
                                class="rounded-xl border px-3 py-2.5 text-sm font-medium transition-all"
                                :class="form.tone === t.value
                                    ? 'border-brand bg-brand-soft text-brand shadow-sm'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'"
                            >
                                <div class="font-semibold" x-text="t.label"></div>
                                <div class="mt-0.5 text-xs font-normal text-muted" x-text="t.hint"></div>
                            </button>
                        </template>
                    </div>
                </div>

                <div x-show="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></div>

                <div class="pt-2">
                    <button
                        type="submit"
                        class="ui-btn-primary w-full py-3"
                        :disabled="loading"
                        :class="loading && 'opacity-60 cursor-not-allowed'"
                    >
                        <span x-show="!loading">Avanti →</span>
                        <span x-show="loading">Salvataggio…</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- ── STEP 1: Audience & services ─────────────────────────────── --}}
        <div x-show="currentStep === 1" x-cloak class="p-8">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Audience e servizi</h2>
                <p class="mt-1 text-sm text-muted">Definiamo a chi parli e cosa offri, cosi l'AI costruisce messaggi mirati.</p>
            </div>

            <form @submit.prevent="saveAudience" class="space-y-5">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">A chi ti rivolgi <span class="text-red-500">*</span></label>
                    <textarea
                        x-model="form.target"
                        rows="2"
                        placeholder="es. Famiglie con bambini, professionisti 30-45 anni, appassionati di fitness"
                        class="ui-input resize-none"
                        required
                        maxlength="255"
                    ></textarea>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">Prodotti / servizi principali <span class="text-red-500">*</span></label>
                    <textarea
                        x-model="form.services"
                        rows="3"
                        placeholder="es. Pizza napoletana, catering per eventi, consegna a domicilio nel centro città"
                        class="ui-input resize-none"
                        required
                        maxlength="500"
                    ></textarea>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">Obiettivo principale dei contenuti</label>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <template x-for="g in goals" :key="g.value">
                            <button
                                type="button"
                                @click="form.default_goal = g.value"
                                class="rounded-xl border px-3 py-2.5 text-left text-sm transition-all"
                                :class="form.default_goal === g.value
                                    ? 'border-brand bg-brand-soft text-brand shadow-sm'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'"
                            >
                                <div class="font-semibold" x-text="g.emoji + ' ' + g.label"></div>
                                <div class="mt-0.5 text-xs font-normal text-muted" x-text="g.hint"></div>
                            </button>
                        </template>
                    </div>
                </div>

                <div x-show="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></div>

                <div class="flex gap-3 pt-2">
                    <button type="button" @click="currentStep = 0" class="ui-btn-secondary flex-1 py-3">
                        ← Indietro
                    </button>
                    <button
                        type="submit"
                        class="ui-btn-primary flex-1 py-3"
                        :disabled="loading"
                        :class="loading && 'opacity-60 cursor-not-allowed'"
                    >
                        <span x-show="!loading">Avanti →</span>
                        <span x-show="loading">Salvataggio…</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- ── STEP 2: Collega social ───────────────────────────────────── --}}
        <div x-show="currentStep === 2" x-cloak class="p-8">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">Collega i social</h2>
                <p class="mt-1 text-sm text-muted">Facoltativo ora — puoi farlo sempre in seguito dalle impostazioni.</p>
            </div>

            <div class="space-y-3">
                {{-- Meta (Facebook + Instagram) --}}
                <a
                    href="{{ route('settings.social.meta.redirect') }}"
                    class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-gray-300 hover:shadow-sm"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-blue-500 text-white">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">Facebook &amp; Instagram</div>
                        <div class="text-sm text-muted">Collega le pagine Meta per pubblicare direttamente</div>
                    </div>
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                {{-- LinkedIn --}}
                <a
                    href="{{ route('settings.social.linkedin.redirect') }}"
                    class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-gray-300 hover:shadow-sm"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#0A66C2] text-white">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">LinkedIn</div>
                        <div class="text-sm text-muted">Pubblica aggiornamenti e articoli professionali</div>
                    </div>
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                {{-- TikTok --}}
                <a
                    href="{{ route('settings.social.tiktok.redirect') }}"
                    class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-gray-300 hover:shadow-sm"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-black text-white">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.78 1.52V6.75a4.85 4.85 0 01-1.01-.06z"/></svg>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">TikTok</div>
                        <div class="text-sm text-muted">Carica video e reel direttamente sull'account</div>
                    </div>
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="button" @click="currentStep = 1" class="ui-btn-secondary flex-1 py-3">
                    ← Indietro
                </button>
                <button type="button" @click="skipSocial" class="ui-btn-primary flex-1 py-3" :disabled="loading" :class="loading && 'opacity-60 cursor-not-allowed'">
                    <span x-show="!loading">Salta per ora →</span>
                    <span x-show="loading">…</span>
                </button>
            </div>
            <p class="mt-3 text-center text-xs text-muted">Puoi collegare i social in qualsiasi momento da Impostazioni</p>
        </div>

        {{-- ── STEP 3: Completato ───────────────────────────────────────── --}}
        <div x-show="currentStep === 3" x-cloak class="p-8 text-center">
            <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-green-50 text-green-600">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h2 class="text-xl font-semibold text-gray-900">Workspace configurato!</h2>
            <p class="mt-2 text-sm text-muted max-w-md mx-auto">
                Perfetto, <strong x-text="'{{ $userName }}'"></strong>. Ora generiamo il tuo primo piano editoriale
                con contenuti virali pensati per il tuo settore.
            </p>

            <div class="mt-8 rounded-xl border border-brand bg-brand-soft p-5 text-left">
                <div class="text-xs font-semibold uppercase tracking-wider text-brand mb-3">Cosa succede adesso</div>
                <ul class="space-y-2 text-sm text-gray-700">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 text-brand font-bold">1.</span>
                        L'AI analizza il tuo settore e costruisce un framework virale su misura
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 text-brand font-bold">2.</span>
                        Vengono generati i tuoi primi post con testo, immagini e video
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 text-brand font-bold">3.</span>
                        Il calendario si popola automaticamente con il piano dei prossimi 30 giorni
                    </li>
                </ul>
            </div>

            <div x-show="error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></div>

            <button
                type="button"
                @click="complete"
                class="ui-btn-primary mt-6 w-full py-3 text-base"
                :disabled="loading"
                :class="loading && 'opacity-60 cursor-not-allowed'"
            >
                <span x-show="!loading">Genera il mio piano editoriale →</span>
                <span x-show="loading">Avvio in corso…</span>
            </button>
        </div>

    </div>{{-- /card --}}

    {{-- Footer minimal --}}
    <p class="mt-8 text-center text-xs text-muted">
        &copy; {{ date('Y') }} {{ config('app.name', 'Social AI') }} &mdash;
        <a href="{{ route('logout') }}" class="underline"
            onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Esci</a>
    </p>
    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>

</div>{{-- /x-data --}}

<script>
function onboarding() {
    return {
        currentStep: 0,
        loading: false,
        error: '',
        form: {
            brand_name:   '',
            industry:     '',
            tone:         'professionale',
            target:       '',
            services:     '',
            default_goal: 'awareness',
        },
        steps: ['Brand', 'Audience', 'Social', 'Pronto'],
        tones: [
            { value: 'professionale', label: 'Professionale',  hint: 'Autorevole, diretto'   },
            { value: 'amichevole',    label: 'Amichevole',     hint: 'Caldo, vicino'          },
            { value: 'divertente',    label: 'Divertente',     hint: 'Leggero, ironico'       },
            { value: 'ispirazionale', label: 'Ispirazionale',  hint: 'Motivante, visionario'  },
            { value: 'informativo',   label: 'Informativo',    hint: 'Chiaro, educativo'      },
            { value: 'lusso',         label: 'Premium',        hint: 'Sofisticato, esclusivo' },
        ],
        goals: [
            { value: 'awareness',   emoji: '📣', label: 'Visibilità',    hint: 'Fatti conoscere'       },
            { value: 'engagement',  emoji: '💬', label: 'Engagement',    hint: 'Like, commenti, share' },
            { value: 'lead',        emoji: '🎯', label: 'Lead',          hint: 'Genera contatti'       },
            { value: 'conversion',  emoji: '💰', label: 'Vendite',       hint: 'Spingi all\'acquisto'  },
            { value: 'trust',       emoji: '🤝', label: 'Fiducia',       hint: 'Costruisci autorità'   },
        ],

        init() {
            // pre-fill se già salvato in una sessione precedente
        },

        async saveBrand() {
            this.error = '';
            if (!this.form.brand_name || !this.form.industry || !this.form.tone) {
                this.error = 'Compila tutti i campi richiesti.';
                return;
            }
            this.loading = true;
            try {
                const res = await fetch('{{ route('onboarding.brand') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        brand_name: this.form.brand_name,
                        industry:   this.form.industry,
                        tone:       this.form.tone,
                    }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || 'Errore di salvataggio.');
                }
                this.currentStep = 1;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async saveAudience() {
            this.error = '';
            if (!this.form.target || !this.form.services) {
                this.error = 'Compila tutti i campi richiesti.';
                return;
            }
            this.loading = true;
            try {
                const res = await fetch('{{ route('onboarding.audience') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        target:       this.form.target,
                        services:     this.form.services,
                        default_goal: this.form.default_goal,
                    }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || 'Errore di salvataggio.');
                }
                this.currentStep = 2;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async skipSocial() {
            this.loading = true;
            try {
                await fetch('{{ route('onboarding.skip-social') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                this.currentStep = 3;
            } finally {
                this.loading = false;
            }
        },

        async complete() {
            this.error = '';
            this.loading = true;
            try {
                const res = await fetch('{{ route('onboarding.complete') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Errore.');
                window.location.href = data.redirect_url;
            } catch (e) {
                this.error = e.message;
                this.loading = false;
            }
        },
    };
}
</script>

</body>
</html>
