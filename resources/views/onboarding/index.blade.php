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
        html,body{margin:0;padding:0}
        body{background:var(--bg);color:var(--text)}
        [x-cloak]{display:none!important}

        .mic-ring{position:absolute;border-radius:50%;pointer-events:none;
            border:2px solid var(--accent);
            animation:ring-out 1.8s ease-out infinite}
        .mic-ring:nth-child(2){animation-delay:.55s}
        .mic-ring:nth-child(3){animation-delay:1.1s}
        @keyframes ring-out{
            0%{width:96px;height:96px;opacity:.7;top:50%;left:50%;transform:translate(-50%,-50%)}
            100%{width:200px;height:200px;opacity:0;top:50%;left:50%;transform:translate(-50%,-50%)}}

        .mic-btn{position:relative;z-index:2;width:96px;height:96px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            cursor:pointer;border:none;outline:none;
            transition:transform .12s,box-shadow .2s}
        .mic-btn:active{transform:scale(.91)}
        .mic-btn.idle{background:var(--primary);box-shadow:0 8px 28px rgba(10,45,111,.35)}
        .mic-btn.listen{background:#DC2626;box-shadow:0 8px 32px rgba(220,38,38,.45)}
        .mic-btn.process{background:var(--primary);opacity:.6;pointer-events:none}

        .chip{display:inline-flex;align-items:center;gap:.35rem;border-radius:99px;
            font-size:.75rem;font-weight:600;padding:.3rem .75rem;
            transition:all .4s cubic-bezier(.22,1,.36,1)}
        .chip-empty{background:var(--surface-3);color:var(--text-muted)}
        .chip-filled{background:rgba(15,159,110,.15);color:#0F9F6E;transform:scale(1.05)}
        .chip-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;transition:background .4s}
        .chip-empty .chip-dot{background:var(--border)}
        .chip-filled .chip-dot{background:#0F9F6E}

        .dropzone{border:2px dashed var(--border);border-radius:1rem;
            transition:border-color .2s,background .2s;cursor:pointer}
        .dropzone.over{border-color:var(--accent);background:var(--accent-soft)}

        @keyframes slideUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
        .slide-up{animation:slideUp .2s ease both}

        @keyframes cta-shine{0%{background-position:200% center}100%{background-position:-200% center}}
        .cta-ready{background:linear-gradient(90deg,var(--primary) 0%,var(--accent) 50%,var(--primary) 100%);
            background-size:200% auto;animation:cta-shine 2.5s linear infinite}
    </style>
</head>
<body x-data="brandVoice()" x-init="init()">

{{-- HEADER --}}
<header class="flex items-center justify-between px-5 py-3.5 border-b" style="background:var(--surface);border-color:var(--border)">
    <x-application-logo variant="full" class="h-7 w-auto"/>
    <form action="{{ route('logout') }}" method="POST">@csrf
        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg" style="color:var(--text-muted)">Esci</button>
    </form>
</header>

<main class="flex flex-col items-center px-4 py-8 sm:py-10" style="min-height:calc(100dvh - 57px)">
<div class="w-full max-w-lg space-y-5">

    {{-- Title --}}
    <div class="text-center">
        <h1 class="text-2xl sm:text-3xl font-bold" style="color:var(--text)">Raccontaci il tuo brand</h1>
        <p class="mt-2 text-sm leading-relaxed" style="color:var(--text-muted)">
            Premi il microfono e parla liberamente — l'AI raccoglie i dati automaticamente.
        </p>
    </div>

    {{-- ── ERRORE PROMINENTE ── --}}
    <div x-show="apiError" x-cloak class="rounded-2xl px-4 py-3 slide-up"
         style="background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);color:var(--danger)">
        <p class="text-sm font-semibold mb-0.5">Errore di comunicazione</p>
        <p class="text-xs" x-text="apiError"></p>
        <button @click="apiError=''" class="mt-2 text-xs underline">Chiudi</button>
    </div>

    {{-- ── MIC PANEL ── --}}
    <div class="rounded-2xl p-5 text-center" style="background:var(--surface);border:1px solid var(--border)">

        {{-- Mic area --}}
        <div class="relative flex items-center justify-center mx-auto mb-4" style="width:200px;height:200px">
            <template x-if="phase==='listening'">
                <div><div class="mic-ring"></div><div class="mic-ring"></div><div class="mic-ring"></div></div>
            </template>
            <button class="mic-btn" :class="phase==='listening'?'listen':phase==='processing'?'process':'idle'"
                @click="toggleMic" :aria-label="phase==='listening'?'Ferma':'Parla'">
                <template x-if="phase==='idle'||phase==='done'">
                    <svg width="38" height="38" fill="none" stroke="white" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                    </svg>
                </template>
                <template x-if="phase==='listening'">
                    <svg width="30" height="30" fill="white" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2.5"/></svg>
                </template>
                <template x-if="phase==='processing'">
                    <svg class="animate-spin" width="32" height="32" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="white" stroke-width="4"/>
                        <path class="opacity-75" fill="white" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </template>
            </button>
        </div>

        {{-- Status --}}
        <p class="text-sm font-semibold mb-2" style="color:var(--text)">
            <span x-show="phase==='idle'">Tocca per parlare</span>
            <span x-show="phase==='listening'" x-cloak style="color:#DC2626">In ascolto — tocca per fermare</span>
            <span x-show="phase==='processing'" x-cloak style="color:var(--primary)">Elaboro…</span>
        </p>

        {{-- Live transcript --}}
        <div x-show="liveTranscript" x-cloak
             class="rounded-xl px-4 py-2.5 mb-3 text-sm italic text-center slide-up"
             style="background:var(--surface-2);color:var(--text);border:1px solid var(--border)"
             x-text="'Hai detto: ' + liveTranscript"></div>

        {{-- AI question --}}
        <div x-show="aiHint" x-cloak
             class="rounded-xl px-4 py-2.5 mb-3 text-sm text-center slide-up"
             style="background:var(--accent-soft);color:var(--primary);border:1px solid rgba(59,200,255,.3);font-weight:500"
             x-text="aiHint"></div>

        {{-- Raccolto toast --}}
        <div x-show="toast" x-cloak
             class="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 mb-2 text-sm font-medium slide-up"
             style="background:rgba(15,159,110,.1);color:#0F9F6E;border:1px solid rgba(15,159,110,.2)">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
            </svg>
            <span x-text="toast"></span>
        </div>

        {{-- Tastiera fallback --}}
        <div class="pt-2 border-t" style="border-color:var(--border)">
            <button @click="showText=!showText" class="text-xs underline underline-offset-2" style="color:var(--text-muted)">
                <span x-text="showText?'Nascondi tastiera':'Non riesci col microfono? Scrivi qui'"></span>
            </button>
            <div x-show="showText" x-cloak class="mt-3 flex gap-2">
                <input type="text" x-model="textInput" @keydown.enter="sendText"
                    :disabled="phase==='processing'"
                    placeholder="Scrivi del tuo brand…"
                    class="flex-1 rounded-xl border px-3.5 py-2.5 text-sm focus:outline-none"
                    style="background:var(--surface-2);border-color:var(--border);color:var(--text)">
                <button @click="sendText" :disabled="!textInput.trim()||phase==='processing'"
                    class="rounded-xl px-4 text-sm font-semibold text-white disabled:opacity-40"
                    style="background:var(--primary)">Invia</button>
            </div>
        </div>
    </div>

    {{-- ── DATI RACCOLTI ── --}}
    <div class="rounded-2xl p-4" style="background:var(--surface);border:1px solid var(--border)">
        <div class="flex items-center justify-between mb-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted)">Dati raccolti</p>
            <span class="text-[11px] font-semibold" style="color:var(--text-muted)" x-text="filledRequired+'/6 obbligatori'"></span>
        </div>

        <div class="flex flex-wrap gap-2 mb-3">
            <template x-for="f in allFields" :key="f.key">
                <span class="chip" :class="extracted[f.key]?'chip-filled':'chip-empty'">
                    <span class="chip-dot"></span>
                    <span x-text="f.label"></span>
                    <template x-if="f.required&&!extracted[f.key]">
                        <span class="opacity-50 text-[9px]">●</span>
                    </template>
                    <template x-if="extracted[f.key]">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                        </svg>
                    </template>
                </span>
            </template>
        </div>

        <div class="h-1.5 rounded-full overflow-hidden mb-3" style="background:var(--surface-3)">
            <div class="h-full rounded-full transition-all duration-700" style="background:var(--accent)"
                 :style="'width:'+progressPct+'%'"></div>
        </div>

        {{-- Valori estratti --}}
        <div x-show="filledRequired>0" x-cloak class="space-y-1.5 pt-2 border-t" style="border-color:var(--border)">
            <template x-for="f in allFields.filter(f=>extracted[f.key])" :key="f.key">
                <div class="flex items-start gap-2 text-xs">
                    <span class="font-semibold shrink-0" style="color:var(--text-muted);min-width:5.5rem" x-text="f.label+':'"></span>
                    <span class="truncate" style="color:var(--text)"
                          x-text="Array.isArray(extracted[f.key])?extracted[f.key].join(', '):String(extracted[f.key])"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── IMMAGINI ── --}}
    <div class="rounded-2xl p-4" style="background:var(--surface);border:1px solid var(--border)">
        <div class="flex items-center gap-2 mb-2">
            <p class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted)">Foto brand</p>
            <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold"
                  style="background:rgba(220,38,38,.1);color:var(--danger)">richieste per la generazione</span>
        </div>

        <label class="dropzone flex flex-col items-center py-5 text-center"
               :class="dragOver?'over':''"
               @dragover.prevent="dragOver=true" @dragleave="dragOver=false" @drop.prevent="handleDrop">
            <svg class="h-7 w-7 mb-2" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span class="text-sm font-medium" style="color:var(--text)"
                  x-text="imageFiles.length?imageFiles.length+' foto — clicca per aggiungerne':'Clicca o trascina le foto'"></span>
            <span class="text-[11px] mt-0.5" style="color:var(--text-muted)">PNG, JPG, WebP · max 10 MB</span>
            <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" multiple @change="handleImages">
        </label>

        <div x-show="imagePreviews.length" class="mt-3 flex flex-wrap gap-2">
            <template x-for="(url,i) in imagePreviews" :key="i">
                <div class="group relative">
                    <img :src="url" class="h-14 w-14 rounded-xl object-cover border" style="border-color:var(--border)">
                    <button type="button" @click="removeImage(i)"
                        class="absolute -right-1 -top-1 hidden h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white group-hover:flex"
                        style="background:var(--danger)">✕</button>
                </div>
            </template>
        </div>

        <div class="mt-3 pt-3 border-t" style="border-color:var(--border)">
            <label class="flex items-center gap-3 cursor-pointer rounded-xl px-3 py-2 hover:opacity-80"
                   style="background:var(--surface-2)">
                <div x-show="!logoPreview" class="h-8 w-8 shrink-0 rounded-lg flex items-center justify-center" style="background:var(--surface-3)">
                    <svg class="h-4 w-4" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                </div>
                <img x-show="logoPreview" :src="logoPreview" class="h-8 w-8 rounded-lg object-contain border" style="border-color:var(--border)">
                <span class="text-xs" style="color:var(--text-muted)"
                      x-text="logoFile?logoFile.name:'Carica logo (opzionale)'"></span>
                <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp,image/svg+xml" @change="handleLogo">
            </label>
        </div>
    </div>

    {{-- ── ERRORE SUBMIT ── --}}
    <div x-show="submitError" x-cloak class="rounded-xl px-4 py-3 text-sm"
         style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);color:var(--danger)"
         x-text="submitError"></div>

    {{-- ── CTA ── --}}
    <div class="pb-8">
        <button @click="uploadAndComplete" :disabled="!canComplete||completing"
            class="w-full flex items-center justify-center gap-2.5 rounded-2xl py-4 text-base font-bold text-white transition"
            :class="canComplete&&!completing?'cta-ready':''"
            :style="canComplete?'':'background:var(--surface-3);color:var(--text-muted);cursor:not-allowed'">
            <template x-if="!completing">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span x-text="ctaLabel"></span>
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
        phase:          'idle',
        liveTranscript: '',
        aiHint:         'Dimmi: come si chiama il tuo brand e cosa fate?',
        toast:          '',
        toastTimer:     null,
        showText:       false,
        textInput:      '',
        completing:     false,
        submitError:    '',
        apiError:       '',
        dragOver:       false,

        history: [],

        extracted: {
            business_name:null, industry:null, services:null, target:null,
            default_tone:null, default_goal:null, default_platforms:null,
            vision:null, values:null, cta:null, notes:null,
        },

        allFields: [
            {key:'business_name', label:'Nome brand',   required:true},
            {key:'industry',      label:'Settore',       required:true},
            {key:'services',      label:'Servizi',       required:true},
            {key:'target',        label:'Audience',      required:true},
            {key:'default_tone',  label:'Tono voce',     required:true},
            {key:'default_goal',  label:'Obiettivo',     required:true},
            {key:'default_platforms',label:'Piattaforme',required:false},
            {key:'vision',        label:'Visione',       required:false},
            {key:'values',        label:'Valori',        required:false},
            {key:'cta',           label:'CTA',           required:false},
            {key:'notes',         label:'Note',          required:false},
        ],

        imageFiles:[], imagePreviews:[], logoFile:null, logoPreview:null,
        recognition:null, silenceTimer:null, pendingTranscript:'',

        get filledRequired() {
            return ['business_name','industry','services','target','default_tone','default_goal']
                .filter(k => this.extracted[k]).length;
        },
        get progressPct() { return Math.round((this.filledRequired/6)*100); },
        get isComplete()  { return this.filledRequired===6; },
        get canComplete() { return this.isComplete && this.imageFiles.length>0; },
        get ctaLabel() {
            if (!this.isComplete) return 'Parla per raccogliere i dati ('+this.filledRequired+'/6)';
            if (this.imageFiles.length===0) return 'Carica almeno 1 foto per continuare';
            return 'Genera i contenuti demo';
        },

        init() {},

        // ── SPEECH ────────────────────────────────────────────────────────────
        buildRec() {
            const SR = window.SpeechRecognition||window.webkitSpeechRecognition;
            if (!SR) return null;
            const r = new SR();
            r.lang='it-IT'; r.continuous=false; r.interimResults=true; r.maxAlternatives=1;

            r.onresult = (e) => {
                let interim='', final='';
                for (let i=e.resultIndex; i<e.results.length; i++) {
                    const t = e.results[i][0].transcript;
                    if (e.results[i].isFinal) final+=t; else interim+=t;
                }
                // Mostra in tempo reale
                this.liveTranscript = final||interim;
                // Salva sempre il più aggiornato come pending
                if (interim) this.pendingTranscript = interim;
                if (final)   this.pendingTranscript = final;
                // Risultato finale → elabora subito
                if (final.trim()) {
                    this.clearTimer();
                    this.stopRec();
                    this.send(final.trim());
                }
            };

            r.onerror = (e) => {
                this.clearTimer(); this.stopRec();
                if (e.error==='no-speech') {
                    this.liveTranscript=''; this.pendingTranscript=''; this.phase='idle';
                } else if (e.error==='not-allowed') {
                    this.phase='idle'; this.showText=true;
                    this.apiError='Microfono non autorizzato. Usa la tastiera.';
                } else {
                    this.liveTranscript=''; this.pendingTranscript=''; this.phase='idle';
                    console.warn('SpeechRecognition error:', e.error);
                }
            };

            r.onend = () => {
                this.clearTimer();
                if (this.phase==='processing') return; // già in elaborazione
                const p = this.pendingTranscript.trim();
                if (p) {
                    this.pendingTranscript='';
                    this.send(p);
                } else {
                    this.liveTranscript=''; this.phase='idle';
                }
            };

            return r;
        },

        clearTimer() { if(this.silenceTimer){clearTimeout(this.silenceTimer);this.silenceTimer=null;} },

        stopRec() {
            if (this.recognition) { try{this.recognition.stop();}catch(_){} this.recognition=null; }
        },

        toggleMic() {
            if (this.phase==='listening') {
                this.clearTimer(); this.stopRec();
                const p = this.pendingTranscript.trim();
                if (p) { this.pendingTranscript=''; this.send(p); }
                else   { this.liveTranscript=''; this.phase='idle'; }
                return;
            }
            if (this.phase!=='idle') return;
            this.recognition = this.buildRec();
            if (!this.recognition) {
                this.showText=true;
                this.apiError='Riconoscimento vocale non supportato. Usa la tastiera.';
                return;
            }
            this.phase='listening'; this.liveTranscript=''; this.pendingTranscript=''; this.apiError='';
            try {
                this.recognition.start();
                this.silenceTimer = setTimeout(()=>{ if(this.phase==='listening') this.toggleMic(); }, 30000);
            } catch(e) { this.phase='idle'; console.error('SpeechRecognition start error:',e); }
        },

        // ── TESTO ─────────────────────────────────────────────────────────────
        async sendText() {
            const t = this.textInput.trim();
            if (!t||this.phase==='processing') return;
            this.textInput='';
            this.liveTranscript=t;
            await this.send(t);
        },

        // ── CORE: invia all'AI ────────────────────────────────────────────────
        async send(text) {
            this.phase='processing';
            this.history.push({role:'user',content:text});
            this.apiError='';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const res  = await fetch('{{ route('ai.brand.chat') }}', {
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
                    body: JSON.stringify({
                        messages:         this.history,
                        existing_profile: this.extractedNonNull(),
                    }),
                });

                // Parse risposta — intercetta sia errori HTTP che non-JSON
                let data;
                const rawText = await res.text();
                try { data = JSON.parse(rawText); }
                catch(_) {
                    throw new Error('Risposta non JSON dal server (HTTP '+res.status+'). Possibile problema di sessione.');
                }

                if (!res.ok) throw new Error(data.message||'Errore HTTP '+res.status);

                // Salva risposta AI
                if (data.reply) {
                    this.history.push({role:'assistant',content:data.reply});
                    this.aiHint = data.reply.length>100 ? data.reply.slice(0,97)+'…' : data.reply;
                    this.speak(data.reply);
                }

                // Merge extracted — REPLACE oggetto per garantire reattività Alpine
                if (data.extracted && typeof data.extracted==='object') {
                    const next = {...this.extracted};
                    const newLabels = [];
                    for (const [k,v] of Object.entries(data.extracted)) {
                        if (v!==null && v!=='' && v!==undefined) {
                            if (!next[k]) {
                                const f = this.allFields.find(f=>f.key===k);
                                if (f) newLabels.push(f.label);
                            }
                            next[k] = v;
                        }
                    }
                    this.extracted = next; // Replace → Alpine rileva il cambio
                    if (newLabels.length) this.showToast('Raccolto: '+newLabels.join(', '));
                }

            } catch(e) {
                console.error('BrandAI send() error:', e);
                this.apiError = e.message;
            } finally {
                this.liveTranscript = '';
                this.phase = 'idle';
            }
        },

        showToast(msg) {
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toast=msg;
            this.toastTimer = setTimeout(()=>{ this.toast=''; }, 3500);
        },

        extractedNonNull() {
            return Object.fromEntries(Object.entries(this.extracted).filter(([,v])=>v!==null&&v!==''));
        },

        speak(text) {
            if (!window.speechSynthesis) return;
            try {
                const u=new SpeechSynthesisUtterance(text.replace(/[*_`#\[\]]/g,'').slice(0,200));
                u.lang='it-IT'; u.rate=1.05;
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(u);
            } catch(_) {}
        },

        // ── FILES ─────────────────────────────────────────────────────────────
        handleImages(e) {
            const files=Array.from(e.target.files);
            if(!files.length) return;
            this.imageFiles=[...this.imageFiles,...files];
            this.imagePreviews=[...this.imagePreviews,...files.map(f=>URL.createObjectURL(f))];
            e.target.value='';
        },
        handleDrop(e) {
            this.dragOver=false;
            const files=Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/'));
            if(!files.length) return;
            this.imageFiles=[...this.imageFiles,...files];
            this.imagePreviews=[...this.imagePreviews,...files.map(f=>URL.createObjectURL(f))];
        },
        removeImage(i) { URL.revokeObjectURL(this.imagePreviews[i]); this.imageFiles.splice(i,1); this.imagePreviews.splice(i,1); },
        handleLogo(e) { const f=e.target.files[0]; if(!f) return; this.logoFile=f; this.logoPreview=URL.createObjectURL(f); },

        // ── COMPLETA ─────────────────────────────────────────────────────────
        async uploadAndComplete() {
            if (!this.canComplete) return;
            this.submitError=''; this.completing=true;
            try {
                const csrf=document.querySelector('meta[name="csrf-token"]').content;
                const fd=new FormData();
                if (this.logoFile) fd.append('logo',this.logoFile);
                this.imageFiles.forEach((f,i)=>fd.append('images['+i+']',f));

                const up=await fetch('{{ route('onboarding.assets') }}',{
                    method:'POST',credentials:'same-origin',
                    headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:fd});
                if (!up.ok){const d=await up.json();throw new Error(d.message||'Errore upload');}

                const co=await fetch('{{ route('ai.brand.onboarding-complete') }}',{
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
                    body:JSON.stringify({extracted:this.extractedNonNull()})});
                const data=await co.json();
                if (!co.ok) throw new Error(data.message||'Errore generazione');
                window.location.href=data.redirect_url;
            } catch(e) {
                console.error('uploadAndComplete error:',e);
                this.submitError=e.message;
                this.completing=false;
            }
        },
    };
}
</script>
</body>
</html>
