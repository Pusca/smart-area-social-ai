<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Benvenuto — {{ config('app.name', 'Social AI') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak]{display:none!important}
        .step-enter{animation:stepIn .28s cubic-bezier(.22,1,.36,1) both}
        @keyframes stepIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
    </style>
</head>

<body class="min-h-screen bg-slate-50 text-gray-900 antialiased">

<div class="flex min-h-screen">

    {{-- Sidebar decorativa (solo desktop) --}}
    <aside class="relative hidden lg:flex lg:w-[420px] lg:shrink-0 lg:flex-col lg:justify-between overflow-hidden"
           style="background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 55%, #0c4a6e 100%)">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-20 -top-20 h-80 w-80 rounded-full opacity-20"
                 style="background:radial-gradient(circle,#38bdf8 0%,transparent 70%)"></div>
            <div class="absolute bottom-10 right-0 h-60 w-60 rounded-full opacity-10"
                 style="background:radial-gradient(circle,#818cf8 0%,transparent 70%)"></div>
            <svg class="absolute bottom-0 left-0 right-0 opacity-5" viewBox="0 0 400 200" fill="none">
                <path d="M0 120 Q100 60 200 120 Q300 180 400 120 L400 200 L0 200 Z" fill="white"/>
            </svg>
        </div>

        <div class="relative z-10 px-10 pt-12">
            <x-application-logo variant="full" class="h-8 w-auto brightness-200 invert" />
            <div class="mt-16">
                <p class="text-xs font-semibold uppercase tracking-widest text-sky-400">Setup workspace</p>
                <h1 class="mt-3 text-3xl font-bold leading-snug text-white">
                    L'AI che parla<br>come il tuo brand.
                </h1>
                <p class="mt-4 text-sm leading-relaxed text-slate-400">
                    In meno di 2 minuti configuri la voce, l'audience e carichi le immagini.<br>
                    Poi generiamo 3 contenuti demo: 2 immagini + 1 video.
                </p>
            </div>

            {{-- Steps sidebar --}}
            <div class="mt-12 space-y-5">
                <template x-for="(item, i) in [
                    {label:'Brand', desc:'Nome, settore, tono di voce'},
                    {label:'Audience', desc:'A chi parli, cosa offri'},
                    {label:'Immagini', desc:'Logo e foto di riferimento'},
                    {label:'Pronti', desc:'Genera i contenuti demo'}
                ]" :key="i">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold transition-all"
                             :class="i < $store.onbStep
                                ? 'bg-sky-400 text-slate-900'
                                : i === $store.onbStep
                                    ? 'bg-white text-slate-900 ring-2 ring-sky-400/60'
                                    : 'bg-white/10 text-white/40'">
                            <template x-if="i < $store.onbStep">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </template>
                            <template x-if="i >= $store.onbStep">
                                <span x-text="i+1"></span>
                            </template>
                        </div>
                        <div>
                            <p class="text-sm font-semibold transition-colors"
                               :class="i === $store.onbStep ? 'text-white' : i < $store.onbStep ? 'text-sky-400' : 'text-white/30'"
                               x-text="item.label"></p>
                            <p class="text-xs text-white/30" x-text="item.desc"></p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="relative z-10 px-10 pb-8">
            <p class="text-xs text-slate-600">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </div>
    </aside>

    {{-- Contenuto principale --}}
    <main class="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-8"
          x-data="onboarding()"
          x-init="init()">

        {{-- Logo mobile --}}
        <div class="mb-8 lg:hidden">
            <x-application-logo variant="full" class="h-8 w-auto" />
        </div>

        {{-- Progress bar mobile (4 steps) --}}
        <div class="mb-8 w-full max-w-md lg:hidden">
            <div class="flex items-center gap-1">
                <template x-for="(s, i) in ['Brand','Audience','Immagini','Pronti']" :key="i">
                    <div class="flex items-center gap-1" :class="i < 3 ? 'flex-1' : ''">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold transition-all"
                             :class="i < currentStep
                                ? 'border-sky-500 bg-sky-500 text-white'
                                : i === currentStep
                                    ? 'border-sky-500 bg-white text-sky-600'
                                    : 'border-gray-200 bg-white text-gray-400'">
                            <template x-if="i < currentStep">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </template>
                            <template x-if="i >= currentStep"><span x-text="i+1"></span></template>
                        </div>
                        <div x-show="i < 3" class="h-px flex-1 transition-colors" :class="i < currentStep ? 'bg-sky-500' : 'bg-gray-200'"></div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Card form --}}
        <div class="w-full max-w-md">

            {{-- ── STEP 0: Brand ──────────────────────────────────────────── --}}
            <div x-show="currentStep === 0" x-cloak class="step-enter">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-950">Raccontaci il tuo brand</h2>
                    <p class="mt-1 text-sm text-gray-500">Queste tre informazioni guidano ogni contenuto generato.</p>
                </div>

                <form @submit.prevent="saveBrand" class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">
                            Nome brand / azienda <span class="text-red-400">*</span>
                        </label>
                        <input
                            type="text"
                            x-model="form.brand_name"
                            placeholder="es. Studio Legale Rossi, Bakery Milani…"
                            class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm placeholder-gray-400 transition focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                            required maxlength="120" autofocus
                        >
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">
                            Settore / industria <span class="text-red-400">*</span>
                        </label>
                        <input
                            type="text"
                            x-model="form.industry"
                            placeholder="es. Ristorazione, Moda, Tech, Consulenza"
                            class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm placeholder-gray-400 transition focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                            required maxlength="120"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">
                            Tono di voce <span class="text-red-400">*</span>
                        </label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="t in tones" :key="t.value">
                                <button type="button" @click="form.tone = t.value"
                                    class="rounded-xl border px-3 py-2.5 text-left text-sm transition-all"
                                    :class="form.tone === t.value
                                        ? 'border-sky-400 bg-sky-50 shadow-sm ring-1 ring-sky-300/60'
                                        : 'border-gray-200 bg-white hover:border-gray-300'">
                                    <div class="font-semibold" :class="form.tone === t.value ? 'text-sky-700' : 'text-gray-800'" x-text="t.label"></div>
                                    <div class="mt-0.5 text-xs text-gray-400" x-text="t.hint"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                    <button type="submit"
                        class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                        :disabled="loading">
                        <span x-show="!loading">Continua →</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Salvataggio…
                        </span>
                    </button>
                </form>
            </div>

            {{-- ── STEP 1: Audience ───────────────────────────────────────── --}}
            <div x-show="currentStep === 1" x-cloak class="step-enter">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-950">Audience e servizi</h2>
                    <p class="mt-1 text-sm text-gray-500">L'AI costruirà messaggi mirati per il tuo pubblico specifico.</p>
                </div>

                <form @submit.prevent="saveAudience" class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">
                            A chi ti rivolgi <span class="text-red-400">*</span>
                        </label>
                        <textarea x-model="form.target" rows="2"
                            placeholder="es. Professionisti 30-45 anni, famiglie con bambini, appassionati di fitness"
                            class="w-full resize-none rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm placeholder-gray-400 transition focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                            required maxlength="255"></textarea>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">
                            Prodotti / servizi principali <span class="text-red-400">*</span>
                        </label>
                        <textarea x-model="form.services" rows="3"
                            placeholder="es. Pizza napoletana artigianale, catering eventi, consegna in zona"
                            class="w-full resize-none rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm placeholder-gray-400 transition focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                            required maxlength="500"></textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Obiettivo principale</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="g in goals" :key="g.value">
                                <button type="button" @click="form.default_goal = g.value"
                                    class="rounded-xl border px-3 py-2.5 text-left transition-all"
                                    :class="form.default_goal === g.value
                                        ? 'border-sky-400 bg-sky-50 shadow-sm ring-1 ring-sky-300/60'
                                        : 'border-gray-200 bg-white hover:border-gray-300'">
                                    <div class="text-base" x-text="g.emoji"></div>
                                    <div class="mt-0.5 text-xs font-semibold" :class="form.default_goal === g.value ? 'text-sky-700' : 'text-gray-800'" x-text="g.label"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                    <div class="flex gap-3 mt-2">
                        <button type="button" @click="currentStep = 0; syncStep()"
                            class="flex-1 rounded-xl border border-gray-200 bg-white py-3 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50">
                            ← Indietro
                        </button>
                        <button type="submit"
                            class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                            :disabled="loading">
                            <span x-show="!loading">Continua →</span>
                            <span x-show="loading" class="flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Salvataggio…
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── STEP 2: Carica immagini ────────────────────────────────── --}}
            <div x-show="currentStep === 2" x-cloak class="step-enter">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-950">Carica le immagini del brand</h2>
                    <p class="mt-1 text-sm text-gray-500">L'AI userà le tue foto reali per creare contenuti autentici e riconoscibili.</p>
                </div>

                {{-- Foto di riferimento (obbligatorie) --}}
                <div class="mb-4">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">
                        Foto di riferimento <span class="text-red-400">*</span>
                    </label>
                    <p class="mb-2 text-xs text-gray-400">Almeno 1 foto del tuo brand, prodotto o spazio. Più immagini = risultati migliori.</p>

                    <label for="images-input"
                           class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center transition"
                           :class="uploadedFiles.imageFiles.length > 0
                               ? 'border-sky-300 bg-sky-50/60 hover:bg-sky-50'
                               : 'border-gray-200 bg-white hover:border-sky-300 hover:bg-sky-50'">
                        <svg class="mb-2 h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span class="text-sm font-medium text-gray-600"
                              x-text="uploadedFiles.imageFiles.length > 0
                                  ? uploadedFiles.imageFiles.length + ' foto selezionate — clicca per aggiungerne altre'
                                  : 'Clicca per selezionare le foto'"></span>
                        <span class="mt-1 text-xs text-gray-400">PNG, JPG, WebP · max 10 MB ciascuna</span>
                        <input type="file" id="images-input"
                               accept="image/png,image/jpeg,image/webp"
                               multiple class="hidden"
                               @change="handleImagesChange($event)">
                    </label>

                    {{-- Thumbnails --}}
                    <div x-show="uploadedFiles.images.length > 0" class="mt-3 flex flex-wrap gap-2">
                        <template x-for="(url, i) in uploadedFiles.images" :key="i">
                            <div class="group relative">
                                <img :src="url" class="h-16 w-16 rounded-lg object-cover border border-gray-200 shadow-sm">
                                <button type="button" @click="removeImage(i)"
                                    class="absolute -right-1.5 -top-1.5 hidden h-5 w-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white shadow group-hover:flex">
                                    ✕
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Logo (opzionale) --}}
                <div class="mb-5">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700">
                        Logo <span class="text-xs font-normal text-gray-400">(opzionale)</span>
                    </label>
                    <label for="logo-input"
                           class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 transition hover:border-sky-300">
                        <div x-show="!uploadedFiles.logoUrl"
                             class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                        </div>
                        <img x-show="uploadedFiles.logoUrl" :src="uploadedFiles.logoUrl"
                             class="h-10 w-10 rounded-lg object-contain border border-gray-100">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-700 truncate"
                               x-text="uploadedFiles.logoFile ? uploadedFiles.logoFile.name : 'Carica logo'"></p>
                            <p class="text-xs text-gray-400">PNG, JPG, WebP, SVG · max 8 MB</p>
                        </div>
                        <input type="file" id="logo-input"
                               accept="image/png,image/jpeg,image/webp,image/svg+xml"
                               class="hidden"
                               @change="handleLogoChange($event)">
                    </label>
                </div>

                <div x-show="error" x-cloak class="mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                <div class="flex gap-3">
                    <button type="button" @click="currentStep = 1; syncStep()"
                        class="flex-1 rounded-xl border border-gray-200 bg-white py-3 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50">
                        ← Indietro
                    </button>
                    <button type="button" @click="goToStep3"
                        class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                        :disabled="loading">
                        <span x-show="!loading">Continua →</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Upload…
                        </span>
                    </button>
                </div>
            </div>

            {{-- ── STEP 3: Pronti ─────────────────────────────────────────── --}}
            <div x-show="currentStep === 3" x-cloak class="step-enter">
                <div class="mb-6 text-center">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-900 shadow-lg">
                        <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3l14 9-14 9V3z"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-950">Tutto pronto, {{ $userName }}!</h2>
                    <p class="mt-1.5 text-sm text-gray-500">Generiamo 3 contenuti demo basati sul tuo brand.</p>
                </div>

                {{-- Riepilogo brand --}}
                <div class="mb-4 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-gray-400">Brand</p>
                            <p class="truncate text-sm font-semibold text-gray-900" x-text="form.brand_name || '—'"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-gray-400">Settore</p>
                            <p class="truncate text-sm font-semibold text-gray-900" x-text="form.industry || '—'"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-gray-400">Immagini caricate</p>
                            <p class="truncate text-sm font-semibold text-gray-900"
                               x-text="uploadedFiles.imageFiles.length + ' foto' + (uploadedFiles.logoFile ? ' + logo' : '')"></p>
                        </div>
                    </div>
                </div>

                {{-- Cosa verrà generato --}}
                <div class="mb-5 rounded-xl border border-sky-100 bg-sky-50 px-4 py-3">
                    <p class="text-xs font-semibold text-sky-700 mb-1.5">Piano demo — 3 contenuti</p>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 text-xs text-sky-800">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-sky-200 text-[10px] font-bold">2</span>
                            Immagini generate con NanoBanana AI
                        </div>
                        <div class="flex items-center gap-2 text-xs text-sky-800">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-sky-200 text-[10px] font-bold">1</span>
                            Video generato con Google Veo 3.1
                        </div>
                    </div>
                </div>

                {{-- Nota social --}}
                <div class="mb-5 flex items-start gap-3 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-xs text-gray-500">I canali social si collegano da <strong class="text-gray-700">Impostazioni → Integrazioni</strong> dopo il setup.</p>
                </div>

                <div x-show="error" x-cloak class="mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600" x-text="error"></div>

                <div class="flex gap-3">
                    <button type="button" @click="currentStep = 2; syncStep()"
                        class="flex-1 rounded-xl border border-gray-200 bg-white py-3 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50">
                        ← Indietro
                    </button>
                    <button type="button" @click="complete"
                        class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-slate-900 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                        :disabled="loading">
                        <span x-show="!loading" class="flex items-center gap-1.5">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Genera piano demo
                        </span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Avvio generazione…
                        </span>
                    </button>
                </div>
            </div>

        </div>{{-- /max-w-md --}}

        {{-- Footer mobile --}}
        <p class="mt-10 text-center text-xs text-gray-400 lg:hidden">
            &copy; {{ date('Y') }} {{ config('app.name') }} &middot;
            <a href="{{ route('logout') }}" class="underline underline-offset-2 hover:text-gray-600"
               onclick="event.preventDefault();document.getElementById('ob-logout').submit()">Esci</a>
        </p>
        <form id="ob-logout" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>

    </main>
