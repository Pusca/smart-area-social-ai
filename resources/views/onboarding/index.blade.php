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
        html, body { margin:0; padding:0; min-height:100%; }
        body { background:var(--bg); color:var(--text); }
        [x-cloak] { display:none !important }

        /* ── mic ripple ── */
        .mic-ring {
            position:absolute; border-radius:50%;
            border:2px solid var(--accent);
            animation: mic-expand 1.6s ease-out infinite;
            pointer-events:none;
        }
        .mic-ring:nth-child(2) { animation-delay:.5s }
        .mic-ring:nth-child(3) { animation-delay:1s }
        @keyframes mic-expand {
            0%   { width:96px;height:96px;opacity:.7;top:50%;left:50%;transform:translate(-50%,-50%) }
            100% { width:200px;height:200px;opacity:0;top:50%;left:50%;transform:translate(-50%,-50%) }
        }

        /* ── mic button ── */
        .mic-btn {
            width:96px; height:96px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; border:none; outline:none;
            transition:transform .15s, box-shadow .15s;
            position:relative; z-index:2;
        }
        .mic-btn:active { transform:scale(.93) }
        .mic-btn.idle    { background:var(--primary); box-shadow:0 8px 24px rgba(10,45,111,.35) }
        .mic-btn.active  { background:#DC2626; box-shadow:0 8px 28px rgba(220,38,38,.45) }
        .mic-btn.working { background:var(--primary); opacity:.7; pointer-events:none }

        /* ── field chips ── */
        .chip {
            display:inline-flex; align-items:center; gap:.4rem;
            border-radius:99px; font-size:.78rem; font-weight:600;
            padding:.35rem .85rem; transition:all .3s cubic-bezier(.22,1,.36,1);
        }
        .chip-empty  { background:var(--surface-3); color:var(--text-muted) }
        .chip-filled { background:rgba(15,159,110,.13); color:#0F9F6E }
        .chip-filled .chip-dot { background:#0F9F6E }
        .chip-empty  .chip-dot { background:var(--border) }
        .chip-dot { width:6px; height:6px; border-radius:50%; shrink:0 }

        /* ── dropzone ── */
        .dropzone {
            border:2px dashed var(--border); border-radius:1rem;
            transition:border-color .2s, background .2s;
            cursor:pointer;
        }
        .dropzone.over { border-color:var(--accent); background:var(--accent-soft) }

        /* ── hint text bounce-in ── */
        @keyframes hintIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }
        .hint-in { animation:hintIn .25s ease both }

        /* ── CTA shine ── */
        @keyframes cta-shine {
            0%   { background-position:200% center }
            100% { background-position:-200% center }
        }
        .cta-ready {
            background: linear-gradient(90deg, var(--primary) 0%, var(--accent) 50%, var(--primary) 100%);
            background-size:200% auto;
            animation: cta-shine 2.4s linear infinite;
        }
    </style>
</head>

<body x-data="brandVoice()" x-init="init()">

{{-- ── HEADER ── --}}
<header class="flex items-center justify-between px-5 py-4 border-b" style="background:var(--surface);border-color:var(--border);max-width:100%">
    <x-application-logo variant="full" class="h-7 w-auto" />
    <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg transition" style="color:var(--text-muted)">Esci</button>
    </form>
</header>

{{-- ── MAIN ── --}}
<main class="flex flex-col items-center px-4 py-8 sm:py-12" style="min-height:calc(100dvh - 57px)">

    <div class="w-full max-w-lg">

        {{-- Title --}}
        <div class="text-center mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold" style="color:var(--text)">Racconta il tuo brand</h1>
            <p class="mt-2 text-sm leading-relaxed" style="color:var(--text-muted)">
                Premi il microfono e parla liberamente.<br class="hidden sm:block">
                L'AI capisce tutto e compila il profilo in automatico.
            </p>
        </div>

        {{-- ── MIC ZONE ── --}}
        <div class="flex flex-col items-center gap-5 mb-8">

            {{-- Ripple container --}}
            <div class="relative flex items-center justify-center" style="width:200px;height:200px">

                {{-- Rings (only when listening) --}}
                <template x-if="phase === 'listening'">
                    <div>
                        <div class="mic-ring"></div>
                        <div class="mic-ring"></div>
                        <div class="mic-ring"></div>
                    </div>
                </template>

                {{-- Main mic button --}}
                <button class="mic-btn"
                    :class="phase === 'listening' ? 'active' : phase === 'processing' ? 'working' : 'idle'"
                    @click="toggleMic"
                    :title="phase === 'idle' ? 'Inizia a parlare' : 'Ferma'">

                    {{-- idle: mic icon --}}
                    <template x-if="phase === 'idle' || phase === 'error'">
                        <svg width="38" height="38" fill="none" stroke="white" stroke-width="1.75" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                        </svg>
                    </template>

                    {{-- listening: stop square --}}
                    <template x-if="phase === 'listening'">
                        <svg width="32" height="32" fill="white" viewBox="0 0 24 24">
                            <rect x="5" y="5" width="14" height="14" rx="2"/>
                        </svg>
                    </template>

                    {{-- processing: spinner --}}
                    <template x-if="phase === 'processing'">
                        <svg class="animate-spin" width="32" height="32" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="white" stroke-width="4"/>
                            <path class="opacity-75" fill="white" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                    </template>
                </button>
            </div>

            {{-- Status label --}}
            <div class="text-center">
                <p class="text-sm font-semibold hint-in" :key="phase" style="color:var(--text)">
                    <span x-show="phase === 'idle'"    >Tocca per parlare</span>
                    <span x-show="phase === 'listening'" x-cloak>In ascolto…</span>
                    <span x-show="phase === 'processing'" x-cloak>Elaborazione…</span>
                    <span x-show="phase === 'error'" x-cloak style="color:var(--danger)" x-text="errorMsg"></span>
                </p>

                {{-- AI hint / last question --}}
                <p x-show="aiHint && phase === 'idle'" x-cloak
                   class="mt-1.5 text-sm hint-in"
                   style="color:var(--text-muted)"
                   x-text="aiHint"></p>

                {{-- Text fallback input toggle --}}
                <button x-show="phase === 'idle'" @click="showText = !showText"
                    class="mt-3 text-xs underline-offset-2 underline transition" style="color:var(--text-muted)">
                    Preferisci scrivere?
                </button>
            </div>

            {{-- Text fallback --}}
            <div x-show="showText" x-cloak class="w-full hint-in">
                <div class="flex gap-2">
                    <input type="text" x-model="textInput"
                        @keydown.enter="sendText"
                        placeholder="Scrivi qui del tuo brand…"
                        class="flex-1 rounded-xl border px-4 py-2.5 text-sm focus:outline-none transition"
                        style="background:var(--surface);border-color:var(--border);color:var(--text)">
                    <button @click="sendText" :disabled="!textInput.trim() || phase === 'processing'"
                        class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40 transition"
                        style="background:var(--primary)">Invia</button>
                </div>
            </div>
        </div>

        {{-- ── FIELD CHIPS ── --}}
        <div class="rounded-2xl p-4 mb-6" style="background:var(--surface);border:1px solid var(--border)">
            <p class="text-[11px] font-semibold uppercase tracking-wide mb-3" style="color:var(--text-muted)">Informazioni raccolte</p>
            <div class="flex flex-wrap gap-2">
                <template x-for="f in allFields" :key="f.key">
                    <span class="chip" :class="extracted[f.key] ? 'chip-filled' : 'chip-empty'">
                        <span class="chip-dot"></span>
                        <span x-text="f.label"></span>
                        <template x-if="f.required && !extracted[f.key]">
                            <span style="color:var(--danger);font-size:.65rem">*</span>
                        </template>
                    </span>
                </template>
            </div>

            {{-- Progress bar --}}
            <div class="mt-3 h-1.5 rounded-full overflow-hidden" style="background:var(--surface-3)">
                <div class="h-full rounded-full transition-all duration-500"
                     style="background:var(--accent)"
                     :style="'width:' + progressPct + '%'"></div>
            </div>
            <p class="mt-1.5 text-[11px] text-right" style="color:var(--text-muted)"
               x-text="filledRequired + '/6 campi obbligatori'"></p>
        </div>

        {{-- ── IMAGE UPLOAD ── --}}
        <div class="rounded-2xl p-4 mb-6" style="background:var(--surface);border:1px solid var(--border)">
            <div class="flex items-center gap-2 mb-3">
                <p class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted)">Foto brand</p>
                <span class="text-[10px] px-1.5 py-0.5 rounded-md font-semibold"
                      style="background:rgba(220,38,38,.1);color:var(--danger)">necessarie per la generazione</span>
            </div>

            <label class="dropzone flex flex-col items-center justify-center py-5 text-center"
                   :class="dragOver ? 'over' : ''"
                   @dragover.prevent="dragOver=true"
                   @dragleave="dragOver=false"
                   @drop.prevent="handleDrop">
                <svg class="h-8 w-8 mb-2" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-sm font-medium" style="color:var(--text)"
                      x-text="imageFiles.length ? imageFiles.length + ' foto selezionate — clicca per aggiungerne' : 'Clicca o trascina le foto del brand'"></span>
                <span class="text-[11px] mt-1" style="color:var(--text-muted)">PNG, JPG, WebP · max 10 MB ciascuna</span>
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
                <label class="flex items-center gap-3 cursor-pointer rounded-xl p-2.5 transition hover:opacity-80"
                       style="background:var(--surface-2)">
                    <div x-show="!logoPreview" class="h-8 w-8 shrink-0 rounded-lg flex items-center justify-center" style="background:var(--surface-3)">
                        <svg class="h-4 w-4" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </div>
                    <img x-show="logoPreview" :src="logoPreview" class="h-8 w-8 rounded-lg object-contain border" style="border-color:var(--border)">
                    <span class="text-xs flex-1 truncate" style="color:var(--text)"
                          x-text="logoFile ? logoFile.name : 'Carica logo (opzionale)'"></span>
                    <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp,image/svg+xml" @change="handleLogo">
                </label>
            </div>
        </div>

        {{-- ── ERROR ── --}}
        <div x-show="submitError" x-cloak
             class="rounded-xl px-4 py-3 mb-4 text-sm"
             style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);color:var(--danger)"
             x-text="submitError"></div>

        {{-- ── CTA ── --}}
        <button @click="uploadAndComplete"
            :disabled="!canComplete || completing"
            class="w-full flex items-center justify-center gap-2.5 rounded-2xl py-4 text-base font-bold text-white transition disabled:opacity-40 disabled:cursor-not-allowed"
            :class="canComplete ? 'cta-ready' : ''"
            :style="!canComplete ? 'background:var(--surface-3);color:var(--text-muted)' : ''">
            <template x-if="!completing">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span x-text="canComplete ? 'Genera i contenuti demo' : 'Parla del tuo brand per sbloccare'"></span>
                </span>
            </template>
            <template x-if="completing">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Avvio generazione…
                </span>
            </template>
        </button>

        <p class="mt-3 text-center text-[11px]" style="color:var(--text-muted)">
            Puoi anche <a href="#" @click.prevent="skipToManual" class="underline underline-offset-2">compilare manualmente</a>
        </p>

    </div>
