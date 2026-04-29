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
        html,body { height:100%; overflow:hidden; }
        [x-cloak] { display:none!important }

        /* ── chat layout ── */
        .chat-wrap   { display:flex; flex-direction:column; height:100dvh; }
        .chat-msgs   { flex:1; overflow-y:auto; scroll-behavior:smooth; overscroll-behavior:contain; }
        .chat-msgs::-webkit-scrollbar { width:4px }
        .chat-msgs::-webkit-scrollbar-thumb { background:var(--border); border-radius:99px }

        /* bubbles */
        .bubble-ai   { background:var(--surface-2); color:var(--text); border-radius:1rem 1rem 1rem .25rem; max-width:82%; }
        .bubble-user { background:var(--primary); color:#fff; border-radius:1rem 1rem .25rem 1rem; max-width:82%; }

        /* mic button states */
        .mic-listening { animation: mic-pulse 1.2s ease-in-out infinite; }
        @keyframes mic-pulse {
            0%,100% { box-shadow:0 0 0 0 rgba(220,38,38,.4); }
            50%      { box-shadow:0 0 0 10px rgba(220,38,38,0); }
        }

        /* fade-in messages */
        @keyframes msgIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
        .msg-in { animation: msgIn .22s cubic-bezier(.22,1,.36,1) both }

        /* extracted pill badges */
        .pill { display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border-radius:99px;font-size:.7rem;font-weight:600;line-height:1; }
        .pill-ok { background:rgba(15,159,110,.12);color:#0F9F6E }
        .pill-miss { background:rgba(245,158,11,.1);color:#B45309 }

        /* image upload dropzone */
        .dropzone-active { border-color:var(--accent)!important;background:var(--accent-soft)!important }
    </style>
</head>

<body style="background:var(--bg);color:var(--text)" x-data="brandChat()" x-init="init()">

<div class="chat-wrap">

    {{-- ── HEADER ── --}}
    <header class="shrink-0 flex items-center gap-3 px-4 py-3 border-b" style="background:var(--surface);border-color:var(--border)">
        <x-application-logo variant="icon" class="h-7 w-7 shrink-0" />
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold leading-tight" style="color:var(--text)">Setup Brand AI</p>
            <p class="text-[11px] leading-tight" style="color:var(--text-muted)">Racconto il tuo brand in modo naturale</p>
        </div>

        {{-- progress pills --}}
        <div class="hidden sm:flex items-center gap-1.5">
            <template x-for="f in requiredFields" :key="f.key">
                <span class="pill" :class="extracted[f.key] ? 'pill-ok' : 'pill-miss'" x-text="f.label"></span>
            </template>
        </div>

        {{-- logout --}}
        <form action="{{ route('logout') }}" method="POST" class="ml-2">
            @csrf
            <button type="submit" class="text-xs px-2 py-1 rounded-lg transition hover:opacity-70" style="color:var(--text-muted)">Esci</button>
        </form>
    </header>

    {{-- ── MAIN: chat + sidebar ── --}}
    <div class="flex flex-1 min-h-0">

        {{-- ── CHAT PANEL ── --}}
        <div class="flex flex-col flex-1 min-w-0">

            {{-- Messages --}}
            <div class="chat-msgs flex-1 px-4 py-4 space-y-3" id="chat-scroll">

                {{-- AI greeting (static) --}}
                <div class="flex items-end gap-2 msg-in">
                    <div class="h-7 w-7 shrink-0 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:var(--primary)">AI</div>
                    <div class="bubble-ai px-4 py-2.5 text-sm leading-relaxed shadow-sm">
                        Ciao! Sono qui per aiutarti a configurare il tuo brand. <br>
                        Dimmi tutto: come si chiama la tua azienda e cosa fai? Puoi parlare liberamente!
                    </div>
                </div>

                {{-- Dynamic messages --}}
                <template x-for="(msg, i) in messages" :key="i">
                    <div class="flex msg-in" :class="msg.role === 'user' ? 'justify-end' : 'items-end gap-2'">
                        <template x-if="msg.role === 'assistant'">
                            <div class="h-7 w-7 shrink-0 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:var(--primary)">AI</div>
                        </template>
                        <div class="px-4 py-2.5 text-sm leading-relaxed shadow-sm"
                             :class="msg.role === 'user' ? 'bubble-user' : 'bubble-ai'"
                             x-html="msg.content.replace(/\n/g,'<br>')"></div>
                    </div>
                </template>

                {{-- AI typing indicator --}}
                <div x-show="aiTyping" class="flex items-end gap-2 msg-in">
                    <div class="h-7 w-7 shrink-0 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:var(--primary)">AI</div>
                    <div class="bubble-ai px-4 py-3 flex gap-1 items-center">
                        <span class="h-1.5 w-1.5 rounded-full animate-bounce" style="background:var(--text-muted);animation-delay:0ms"></span>
                        <span class="h-1.5 w-1.5 rounded-full animate-bounce" style="background:var(--text-muted);animation-delay:150ms"></span>
                        <span class="h-1.5 w-1.5 rounded-full animate-bounce" style="background:var(--text-muted);animation-delay:300ms"></span>
                    </div>
                </div>

            </div>

            {{-- ── INPUT BAR ── --}}
            <div class="shrink-0 border-t px-3 py-3" style="background:var(--surface);border-color:var(--border)">

                {{-- Complete CTA (mobile) - shown when complete --}}
                <div x-show="isComplete && imageFiles.length > 0" x-cloak class="mb-2 sm:hidden">
                    <button @click="completeOnboarding" :disabled="completing"
                        class="w-full flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white transition"
                        style="background:var(--primary)">
                        <template x-if="!completing">
                            <span class="flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Genera contenuti demo
                            </span>
                        </template>
                        <template x-if="completing">
                            <span class="flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Avvio generazione…
                            </span>
                        </template>
                    </button>
                </div>

                {{-- Image upload prompt (mobile) --}}
                <div x-show="isComplete && imageFiles.length === 0" x-cloak class="mb-2 sm:hidden">
                    <label class="flex items-center gap-2 rounded-xl border-2 border-dashed px-4 py-2.5 cursor-pointer transition text-sm font-medium"
                           style="border-color:var(--accent);color:var(--primary)">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Carica almeno 1 foto del brand per generare i contenuti
                        <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" multiple @change="handleImages($event)">
                    </label>
                </div>

                <div class="flex items-end gap-2">
                    {{-- Text input --}}
                    <textarea
                        x-model="userInput"
                        @keydown.enter.prevent="if(!$event.shiftKey && userInput.trim()) sendMessage()"
                        placeholder="Scrivi qui…"
                        rows="1"
                        :disabled="aiTyping"
                        class="flex-1 resize-none rounded-xl border px-3.5 py-2.5 text-sm leading-relaxed transition focus:outline-none"
                        style="background:var(--surface-2);border-color:var(--border);color:var(--text);max-height:120px"
                        @input="autoResize($el)"
                    ></textarea>

                    {{-- Mic button --}}
                    <button @click="toggleMic" type="button"
                        class="shrink-0 h-10 w-10 rounded-full flex items-center justify-center transition"
                        :class="listening ? 'mic-listening' : ''"
                        :style="listening ? 'background:#DC2626;color:#fff' : 'background:var(--surface-3);color:var(--text-muted)'"
                        title="Parla">
                        <svg class="h-4.5 w-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                  d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                  d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                        </svg>
                    </button>

                    {{-- Send button --}}
                    <button @click="sendMessage" type="button" :disabled="!userInput.trim() || aiTyping"
                        class="shrink-0 h-10 w-10 rounded-full flex items-center justify-center transition disabled:opacity-40"
                        style="background:var(--primary);color:#fff">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <p class="mt-1.5 text-center text-[10px]" style="color:var(--text-muted)">Invio con Enter · Shift+Enter per andare a capo</p>
            </div>
        </div>

        {{-- ── SIDEBAR (desktop only) ── --}}
        <aside class="hidden sm:flex flex-col w-72 xl:w-80 shrink-0 border-l overflow-y-auto" style="background:var(--surface);border-color:var(--border)">

            {{-- Brand data extracted --}}
            <div class="p-4 border-b" style="border-color:var(--border)">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color:var(--text-muted)">Dati estratti</p>

                <div class="space-y-2">
                    <template x-for="f in allFields" :key="f.key">
                        <div class="flex items-start gap-2">
                            <div class="mt-0.5 h-4 w-4 shrink-0 rounded-full flex items-center justify-center text-[9px]"
                                 :style="extracted[f.key] ? 'background:rgba(15,159,110,.15);color:#0F9F6E' : 'background:var(--surface-3);color:var(--text-muted)'">
                                <template x-if="extracted[f.key]">
                                    <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </template>
                                <template x-if="!extracted[f.key]">
                                    <span>·</span>
                                </template>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold" style="color:var(--text-muted)" x-text="f.label + (f.required ? ' *' : '')"></p>
                                <p class="text-xs truncate" style="color:var(--text)" x-text="formatValue(extracted[f.key]) || '—'"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Image upload --}}
            <div class="p-4 border-b" style="border-color:var(--border)">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color:var(--text-muted)">Foto brand <span style="color:var(--danger)">*</span></p>
                <p class="text-[11px] mb-2" style="color:var(--text-muted)">Necessarie per generare i contenuti demo.</p>

                <label class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed px-3 py-4 cursor-pointer transition text-center"
                       :class="dragOver ? 'dropzone-active' : ''"
                       style="border-color:var(--border)"
                       @dragover.prevent="dragOver=true"
                       @dragleave="dragOver=false"
                       @drop.prevent="handleDrop($event)">
                    <svg class="h-7 w-7 mb-1.5" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="text-xs font-medium" style="color:var(--text)"
                          x-text="imageFiles.length > 0 ? imageFiles.length + ' foto selezionate' : 'Clicca o trascina le foto'"></span>
                    <span class="text-[10px] mt-0.5" style="color:var(--text-muted)">PNG, JPG, WebP · max 10 MB</span>
                    <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" multiple @change="handleImages($event)">
                </label>

                {{-- Thumbnails --}}
                <div x-show="imagePreviews.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="(url, i) in imagePreviews" :key="i">
                        <div class="group relative">
                            <img :src="url" class="h-12 w-12 rounded-lg object-cover border" style="border-color:var(--border)">
                            <button type="button" @click="removeImage(i)"
                                class="absolute -right-1 -top-1 hidden h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white group-hover:flex"
                                style="background:var(--danger)">✕</button>
                        </div>
                    </template>
                </div>

                {{-- Logo upload --}}
                <div class="mt-3">
                    <p class="text-[11px] font-semibold mb-1.5" style="color:var(--text-muted)">Logo <span class="font-normal">(opzionale)</span></p>
                    <label class="flex items-center gap-2 rounded-xl border px-3 py-2 cursor-pointer transition hover:opacity-80"
                           style="border-color:var(--border);background:var(--surface-2)">
                        <div x-show="!logoPreview" class="h-8 w-8 shrink-0 rounded-lg flex items-center justify-center" style="background:var(--surface-3)">
                            <svg class="h-4 w-4" style="color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <img x-show="logoPreview" :src="logoPreview" class="h-8 w-8 rounded-lg object-contain border" style="border-color:var(--border)">
                        <span class="text-xs truncate" style="color:var(--text)"
                              x-text="logoFile ? logoFile.name : 'Carica logo'"></span>
                        <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp,image/svg+xml" @change="handleLogo($event)">
                    </label>
                </div>
            </div>

            {{-- Complete CTA --}}
            <div class="p-4 mt-auto">
                <div x-show="!isComplete" class="text-center">
                    <p class="text-xs" style="color:var(--text-muted)">Completa la chat per sbloccare la generazione</p>
                    <div class="mt-2 flex flex-wrap justify-center gap-1">
                        <template x-for="f in missingFields" :key="f">
                            <span class="pill pill-miss" x-text="fieldLabel(f)"></span>
                        </template>
                    </div>
                </div>

                <div x-show="isComplete" x-cloak>
                    <div x-show="imageFiles.length === 0" class="mb-3 rounded-xl border px-3 py-2.5 text-xs text-center"
                         style="border-color:var(--warning);background:rgba(245,158,11,.07);color:#B45309">
                        Carica almeno 1 foto per continuare
                    </div>
                    <div x-show="errorMsg" class="mb-3 rounded-xl border px-3 py-2 text-xs" style="border-color:var(--danger);background:rgba(220,38,38,.07);color:var(--danger)" x-text="errorMsg"></div>

                    <button @click="uploadAndComplete" :disabled="imageFiles.length === 0 || completing"
                        class="w-full flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white transition disabled:opacity-50"
                        style="background:var(--primary)">
                        <template x-if="!completing">
                            <span class="flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Genera contenuti demo
                            </span>
                        </template>
                        <template x-if="completing">
                            <span class="flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Avvio generazione…
                            </span>
                        </template>
                    </button>
                </div>
            </div>

        </aside>
    </div>

</div>

<script>
function brandChat() {
    return {
        // state
        messages:      [],
        userInput:     '',
        aiTyping:      false,
        listening:     false,
        isComplete:    false,
        completing:    false,
        dragOver:      false,
        errorMsg:      '',
        missingFields: ['business_name','industry','services','target','default_tone','default_goal'],

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

        // files
        imageFiles:    [],
        imagePreviews: [],
        logoFile:      null,
        logoPreview:   null,

        // speech recognition
        recognition: null,

        // field metadata
        requiredFields: [
            { key:'business_name', label:'Brand'    },
            { key:'industry',      label:'Settore'  },
            { key:'services',      label:'Servizi'  },
            { key:'target',        label:'Audience' },
            { key:'default_tone',  label:'Tono'     },
            { key:'default_goal',  label:'Obiettivo'},
        ],
        allFields: [
            { key:'business_name',     label:'Nome brand',   required:true  },
            { key:'industry',          label:'Settore',       required:true  },
            { key:'services',          label:'Servizi',       required:true  },
            { key:'target',            label:'Audience',      required:true  },
            { key:'default_tone',      label:'Tono',          required:true  },
            { key:'default_goal',      label:'Obiettivo',     required:true  },
            { key:'default_platforms', label:'Piattaforme',   required:false },
            { key:'vision',            label:'Visione',       required:false },
            { key:'values',            label:'Valori',        required:false },
            { key:'cta',               label:'CTA',           required:false },
            { key:'notes',             label:'Note',          required:false },
        ],

        init() {
            this.setupSpeech();
        },

        setupSpeech() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) return;
            const r = new SpeechRecognition();
            r.lang = 'it-IT';
            r.interimResults = false;
            r.maxAlternatives = 1;
            r.onresult = (e) => {
                const transcript = e.results[0][0].transcript;
                this.userInput = transcript;
                this.listening = false;
                this.$nextTick(() => this.sendMessage());
            };
            r.onerror = () => { this.listening = false; };
            r.onend   = () => { this.listening = false; };
            this.recognition = r;
        },

        toggleMic() {
            if (!this.recognition) {
                alert('Il riconoscimento vocale non è supportato in questo browser.');
                return;
            }
            if (this.listening) {
                this.recognition.stop();
            } else {
                this.listening = true;
                this.recognition.start();
            }
        },

        async sendMessage() {
            const text = this.userInput.trim();
            if (!text || this.aiTyping) return;

            this.messages.push({ role: 'user', content: text });
            this.userInput = '';
            this.$nextTick(() => this.scrollDown());

            this.aiTyping = true;
            try {
                const res = await fetch('{{ route('ai.brand.chat') }}', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify({
                        messages:         this.messages,
                        existing_profile: this.extractedNonNull(),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Errore AI');

                this.messages.push({ role: 'assistant', content: data.reply });

                // merge extracted fields
                if (data.extracted) {
                    for (const [k, v] of Object.entries(data.extracted)) {
                        if (v !== null && v !== '') this.extracted[k] = v;
                    }
                }
                this.missingFields = data.missing_fields || [];
                this.isComplete    = data.complete === true;

            } catch(e) {
                this.messages.push({ role:'assistant', content:'Mi dispiace, si è verificato un errore. Riprova.' });
            } finally {
                this.aiTyping = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        extractedNonNull() {
            return Object.fromEntries(
                Object.entries(this.extracted).filter(([,v]) => v !== null && v !== '')
            );
        },

        scrollDown() {
            const el = document.getElementById('chat-scroll');
            if (el) el.scrollTop = el.scrollHeight;
        },

        autoResize(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        },

        handleImages(event) {
            const files = Array.from(event.target.files);
            if (!files.length) return;
            this.imageFiles = [...this.imageFiles, ...files];
            this.imagePreviews = [...this.imagePreviews, ...files.map(f => URL.createObjectURL(f))];
            event.target.value = '';
        },

        handleDrop(event) {
            this.dragOver = false;
            const files = Array.from(event.dataTransfer.files).filter(f => f.type.startsWith('image/'));
            if (!files.length) return;
            this.imageFiles = [...this.imageFiles, ...files];
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

        async uploadAndComplete() {
            this.errorMsg = '';
            if (this.imageFiles.length === 0) { this.errorMsg = 'Carica almeno 1 foto.'; return; }
            this.completing = true;
            try {
                // 1. Upload assets
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const fd   = new FormData();
                if (this.logoFile) fd.append('logo', this.logoFile);
                this.imageFiles.forEach((f, i) => fd.append('images[' + i + ']', f));
                const uploadRes = await fetch('{{ route('onboarding.assets') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                if (!uploadRes.ok) { const d = await uploadRes.json(); throw new Error(d.message || 'Errore upload.'); }

                // 2. Complete onboarding (applies extracted + runs quickstart)
                const completeRes = await fetch('{{ route('ai.brand.onboarding-complete') }}', {
                    method:  'POST',
                    headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json' },
                    body: JSON.stringify({ extracted: this.extractedNonNull() }),
                });
                const data = await completeRes.json();
                if (!completeRes.ok) throw new Error(data.message || 'Errore generazione.');
                window.location.href = data.redirect_url;

            } catch(e) {
                this.errorMsg  = e.message;
                this.completing = false;
            }
        },

        // mobile path: upload + complete
        async completeOnboarding() {
            return this.uploadAndComplete();
        },

        formatValue(v) {
            if (!v) return null;
            if (Array.isArray(v)) return v.join(', ');
            return String(v);
        },

        fieldLabel(key) {
            const f = this.allFields.find(f => f.key === key);
            return f ? f.label : key;
        },
    };
}
</script>

</body>
</html>