</div>

<script>
Alpine.store('onbStep', 0);

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
        uploadedFiles: {
            logoFile: null,
            logoUrl:  null,
            imageFiles: [],
            images:   [],
        },
        tones: [
            { value: 'professionale', label: 'Professionale', hint: 'Autorevole, diretto'  },
            { value: 'amichevole',    label: 'Amichevole',    hint: 'Caldo, vicino'         },
            { value: 'divertente',    label: 'Divertente',    hint: 'Leggero, ironico'      },
            { value: 'ispirazionale', label: 'Ispirazionale', hint: 'Motivante, visionario' },
            { value: 'informativo',   label: 'Informativo',   hint: 'Chiaro, educativo'     },
            { value: 'lusso',         label: 'Premium',       hint: 'Sofisticato, esclusivo'},
        ],
        goals: [
            { value: 'awareness',  emoji: '📣', label: 'Visibilità'  },
            { value: 'engagement', emoji: '💬', label: 'Engagement'  },
            { value: 'lead',       emoji: '🎯', label: 'Lead'        },
            { value: 'conversion', emoji: '💰', label: 'Vendite'     },
            { value: 'trust',      emoji: '🤝', label: 'Fiducia'     },
        ],

        init() {},

        syncStep() {
            Alpine.store('onbStep', this.currentStep);
        },

        handleLogoChange(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.uploadedFiles.logoFile = file;
            this.uploadedFiles.logoUrl  = URL.createObjectURL(file);
        },

        handleImagesChange(event) {
            const files = Array.from(event.target.files);
            if (!files.length) return;
            this.uploadedFiles.imageFiles = [...this.uploadedFiles.imageFiles, ...files];
            this.uploadedFiles.images     = [...this.uploadedFiles.images, ...files.map(f => URL.createObjectURL(f))];
            event.target.value = '';
        },

        removeImage(index) {
            URL.revokeObjectURL(this.uploadedFiles.images[index]);
            this.uploadedFiles.imageFiles.splice(index, 1);
            this.uploadedFiles.images.splice(index, 1);
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
                    body: JSON.stringify({ brand_name: this.form.brand_name, industry: this.form.industry, tone: this.form.tone }),
                });
                if (!res.ok) { const d = await res.json(); throw new Error(d.message || 'Errore.'); }
                this.currentStep = 1;
                this.syncStep();
            } catch(e) { this.error = e.message; }
            finally { this.loading = false; }
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
                    body: JSON.stringify({ target: this.form.target, services: this.form.services, default_goal: this.form.default_goal }),
                });
                if (!res.ok) { const d = await res.json(); throw new Error(d.message || 'Errore.'); }
                this.currentStep = 2;
                this.syncStep();
            } catch(e) { this.error = e.message; }
            finally { this.loading = false; }
        },

        async goToStep3() {
            this.error = '';
            if (this.uploadedFiles.imageFiles.length === 0) {
                this.error = 'Carica almeno 1 foto per continuare.';
                return;
            }
            this.loading = true;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const fd   = new FormData();
                if (this.uploadedFiles.logoFile) {
                    fd.append('logo', this.uploadedFiles.logoFile);
                }
                this.uploadedFiles.imageFiles.forEach((f, i) => fd.append('images[' + i + ']', f));

                const res = await fetch('{{ route('onboarding.assets') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                if (!res.ok) { const d = await res.json(); throw new Error(d.message || 'Errore upload.'); }
                this.currentStep = 3;
                this.syncStep();
            } catch(e) { this.error = e.message; }
            finally { this.loading = false; }
        },

        async complete() {
            this.error = '';
            this.loading = true;
            try {
                const res = await fetch('{{ route('onboarding.complete') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Errore.');
                window.location.href = data.redirect_url;
            } catch(e) { this.error = e.message; this.loading = false; }
        },
    };
}
</script>

</body>
</html>
