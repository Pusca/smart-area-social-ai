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
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>

<body class="min-h-screen bg-[#F5F7FE] antialiased">

{{-- Sfondo decorativo fisso --}}
<div class="pointer-events-none fixed inset-0 -z-10">
    <div class="absolute -left-40 -top-32 h-[500px] w-[500px] rounded-full blur-[120px]" style="background: rgba(59,130,246,0.09);"></div>
    <div class="absolute -right-40 bottom-0 h-[400px] w-[400px] rounded-full blur-[100px]" style="background: rgba(99,102,241,0.07);"></div>
    <div class="absolute left-1/2 top-1/3 h-[300px] w-[600px] -translate-x-1/2 rounded-full blur-[140px]" style="background: rgba(34,211,238,0.05);"></div>
</div>

<div
    class="mx-auto flex min-h-screen max-w-lg flex-col px-4 py-10 sm:px-6"
    x-data="onboarding()"
    x-init="init()"
>

    {{-- Logo --}}
    <div class="mb-8 flex justify-center">
        <x-application-logo variant="full" class="h-9 w-auto" />
    </div>

    {{-- Progress stepper --}}
    <div class="mb-8">
        <div class="relative flex items-center justify-between">
            {{-- Line track (behind circles) --}}
            <div class="absolute left-0 right-0 top-4 h-px bg-gray-200" aria-hidden="true"></div>
            <div
                class="absolute left-0 top-4 h-px bg-brand transition-all duration-500"
                :style="`width: ${(currentStep / (steps.length - 1)) * 100}%`"
                aria-hidden="true"
            ></div>

            <template x-for="(label, i) in steps" :key="i">
                <div class="relative flex flex-col items-center" style="flex: 1; max-width: 25%">
                    <div
                        class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold transition-all duration-300"
                        :class="{
                            'border-brand bg-brand text-white shadow-md shadow-brand/30': i < currentStep,
                            'border-brand bg-white text-brand ring-4 ring-brand/20 shadow-lg': i === currentStep,
                            'border-gray-200 bg-white text-gray-400': i > currentStep
                        }"
                    >
                        <template x-if="i < currentStep">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </template>
                        <template x-if="i >= currentStep">
                            <span x-text="i + 1" class="leading-none"></span>
                        </template>
                    </div>
                    <span
                        class="mt-2 text-[10px] font-semibold tracking-wide transition-colors duration-300"
                        :class="i === currentStep ? 'text-brand' : i < currentStep ? 'text-brand/60' : 'text-gray-400'"
                        x-text="label"
                    ></span>
                </div>
            </template>
        </div>
    </div>

    {{-- Card principale — NO overflow-hidden per non tagliare decorazioni --}}
    <div class="flex-1">

        {{-- ── STEP 0: Brand ─────────────────────────────────────────────── --}}
        <div x-show="currentStep === 0" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
        >
            {{-- Icona step --}}
            <div class="mb-5 flex justify-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-blue-600 shadow-lg shadow-indigo-200">
                    <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
            </div>

            <div class="mb-6 text-center">
                <h2 class="text-2xl font-bold text-gray-950">Il tuo brand</h2>
                <p class="mt-1.5 text-sm text-gray-500">Tre informazioni che guidano ogni contenuto generato dall'AI.</p>
            </div>

            <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                <form @submit.prevent="saveBrand" class="space-y-5">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-gray-700">Nome brand / azienda <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            x-model="form.brand_name"
                            placeholder="es. Pizzeria Da Mario"
                            class="block w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 transition focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20"
                            required
                            maxlength="120"
                            autofocus
                        >
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-gray-700">Settore / industria <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            x-model="form.industry"
                            placeholder="es. Ristorazione, Moda, Consulenza, Benessere"
                            class="block w-full rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 transition focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20"
                            required
                            maxlength="120"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-gray-700">Tono di voce <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="t in tones" :key="t.value">
                                <button
                                    type="button"
                                    @click="form.tone = t.value"
                                    class="group rounded-2xl border p-3 text-left transition-all duration-200"
                                    :class="form.tone === t.value
                                        ? 'border-brand bg-gradient-to-br from-indigo-50 to-blue-50 shadow-sm ring-1 ring-brand/30'
                                        : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50'"
                                >
                                    <div class="text-sm font-semibold" :class="form.tone === t.value ? 'text-brand' : 'text-gray-800'" x-text="t.label"></div>
                                    <div class="mt-0.5 text-xs text-gray-400" x-text="t.hint"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                    <button
                        type="submit"
                        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-indigo-600 to-blue-600 py-3 text-sm font-semibold text-white shadow-md shadow-indigo-200 transition hover:from-indigo-700 hover:to-blue-700 disabled:opacity-50"
                        :disabled="loading"
                    >
                        <span x-show="!loading">Continua</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Salvataggio…
                        </span>
                    </button>
                </form>
            </div>
        </div>

        {{-- ── STEP 1: Audience ───────────────────────────────────────────── --}}
        <div x-show="currentStep === 1" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
        >
            <div class="mb-5 flex justify-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-200">
                    <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>

            <div class="mb-6 text-center">
                <h2 class="text-2xl font-bold text-gray-950">Audience e servizi</h2>
                <p class="mt-1.5 text-sm text-gray-500">A chi parli e cosa offri — l'AI costruisce messaggi mirati per loro.</p>
            </div>

            <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                <form @submit.prevent="saveAudience" class="space-y-5">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-gray-700">A chi ti rivolgi <span class="text-red-500">*</span></label>
                        <textarea
                            x-model="form.target"
                            rows="2"
                            placeholder="es. Famiglie con bambini, professionisti 30-45 anni, appassionati di fitness"
                            class="block w-full resize-none rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 transition focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20"
                            required
                            maxlength="255"
                        ></textarea>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-gray-700">Prodotti / servizi principali <span class="text-red-500">*</span></label>
                        <textarea
                            x-model="form.services"
                            rows="3"
                            placeholder="es. Pizza napoletana, catering per eventi, consegna a domicilio"
                            class="block w-full resize-none rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 transition focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20"
                            required
                            maxlength="500"
                        ></textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-gray-700">Obiettivo principale dei contenuti</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="g in goals" :key="g.value">
                                <button
                                    type="button"
                                    @click="form.default_goal = g.value"
                                    class="rounded-2xl border p-3 text-left transition-all duration-200"
                                    :class="form.default_goal === g.value
                                        ? 'border-emerald-400 bg-gradient-to-br from-emerald-50 to-teal-50 shadow-sm ring-1 ring-emerald-300/50'
                                        : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50'"
                                >
                                    <div class="text-base" x-text="g.emoji"></div>
                                    <div class="mt-0.5 text-xs font-semibold" :class="form.default_goal === g.value ? 'text-emerald-700' : 'text-gray-800'" x-text="g.label"></div>
                                    <div class="text-[10px] text-gray-400" x-text="g.hint"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                    <div class="flex gap-3">
                        <button type="button" @click="currentStep = 0"
                            class="flex flex-1 items-center justify-center gap-1 rounded-2xl border border-gray-200 bg-white py-3 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                            ← Indietro
                        </button>
                        <button type="submit"
                            class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-600 py-3 text-sm font-semibold text-white shadow-md shadow-emerald-200 transition hover:from-emerald-600 hover:to-teal-700 disabled:opacity-50"
                            :disabled="loading">
                            <span x-show="!loading">Continua</span>
                            <span x-show="loading" class="flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Salvataggio…
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── STEP 2: Social ─────────────────────────────────────────────── --}}
        <div x-show="currentStep === 2" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
        >
            <div class="mb-5 flex justify-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-lg shadow-violet-200">
                    <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                    </svg>
                </div>
            </div>

            <div class="mb-6 text-center">
                <h2 class="text-2xl font-bold text-gray-950">Collega i social</h2>
                <p class="mt-1.5 text-sm text-gray-500">Facoltativo ora — puoi sempre farlo dopo dalle impostazioni.</p>
            </div>

            <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="space-y-2.5">
                    <a href="{{ route('settings.social.meta.redirect') }}"
                        class="flex items-center gap-4 rounded-2xl border border-gray-100 p-4 transition hover:border-blue-200 hover:bg-blue-50/50">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-blue-500 shadow-sm">
                            <svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-gray-900">Facebook &amp; Instagram</div>
                            <div class="text-xs text-gray-400">Pubblica direttamente sulle pagine Meta</div>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>

                    <a href="{{ route('settings.social.linkedin.redirect') }}"
                        class="flex items-center gap-4 rounded-2xl border border-gray-100 p-4 transition hover:border-blue-200 hover:bg-blue-50/50">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-sm" style="background:#0A66C2">
                            <svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-gray-900">LinkedIn</div>
                            <div class="text-xs text-gray-400">Post professionali e articoli</div>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>

                    <a href="{{ route('settings.social.tiktok.redirect') }}"
                        class="flex items-center gap-4 rounded-2xl border border-gray-100 p-4 transition hover:border-gray-300 hover:bg-gray-50">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-black shadow-sm">
                            <svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.78 1.52V6.75a4.85 4.85 0 01-1.01-.06z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-gray-900">TikTok</div>
                            <div class="text-xs text-gray-400">Video e reel sull'account</div>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <div class="mt-5 flex gap-3">
                    <button type="button" @click="currentStep = 1"
                        class="flex flex-1 items-center justify-center rounded-2xl border border-gray-200 bg-white py-3 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        ← Indietro
                    </button>
                    <button type="button" @click="skipSocial"
                        class="flex flex-1 items-center justify-center rounded-2xl bg-gradient-to-r from-violet-500 to-purple-600 py-3 text-sm font-semibold text-white shadow-md shadow-violet-200 transition hover:from-violet-600 hover:to-purple-700 disabled:opacity-50"
                        :disabled="loading">
                        <span x-show="!loading">Salta per ora →</span>
                        <span x-show="loading">…</span>
                    </button>
                </div>
                <p class="mt-3 text-center text-xs text-gray-400">Puoi collegare i social in qualsiasi momento da Impostazioni</p>
            </div>
        </div>

        {{-- ── STEP 3: Pronto! ────────────────────────────────────────────── --}}
        <div x-show="currentStep === 3" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
        >
            <div class="mb-5 flex justify-center">
                <div class="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 shadow-lg shadow-orange-200">
                    <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    {{-- Pulse ring --}}
                    <div class="absolute inset-0 rounded-2xl ring-4 ring-orange-300/40 animate-ping" style="animation-duration:2s"></div>
                </div>
            </div>

            <div class="mb-6 text-center">
                <h2 class="text-2xl font-bold text-gray-950">Workspace configurato!</h2>
                <p class="mt-1.5 text-sm text-gray-500">
                    Perfetto, <strong class="text-gray-800">{{ $userName }}</strong>. Generiamo il tuo primo piano editoriale.
                </p>
            </div>

            <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                {{-- Cosa succede --}}
                <div class="mb-5 rounded-2xl bg-gradient-to-br from-amber-50 to-orange-50 p-4">
                    <p class="mb-3 text-xs font-bold uppercase tracking-wider text-amber-600">Cosa succede adesso</p>
                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-600">1</span>
                            <span class="text-sm text-gray-700">L'AI analizza il tuo settore e costruisce un framework virale su misura</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-600">2</span>
                            <span class="text-sm text-gray-700">Vengono generati i primi post con testo, immagini e video</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-600">3</span>
                            <span class="text-sm text-gray-700">Il calendario si popola con il piano dei prossimi 30 giorni</span>
                        </li>
                    </ul>
                </div>

                <div x-show="error" x-cloak class="mb-4 rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                <button
                    type="button"
                    @click="complete"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-amber-500 to-orange-500 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-200 transition hover:from-amber-600 hover:to-orange-600 disabled:opacity-50"
                    :disabled="loading"
                >
                    <span x-show="!loading" class="flex items-center gap-2">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Genera il mio piano editoriale
                    </span>
                    <span x-show="loading" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Avvio in corso…
                    </span>
                </button>
            </div>
        </div>

    </div>{{-- /flex-1 --}}

    {{-- Footer --}}
    <p class="mt-8 text-center text-xs text-gray-400">
        &copy; {{ date('Y') }} {{ config('app.name', 'Social AI') }} &nbsp;&middot;&nbsp;
        <a href="{{ route('logout') }}" class="underline underline-offset-2 hover:text-gray-600"
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
            { value: 'awareness',   emoji: '📣', label: 'Visibilità',  hint: 'Fatti conoscere'       },
            { value: 'engagement',  emoji: '💬', label: 'Engagement',  hint: 'Like, commenti, share' },
            { value: 'lead',        emoji: '🎯', label: 'Lead',        hint: 'Genera contatti'       },
            { value: 'conversion',  emoji: '💰', label: 'Vendite',     hint: 'Spingi all\'acquisto'  },
            { value: 'trust',       emoji: '🤝', label: 'Fiducia',     hint: 'Costruisci autorità'   },
        ],

        init() {},

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
                    const d = await res.json();
                    throw new Error(d.message || 'Errore di salvataggio.');
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
                    const d = await res.json();
                    throw new Error(d.message || 'Errore di salvataggio.');
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