</main>

{{-- Hidden manual fallback form (redirect to old-style if user wants) --}}
<form id="manual-redirect" action="{{ route('onboarding') }}" method="GET" class="hidden"></form>

<script>
function brandVoice() {
    return {
        // phase: idle | listening | processing | error
        phase:      'idle',
        errorMsg:   '',
        aiHint:     'Dimmi il nome della tua azienda e cosa fate.',
        showText:   false,
        textInput:  '',
        completing: false,
        submitError:'',
        dragOver:   false,

        // conversation history (hidden from user)
        history: [],

        // extracted data
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
            { key:'business_name', label:'Nome brand',  required:true  },
            { key:'industry',      label:'Settore',      required:true  },
            { key:'services',      label:'Servizi',      required:true  },
            { key:'target',        label:'Audience',     required:true  },
            { key:'default_tone',  label:'Tono',         required:true  },
            { key:'default_goal',  label:'Obiettivo',    required:true  },
            { key:'default_platforms', label:'Piattaforme', required:false },
            { key:'vision',        label:'Visione',      required:false },
            { key:'values',        label:'Valori',       required:false },
            { key:'cta',           label:'CTA',          required:false },
            { key:'notes',         label:'Note',         required:false },
        ],

        // files
        imageFiles:    [],
        imagePreviews: [],
        logoFile:      null,
        logoPreview:   null,

        // speech
        recognition:   null,
        silenceTimer:  null,

        get filledRequired() {
            const req = ['business_name','industry','services','target','default_tone','default_goal'];
            return req.filter(k => this.extracted[k]).length;
        },

        get progressPct() {
            return Math.round((this.filledRequired / 6) * 100);
        },

        get isComplete() {
            return this.filledRequired === 6;
        },

        get canComplete() {
            return this.isComplete && this.imageFiles.length > 0;
        },

        init() {
            // nothing auto-started
        },

        // ── SPEECH ───────────────────────────────────────────────────────────
        buildRecognition() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return null;

            const r         = new SR();
            r.lang          = 'it-IT';
            r.continuous    = false;
            r.interimResults = false;
            r.maxAlternatives = 1;

            r.onresult = (e) => {
                this.clearSilenceTimer();
                let transcript = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    if (e.results[i].isFinal) transcript += e.results[i][0].transcript;
                }
                if (transcript.trim()) {
                    this.phase = 'processing';
                    this.processInput(transcript.trim());
                } else {
                    this.phase = 'idle';
                }
            };

            r.onerror = (e) => {
                this.clearSilenceTimer();
                if (e.error === 'no-speech') {
                    this.phase   = 'idle';
                } else if (e.error === 'not-allowed') {
                    this.phase    = 'error';
                    this.errorMsg = 'Microfono non autorizzato. Usa la tastiera.';
                    this.showText = true;
                } else {
                    this.phase    = 'error';
                    this.errorMsg = 'Errore microfono (' + e.error + '). Riprova.';
                }
            };

            r.onend = () => {
                this.clearSilenceTimer();
                // If still in listening state after end (no result, no error) → go idle
                if (this.phase === 'listening') this.phase = 'idle';
            };

            return r;
        },

        clearSilenceTimer() {
            if (this.silenceTimer) { clearTimeout(this.silenceTimer); this.silenceTimer = null; }
        },

        toggleMic() {
            if (this.phase === 'listening') {
                this.stopListening();
            } else if (this.phase === 'idle' || this.phase === 'error') {
                this.startListening();
            }
        },

        startListening() {
            // Re-create each time to avoid stale state on mobile
            this.recognition = this.buildRecognition();
            if (!this.recognition) {
                this.phase    = 'error';
                this.errorMsg = 'Riconoscimento vocale non supportato. Usa la tastiera.';
                this.showText = true;
                return;
            }

            this.phase    = 'listening';
            this.errorMsg = '';

            try {
                this.recognition.start();

                // Safety timeout: auto-stop after 30s
                this.silenceTimer = setTimeout(() => {
                    if (this.phase === 'listening') this.stopListening();
                }, 30000);
            } catch(e) {
                this.phase    = 'error';
                this.errorMsg = 'Impossibile avviare il microfono. Riprova.';
            }
        },

        stopListening() {
            this.clearSilenceTimer();
            if (this.recognition) {
                try { this.recognition.stop(); } catch(_) {}
                this.recognition = null;
            }
            if (this.phase === 'listening') this.phase = 'idle';
        },

        // ── TEXT FALLBACK ─────────────────────────────────────────────────────
        async sendText() {
            const text = this.textInput.trim();
            if (!text) return;
            this.textInput = '';
            this.phase = 'processing';
            await this.processInput(text);
        },

        // ── CORE: send to AI ──────────────────────────────────────────────────
        async processInput(text) {
            this.history.push({ role: 'user', content: text });

            try {
                const res  = await fetch('{{ route('ai.brand.chat') }}', {
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

                // Save AI reply in history (for context on next turn)
                this.history.push({ role: 'assistant', content: data.reply });

                // Show AI reply as hint text (max 120 chars)
                this.aiHint = data.reply.length > 120
                    ? data.reply.slice(0, 117) + '…'
                    : data.reply;

                // Merge extracted fields (never overwrite with null)
                if (data.extracted) {
                    for (const [k, v] of Object.entries(data.extracted)) {
                        if (v !== null && v !== '') this.extracted[k] = v;
                    }
                }

                this.phase = 'idle';

                // Auto-speak AI reply if TTS available
                this.speak(data.reply);

            } catch(e) {
                this.aiHint = 'Errore. Riprova.';
                this.phase  = 'idle';
            }
        },

        extractedNonNull() {
            return Object.fromEntries(
                Object.entries(this.extracted).filter(([,v]) => v !== null && v !== '')
            );
        },

        // ── TTS (optional, non-blocking) ──────────────────────────────────────
        speak(text) {
            if (!window.speechSynthesis || !text) return;
            try {
                const clean = text.replace(/[*_`#]/g, '').trim();
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
            const files   = Array.from(event.dataTransfer.files)
                .filter(f => f.type.startsWith('image/'));
            if (!files.length) return;
            this.imageFiles    = [...this.imageFiles, ...files];
            this.imagePreviews = [...this.imagePreviews, ...files.map(f => URL.createObjectURL(f))];
        },

        removeImage(index) {
            URL.revokeObjectURL(this.imagePreviews[index]);
            this.imageFiles.splice(index, 1);
            this.imagePreviews.splice(index, 1);
        },

        handleLogo(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.logoFile    = file;
            this.logoPreview = URL.createObjectURL(file);
        },

        // ── COMPLETE ─────────────────────────────────────────────────────────
        async uploadAndComplete() {
            if (!this.canComplete) return;
            this.submitError = '';
            this.completing  = true;

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;

                // 1. Upload assets
                const fd = new FormData();
                if (this.logoFile) fd.append('logo', this.logoFile);
                this.imageFiles.forEach((f, i) => fd.append('images[' + i + ']', f));

                const uploadRes = await fetch('{{ route('onboarding.assets') }}', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers:     { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body:        fd,
                });
                if (!uploadRes.ok) {
                    const d = await uploadRes.json();
                    throw new Error(d.message || 'Errore upload foto.');
                }

                // 2. Complete onboarding
                const completeRes = await fetch('{{ route('ai.brand.onboarding-complete') }}', {
                    method:  'POST',
                    headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json' },
                    body:    JSON.stringify({ extracted: this.extractedNonNull() }),
                });
                const data = await completeRes.json();
                if (!completeRes.ok) throw new Error(data.message || 'Errore generazione.');

                window.location.href = data.redirect_url;

            } catch(e) {
                this.submitError = e.message;
                this.completing  = false;
            }
        },

        skipToManual() {
            // We'll just reload and the old form-based approach would be needed
            // For now, show text input
            this.showText = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
    };
}
</script>
</body>
</html>
