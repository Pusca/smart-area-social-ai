<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Setup Brand — {{ config('app.name', 'Social AI') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('brand/icona-socialai.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html,body { margin:0; padding:0; }
        body { background:var(--bg); color:var(--text); }
        [x-cloak] { display:none !important }

        /* ── mic rings ── */
        .mic-ring {
            position:absolute; border-radius:50%; pointer-events:none;
            border:2px solid var(--accent);
            animation: ring-out 1.8s ease-out infinite;
        }
        .mic-ring:nth-child(2) { animation-delay:.55s }
        .mic-ring:nth-child(3) { animation-delay:1.1s }
        @keyframes ring-out {
            0%   { width:96px;height:96px;opacity:.75;top:50%;left:50%;transform:translate(-50%,-50%) scale(1) }
            100% { width:210px;height:210px;opacity:0;top:50%;left:50%;transform:translate(-50%,-50%) scale(1) }
        }

        /* ── mic button ── */
        .mic-btn {
            position:relative; z-index:2;
            width:96px; height:96px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; border:none; outline:none;
            transition:transform .12s, box-shadow .2s;
        }
        .mic-btn:active { transform:scale(.91) }
        .mic-btn.idle    { background:var(--primary); box-shadow:0 8px 28px rgba(10,45,111,.35) }
        .mic-btn.listen  { background:#DC2626;         box-shadow:0 8px 32px rgba(220,38,38,.45) }
        .mic-btn.process { background:var(--primary);  opacity:.65; pointer-events:none }

        /* ── chip ── */
        .chip {
            display:inline-flex; align-items:center; gap:.35rem;
            border-radius:99px; font-size:.75rem; font-weight:600;
            padding:.3rem .75rem;
            transition:background .35s, color .35s, transform .25s;
        }
        .chip-empty  { background:var(--surface-3); color:var(--text-muted) }
        .chip-filled { background:rgba(15,159,110,.14); color:#0F9F6E; transform:scale(1.04) }
        .chip-dot { width:5px; height:5px; border-radius:50%; flex-shrink:0; transition:background .35s }
        .chip-empty  .chip-dot { background:var(--border) }
        .chip-filled .chip-dot { background:#0F9F6E }

        /* ── dropzone ── */
        .dropzone {
            border:2px dashed var(--border); border-radius:1rem;
            transition:border-color .2s, background .2s; cursor:pointer;
        }
        .dropzone.over { border-color:var(--accent); background:var(--accent-soft) }

        /* ── toast ── */
        @keyframes toastIn  { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
        @keyframes toastOut { from{opacity:1} to{opacity:0} }
        .toast-in  { animation:toastIn  .25s ease both }
        .toast-out { animation:toastOut .3s  ease both }

        /* ── live transcript pill ── */
        .transcript-pill {
            background:var(--surface); border:1px solid var(--border);
            border-radius:.75rem; padding:.5rem 1rem;
            font-size:.8rem; color:var(--text-muted);
            min-height:2.2rem;
            transition:opacity .2s;
        }

        /* ── CTA gradient ── */
        @keyframes cta-shine {
            0%   { background-position:200% center }
            100% { background-position:-200% center }
        }
        .cta-ready {
            background:linear-gradient(90deg,var(--primary) 0%,var(--accent) 50%,var(--primary) 100%);
            background-size:200% auto;
            animation:cta-shine 2.5s linear infinite;
        }
    </style>
</head>

<body x-data="brandVoice()" x-init="init()">

{{-- HEADER --}}
<header class="flex items-center justify-between px-5 py-3.5 border-b" style="background:var(--surface);border-color:var(--border)">
    <x-application-logo variant="full" class="h-7 w-auto" />
    <form action="{{ route('logout') }}" method="POST">@csrf
        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg" style="color:var(--text-muted)">Esci</button>
    </form>
</header>

{{-- MAIN --}}
<main class="flex flex-col items-center px-4 py-8 sm:py-12" style="min-height:calc(100dvh - 57px)">
<div class="w-full max-w-lg space-y-6">

    {{-- Title --}}
    <div class="text-center">
        <h1 class="text-2xl sm:text-3xl font-bold" style="color:var(--text)">Raccontaci il tuo brand</h1>
        <p class="mt-2 text-sm leading-relaxed" style="color:var(--text-muted)">
            Premi il microfono e parla liberamente.<br>
            L'AI capisce e compila il profilo in automatico.
        </p>
    </div>

    {{-- ── MIC + FEEDBACK ── --}}
    <div class="rounded-2xl p-6 text-center space-y-4" style="background:var(--surface);border:1px solid var(--border)">

        {{-- Ripple container --}}
        <div class="relative flex items-center justify-center mx-auto" style="width:200px;height:200px">

            <template x-if="phase === 'listening'">
                <div>
                    <div class="mic-ring"></div>
                    <div class="mic-ring"></div>
                    <div class="mic-ring"></div>
                </div>
            </template>

            {{-- Mic button --}}
            <button class="mic-btn"
                :class="phase === 'listening' ? 'listen' : phase === 'processing' ? 'process' : 'idle'"
                @click="toggleMic"
                :aria-label="phase === 'listening' ? 'Ferma registrazione' : 'Inizia a parlare'">

                <template x-if="phase === 'idle' || phase === 'error'">
                    <svg width="38" height="38" fill="none" stroke="white" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                    </svg>
                </template>

                <template x-if="phase === 'listening'">
                    <svg width="30" height="30" fill="white" viewBox="0 0 24 24">
                        <rect x="5" y="5" width="14" height="14" rx="2.5"/>
                    </svg>
                </template>

                <template x-if="phase === 'processing'">
                    <svg class="animate-spin" width="32" height="32" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="white" stroke-width="4"/>
                        <path class="opacity-75" fill="white" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </template>
            </button>
        </div>

        {{-- Status label --}}
        <div class="space-y-1.5">
            <p class="text-sm font-semibold" style="color:var(--text)">
                <span x-show="phase === 'idle'">Tocca per parlare</span>
                <span x-show="phase === 'listening'" x-cloak style="color:#DC2626">In ascolto — tocca per fermare</span>
                <span x-show="phase === 'processing'" x-cloak style="color:var(--primary)">Elaboro ciò che hai detto…</span>
                <span x-show="phase === 'error'" x-cloak style="color:var(--danger)" x-text="errorMsg"></span>
            </p>

            {{-- Live transcript (what mic is hearing) --}}
            <div x-show="liveTranscript || phase === 'listening'" x-cloak
                 class="transcript-pill mx-auto"
                 style="max-width:340px;min-height:2.2rem">
                <span x-show="liveTranscript" x-text="liveTranscript" class="italic"></span>
                <span x-show="!liveTranscript && phase === 'listening'" class="opacity-50">Sto ascoltando…</span>
            </div>

            {{-- AI question / hint --}}
            <p x-show="aiHint && phase !== 'processing'" x-cloak
               class="text-sm px-2"
               style="color:var(--primary);font-weight:500"
               x-text="aiHint"></p>
        </div>

        {{-- Raccolto toast --}}
        <div x-show="extractedToast" x-cloak
             class="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium toast-in"
             style="background:rgba(15,159,110,.1);color:#0F9F6E;border:1px solid rgba(15,159,110,.2)">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
            </svg>
            <span x-text="extractedToast"></span>
        </div>

        {{-- Fallback tastiera --}}
        <div class="pt-1">
            <button @click="showText = !showText"
                class="text-xs underline underline-offset-2" style="color:var(--text-muted)">
                <span x-text="showText ? 'Nascondi tastiera' : 'Preferisci scrivere?'"></span>
            </button>
            <div x-show="showText" x-cloak class="mt-3 flex gap-2">
                <input type="text" x-model="textInput"
                    @keydown.enter="sendText"
                    placeholder="Scrivi qui del tuo brand…"
                    :disabled="phase === 'processing'"
                    class="flex-1 rounded-xl border px-3.5 py-2.5 text-sm focus:outline-none"
                    style="background:var(--surface-2);border-color:var(--border);color:var(--text)">
                <button @click="sendText" :disabled="!textInput.trim() || phase === 'processing'"
                    class="rounded-xl px-4 text-sm font-semibold text-white disabled:opacity-40"
                    style="background:var(--primary)">Invia</button>
            </div>
        </div>
    </div>

    {{-- ── CAMPO CHIPS ── --}}
    <div class="rounded-2xl p-4" style="background:var(--surface);border:1px solid var(--border)">
        <div class="flex items-center justify-between mb-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted)">
                Informazioni raccolte
            </p>
            <span class="text-[11px] font-semibold" style="color:var(--text-muted)"
                  x-text="filledRequired + '/6 obbligatori'"></span>
        </div>

        <div class="flex flex-wrap gap-2 mb-3">
            <template x-for="f in allFields" :key="f.key">
                <span class="chip" :class="extracted[f.key] ? 'chip-filled' : 'chip-empty'">
                    <span class="chip-dot"></span>
                    <span x-text="f.label"></span>
                    <template x-if="f.required && !extracted[f.key]">
                        <span class="text-[9px]" style="color:var(--danger)">●</span>
                    </template>
                    <template x-if="extracted[f.key]">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                        </svg>
                    </template>
                </span>
            </template>
        </div>

        {{-- Progress --}}
        <div class="h-1.5 rounded-full overflow-hidden" style="background:var(--surface-3)">
            <div class="h-full rounded-full transition-all duration-700"
                 style="background:var(--accent)"
                 :style="'width:' + progressPct + '%'"></div>
        </div>

        {{-- Extracted values preview --}}
        <div x-show="filledRequired > 0" x-cloak class="mt-3 pt-3 border-t space-y-1" style="border-color:var(--border)">
            <template x-for="f in allFields.filter(f => extracted[f.key])" :key="f.key">
                <div class="flex items-center gap-2 text-xs">
                    <span class="font-semibold shrink-0 w-20 truncate" style="color:var(--text-muted)" x-text="f.label + ':'"></span>
                    <span class="truncate" style="color:var(--text)"
                          x-text="Array.isArray(extracted[f.key]) ? extracted[f.key].join(', ') : extracted[f.key]"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── IMMAGINI ── --}}
    <div class="rounded-2xl p-4" style="background:var(--surface);border:1px solid var(--border)">
        <div class="flex items-center gap-2 mb-2">
            <p class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted)">Foto brand</p>
            <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold"
                  style="background:rgba(220,38,38,.1);color:var(--danger)">per generare i contenuti</span>
        </div>
        <p class="text-xs mb-3" style="color:var(--text-muted)">Almeno 1 foto — più ne carichi, migliori i risultati.</p>

        <label class="dropzone flex flex-col items-center py-5 text-center"
               :class="dragOver ? 'over' : ''"
               @dragover.prevent="dragOver=true"
               @dragleave="dragOver=false"
               @drop.prevent="handleDrop">
            <svg class="h-7 w-7 mb-2" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span class="text-sm font-medium" style="color:var(--text)"
                  x-text="imageFiles.length ? imageFiles.length + ' foto selezionate' : 'Clicca o trascina le foto'"></span>
            <span class="text-[11px] mt-0.5" style="color:var(--text-muted)">PNG, JPG, WebP · max 10 MB</span>
            <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" multiple @change="handleImages">
        </label>

        <div x-show="imagePreviews.length > 0" class="mt-3 flex flex-wrap gap-2">
            <template x-for="(url, i) in imagePreviews" :key="i">
                <div class="group relative">
                    <img :src="url" class="h-14 w-14 rounded-xl object-cover border" style="border-color:var(--border)">
                    <button type="button" @click="removeImage(i)"
                        class="absolute -right-1 -top-1 hidden h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white group-hover:flex"
                        style="background:var(--danger)">✕</button>
                </div>
            </template>
        </div>

        {{-- Logo --}}
        <div class="mt-3 pt-3 border-t" style="border-color:var(--border)">
            <label class="flex items-center gap-3 cursor-pointer rounded-xl px-3 py-2 transition hover:opacity-80"
                   style="background:var(--surface-2)">
                <div x-show="!logoPreview" class="h-8 w-8 shrink-0 rounded-lg flex items-center justify-center" style="background:var(--surface-3)">
                    <svg class="h-4 w-4" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                </div>
                <img x-show="logoPreview" :src="logoPreview" class="h-8 w-8 rounded-lg object-contain border" style="border-color:var(--border)">
                <span class="text-xs" style="color:var(--text-muted)"
                      x-text="logoFile ? logoFile.name : 'Carica logo (opzionale)'"></span>
                <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp,image/svg+xml" @change="handleLogo">
            </label>
        </div>
    </div>

    {{-- ── ERRORE SUBMIT ── --}}
    <div x-show="submitError" x-cloak
         class="rounded-xl px-4 py-3 text-sm"
         style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);color:var(--danger)"
         x-text="submitError"></div>

    {{-- ── CTA ── --}}
    <div class="pb-8">
        <button @click="uploadAndComplete"
            :disabled="!canComplete || completing"
            class="w-full flex items-center justify-center gap-2.5 rounded-2xl py-4 text-base font-bold text-white transition"
            :class="canComplete && !completing ? 'cta-ready' : 'opacity-40 cursor-not-allowed'"
            :style="canComplete ? '' : 'background:var(--surface-3);color:var(--text-muted)'">

            <template x-if="!completing">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span x-text="canComplete ? 'Genera i contenuti demo' : (filledRequired < 6 ? 'Parla per raccogliere i dati (' + filledRequired + '/6)' : 'Carica almeno 1 foto per continuare')"></span>
                </span>
            </template>

            <template x-if="completing">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Avvio generazione…
                </span>
            </template>
        </button>
    </div>

</div>
</main>

<script>
function brandVoice() {
    return {
        phase:          'idle',   // idle | listening | processing | error
        errorMsg:       '',
        aiHint:         'Dimmi: come si chiama il tuo brand e cosa fate?',
        liveTranscript: '',
        extractedToast: '',
        toastTimer:     null,
        showText:       false,
        textInput:      '',
        completing:     false,
        submitError:    '',
        dragOver:       false,

        history: [],

        extracted: {
            business_name:     null,
            industry:          null,
            services:          null,
            target:            null,
            default_tone:      null,
            default_goal:      null,
            default_platforms: null,
            vision:            null,
            values:            null,
            cta:               null,
            notes:             null,
        },

        allFields: [
            { key:'business_name', label:'Nome brand',   required:true  },
            { key:'industry',      label:'Settore',       required:true  },
            { key:'services',      label:'Servizi',       required:true  },
            { key:'target',        label:'Audience',      required:true  },
            { key:'default_tone',  label:'Tono voce',     required:true  },
            { key:'default_goal',  label:'Obiettivo',     required:true  },
            { key:'default_platforms', label:'Piattaforme', required:false },
            { key:'vision',        label:'Visione',       required:false },
            { key:'values',        label:'Valori',        required:false },
            { key:'cta',           label:'CTA',           required:false },
            { key:'notes',         label:'Note',          required:false },
        ],

        imageFiles:    [],
        imagePreviews: [],
        logoFile:      null,
        logoPreview:   null,

        recognition:   null,
        silenceTimer:  null,
        pendingTranscript: '',

        get filledRequired() {
            const req = ['business_name','industry','services','target','default_tone','default_goal'];
            return req.filter(k => this.extracted[k]).length;
        },
        get progressPct() {
            return Math.round((this.filledRequired / 6) * 100);
        },
        get isComplete() { return this.filledRequired === 6; },
        get canComplete() { return this.isComplete && this.imageFiles.length > 0; },

        init() { /* no-op */ },

        // ── SPEECH ────────────────────────────────────────────────────────────
        buildRecognition() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return null;

            const r           = new SR();
            r.lang            = 'it-IT';
            r.continuous      = false;
            r.interimResults  = true;   // cattura anche risultati parziali
            r.maxAlternatives = 1;

            r.onresult = (e) => {
                let interim = '';
                let final   = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const text = e.results[i][0].transcript;
                    if (e.results[i].isFinal) {
                        final  += text;
                    } else {
                        interim += text;
                    }
                }

                // Mostra in tempo reale cosa sente
                if (final) {
                    this.pendingTranscript = final;
                    this.liveTranscript    = final;
                } else if (interim) {
                    this.liveTranscript = interim;
                    // Salva interim come fallback
                    if (!this.pendingTranscript) this.pendingTranscript = interim;
                }

                // Se abbiamo un risultato finale, avvia elaborazione
                if (final.trim()) {
                    this.clearSilenceTimer();
                    this.stopRecognition();
                    const toProcess = final.trim();
                    this.pendingTranscript = '';
                    this.liveTranscript    = toProcess;
                    this.phase = 'processing';
                    setTimeout(() => { this.liveTranscript = ''; }, 600);
                    this.processInput(toProcess);
                }
            };

            r.onerror = (e) => {
                this.clearSilenceTimer();
                if (e.error === 'no-speech') {
                    // nessun suono rilevato, torna a idle silenziosamente
                    this.liveTranscript    = '';
                    this.pendingTranscript = '';
                    this.phase = 'idle';
                } else if (e.error === 'not-allowed') {
                    this.phase    = 'error';
                    this.errorMsg = 'Microfono non autorizzato. Usa la tastiera.';
                    this.showText = true;
                } else {
                    // audio-capture, network, etc.
                    this.liveTranscript    = '';
                    this.pendingTranscript = '';
                    this.phase = 'idle';
                }
            };

            r.onend = () => {
                this.clearSilenceTimer();
                const pending = this.pendingTranscript.trim();

                if (pending && this.phase !== 'processing') {
                    // Avevamo un transcript parziale — usalo
                    this.pendingTranscript = '';
                    this.liveTranscript    = pending;
                    this.phase = 'processing';
                    setTimeout(() => { this.liveTranscript = ''; }, 600);
                    this.processInput(pending);
                } else if (this.phase === 'listening') {
                    this.liveTranscript    = '';
                    this.pendingTranscript = '';
                    this.phase = 'idle';
                }
            };

            return r;
        },

        clearSilenceTimer() {
            if (this.silenceTimer) { clearTimeout(this.silenceTimer); this.silenceTimer = null; }
        },

        stopRecognition() {
            if (this.recognition) { try { this.recognition.stop(); } catch(_){} this.recognition = null; }
        },

        toggleMic() {
            if (this.phase === 'listening') {
                this.clearSilenceTimer();
                this.stopRecognition();
                // Se avevamo un pending, elaboralo
                const pending = this.pendingTranscript.trim();
                if (pending) {
                    this.pendingTranscript = '';
                    this.phase = 'processing';
                    this.processInput(pending);
                } else {
                    this.liveTranscript = '';
                    this.phase = 'idle';
                }
                return;
            }
            if (this.phase === 'idle' || this.phase === 'error') {
                this.startMic();
            }
        },

        startMic() {
            this.recognition = this.buildRecognition();
            if (!this.recognition) {
                this.phase    = 'error';
                this.errorMsg = 'Riconoscimento vocale non supportato in questo browser. Usa la tastiera.';
                this.showText = true;
                return;
            }
            this.phase             = 'listening';
            this.errorMsg          = '';
            this.liveTranscript    = '';
            this.pendingTranscript = '';

            try {
                this.recognition.start();
                // Safety timeout 30s
                this.silenceTimer = setTimeout(() => {
                    if (this.phase === 'listening') this.toggleMic();
                }, 30000);
            } catch(e) {
                this.phase = 'idle';
            }
        },

        // ── TASTIERA ──────────────────────────────────────────────────────────
        async sendText() {
            const text = this.textInput.trim();
            if (!text || this.phase === 'processing') return;
            this.textInput = '';
            this.phase = 'processing';
            await this.processInput(text);
        },

        // ── CORE API ──────────────────────────────────────────────────────────
        async processInput(text) {
            this.history.push({ role: 'user', content: text });
            try {
                const res = await fetch('{{ route('ai.brand.chat') }}', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify({
                        messages:         this.history,
                        existing_profile: this.extractedNonNull(),
                    }),
                });

                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Errore AI');

                // Salva risposta AI nello storico
                if (data.reply) {
                    this.history.push({ role: 'assistant', content: data.reply });
                    this.aiHint = data.reply.length > 100 ? data.reply.slice(0, 97) + '…' : data.reply;
                    this.speak(data.reply);
                }

                // Merge campi estratti
                if (data.extracted && typeof data.extracted === 'object') {
                    const newFields = [];
                    for (const [k, v] of Object.entries(data.extracted)) {
                        if (v !== null && v !== '' && v !== undefined) {
                            const wasEmpty = !this.extracted[k];
                            this.extracted[k] = v;
                            if (wasEmpty) {
                                const field = this.allFields.find(f => f.key === k);
                                if (field) newFields.push(field.label);
                            }
                        }
                    }
                    if (newFields.length) this.showExtractedToast(newFields);
                }

            } catch(e) {
                this.aiHint = 'Si è verificato un errore. Riprova a parlare.';
            } finally {
                this.phase = 'idle';
            }
        },

        showExtractedToast(fields) {
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.extractedToast = 'Raccolto: ' + fields.join(', ');
            this.toastTimer = setTimeout(() => { this.extractedToast = ''; }, 3500);
        },

        extractedNonNull() {
            return Object.fromEntries(
                Object.entries(this.extracted).filter(([,v]) => v !== null && v !== '')
            );
        },

        // ── TTS ───────────────────────────────────────────────────────────────
        speak(text) {
            if (!window.speechSynthesis) return;
            try {
                const clean = text.replace(/[*_`#\[\]]/g, '').slice(0, 200);
                const utt   = new SpeechSynthesisUtterance(clean);
                utt.lang    = 'it-IT';
                utt.rate    = 1.05;
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(utt);
            } catch(_) {}
        },

        // ── FILES ─────────────────────────────────────────────────────────────
        handleImages(event) {
            const files = Array.from(event.target.files);
            if (!files.length) return;
            this.imageFiles    = [...this.imageFiles, ...files];
            this.imagePreviews = [...this.imagePreviews, ...files.map(f => URL.createObjectURL(f))];
            event.target.value = '';
        },

        handleDrop(event) {
            this.dragOver = false;
            const files   = Array.from(event.dataTransfer.files).filter(f => f.type.startsWith('image/'));
            if (!files.length) return;
            this.imageFiles    = [...this.imageFiles, ...files];
            this.imagePreviews = [...this.imagePreviews, ...files.map(f => URL.createObjectURL(f))];
        },

        removeImage(i) {
            URL.revokeObjectURL(this.imagePreviews[i]);
            this.imageFiles.splice(i, 1);
            this.imagePreviews.splice(i, 1);
        },

        handleLogo(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.logoFile    = file;
            this.logoPreview = URL.createObjectURL(file);
        },

        // ── COMPLETA ─────────────────────────────────────────────────────────
        async uploadAndComplete() {
            if (!this.canComplete) return;
            this.submitError = '';
            this.completing  = true;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const fd   = new FormData();
                if (this.logoFile) fd.append('logo', this.logoFile);
                this.imageFiles.forEach((f, i) => fd.append('images[' + i + ']', f));

                const up = await fetch('{{ route('onboarding.assets') }}', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                if (!up.ok) { const d = await up.json(); throw new Error(d.message || 'Errore upload'); }

                const comp = await fetch('{{ route('ai.brand.onboarding-complete') }}', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json' },
                    body: JSON.stringify({ extracted: this.extractedNonNull() }),
                });
                const data = await comp.json();
                if (!comp.ok) throw new Error(data.message || 'Errore generazione');

                window.location.href = data.redirect_url;
            } catch(e) {
                this.submitError = e.message;
                this.completing  = false;
            }
        },
    };
}
</script>
</body>
</html>
