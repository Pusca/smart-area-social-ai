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
        body{background:var(--bg);color:var(--text);font-family:inherit}

        /* mic */
        .mic-wrap{position:relative;width:80px;height:80px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .mic-btn{width:80px;height:80px;border-radius:50%;border:none;outline:none;cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:transform .12s,background .2s,box-shadow .2s;z-index:2;position:relative}
        .mic-btn:active{transform:scale(.91)}
        .mic-btn.idle  {background:var(--primary);box-shadow:0 6px 22px rgba(10,45,111,.35)}
        .mic-btn.listen{background:#DC2626;   box-shadow:0 6px 26px rgba(220,38,38,.4)}
        .mic-ring{position:absolute;border-radius:50%;border:2px solid var(--accent);
            pointer-events:none;opacity:0;
            animation:ring-out 1.8s ease-out infinite}
        .mic-ring:nth-child(2){animation-delay:.55s}
        .mic-ring:nth-child(3){animation-delay:1.1s}
        @keyframes ring-out{
            0%  {width:80px;height:80px;opacity:.65;top:50%;left:50%;transform:translate(-50%,-50%)}
            100%{width:180px;height:180px;opacity:0;top:50%;left:50%;transform:translate(-50%,-50%)}}
        .mic-wrap.listening .mic-ring{opacity:1}/* mostra rings solo quando attivo */

        /* chips */
        .chip{display:inline-flex;align-items:center;gap:.32rem;border-radius:99px;
            font-size:.73rem;font-weight:600;padding:.28rem .65rem;
            transition:all .4s cubic-bezier(.22,1,.36,1)}
        .chip.empty {background:var(--surface-3);color:var(--text-muted)}
        .chip.filled{background:rgba(15,159,110,.15);color:#0F9F6E;transform:scale(1.06)}
        .chip-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;transition:background .4s}
        .chip.empty  .chip-dot{background:var(--border)}
        .chip.filled .chip-dot{background:#0F9F6E}

        /* send */
        .send-btn{display:flex;align-items:center;gap:.5rem;
            background:var(--primary);color:#fff;border:none;border-radius:.75rem;
            padding:.65rem 1.25rem;font-size:.875rem;font-weight:600;cursor:pointer;
            transition:opacity .15s}
        .send-btn:disabled{opacity:.38;cursor:not-allowed}

        /* dropzone */
        .dropzone{border:2px dashed var(--border);border-radius:1rem;
            transition:border-color .2s,background .2s;cursor:pointer;
            display:flex;flex-direction:column;align-items:center;
            padding:1.25rem;text-align:center}
        .dropzone.over{border-color:var(--accent);background:var(--accent-soft)}

        /* progress */
        .progress-track{height:6px;border-radius:99px;overflow:hidden;background:var(--surface-3)}
        .progress-fill {height:100%;border-radius:99px;background:var(--accent);
            transition:width .7s cubic-bezier(.22,1,.36,1)}

        /* cta */
        @keyframes cta-shine{0%{background-position:200% center}100%{background-position:-200% center}}
        .cta-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:.6rem;
            border-radius:1rem;padding:1rem;font-size:1rem;font-weight:700;color:#fff;border:none;
            cursor:pointer;transition:opacity .2s}
        .cta-btn:disabled{opacity:.38;cursor:not-allowed;background:var(--surface-3)!important;color:var(--text-muted)!important}
        .cta-btn.ready{background:linear-gradient(90deg,var(--primary) 0%,var(--accent) 50%,var(--primary) 100%);
            background-size:200% auto;animation:cta-shine 2.5s linear infinite}
        .cta-btn.not-ready{background:var(--surface-3);color:var(--text-muted)!important}

        /* toast / banner */
        .banner{border-radius:.875rem;padding:.75rem 1rem;font-size:.875rem;display:none}
        .banner.show{display:block}
        .banner-error{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);color:var(--danger)}
        .banner-ok   {background:rgba(15,159,110,.1); border:1px solid rgba(15,159,110,.2);color:#0F9F6E}
        .banner-hint {background:var(--accent-soft);  border:1px solid rgba(59,200,255,.3);color:var(--primary);font-weight:500}

        @keyframes fadeUp{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
        .fade-up{animation:fadeUp .22s ease both}

        .card{border-radius:1rem;padding:1.25rem;background:var(--surface);border:1px solid var(--border)}
        .label-xs{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)}
        .badge-danger{font-size:.65rem;font-weight:700;padding:.18rem .45rem;border-radius:.35rem;
            background:rgba(220,38,38,.1);color:var(--danger)}

        .thumb-wrap{position:relative;display:inline-block}
        .thumb-wrap img{width:56px;height:56px;border-radius:.75rem;object-fit:cover;
            border:1px solid var(--border);display:block}
        .thumb-del{position:absolute;top:-4px;right:-4px;width:18px;height:18px;
            border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;
            font-size:9px;font-weight:700;display:none;align-items:center;justify-content:center}
        .thumb-wrap:hover .thumb-del{display:flex}
    </style>
</head>
<body>

{{-- HEADER --}}
<header style="display:flex;align-items:center;justify-content:space-between;
               padding:.875rem 1.25rem;background:var(--surface);
               border-bottom:1px solid var(--border)">
    <x-application-logo variant="full" class="h-7 w-auto"/>
    <form action="{{ route('logout') }}" method="POST">@csrf
        <button type="submit"
                style="font-size:.75rem;padding:.375rem .75rem;border-radius:.5rem;
                       border:none;background:none;cursor:pointer;color:var(--text-muted)">
            Esci
        </button>
    </form>
</header>

<main style="min-height:calc(100dvh - 57px);display:flex;flex-direction:column;
             align-items:center;padding:2rem 1rem">
<div style="width:100%;max-width:480px;display:flex;flex-direction:column;gap:1.25rem">

    {{-- Title --}}
    <div style="text-align:center">
        <h1 style="font-size:1.6rem;font-weight:700;margin:0;color:var(--text)">
            Raccontaci il tuo brand
        </h1>
        <p style="margin:.5rem 0 0;font-size:.875rem;color:var(--text-muted);line-height:1.5">
            Usa il microfono o scrivi nella casella, poi premi <strong>Invia</strong>.
        </p>
    </div>

    {{-- ── ERRORE API ── --}}
    <div id="api-error" class="banner banner-error" role="alert">
        <div style="display:flex;justify-content:space-between;align-items:start;gap:.5rem">
            <span id="api-error-text" style="font-size:.8rem"></span>
            <button onclick="document.getElementById('api-error').classList.remove('show')"
                    style="border:none;background:none;cursor:pointer;font-size:.75rem;
                           color:inherit;text-decoration:underline;flex-shrink:0">
                Chiudi
            </button>
        </div>
    </div>

    {{-- ── INPUT PANEL ── --}}
    <div class="card">
        {{-- Mic + textarea --}}
        <div style="display:flex;align-items:flex-start;gap:.875rem;margin-bottom:.875rem">

            {{-- Mic --}}
            <div class="mic-wrap" id="mic-wrap">
                <div class="mic-ring"></div>
                <div class="mic-ring"></div>
                <div class="mic-ring"></div>
                <button class="mic-btn idle" id="mic-btn" type="button">
                    {{-- mic icon --}}
                    <svg id="icon-mic" width="30" height="30" fill="none" stroke="white"
                         stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                    </svg>
                    {{-- stop icon --}}
                    <svg id="icon-stop" width="22" height="22" fill="white"
                         viewBox="0 0 24 24" style="display:none">
                        <rect x="5" y="5" width="14" height="14" rx="2.5"/>
                    </svg>
                </button>
            </div>

            {{-- Textarea --}}
            <div style="flex:1;display:flex;flex-direction:column;gap:.45rem">
                <div id="mic-status" style="font-size:.72rem;color:var(--text-muted)">
                    Scrivi oppure usa il microfono
                </div>
                <textarea id="text-input" rows="3"
                    placeholder="Es: Sono Luca, ho una pizzeria a Napoli. Vendo pizze artigianali a famiglie e turisti, tono amichevole, voglio più visibilità sui social..."
                    style="width:100%;resize:none;border-radius:.75rem;
                           border:1px solid var(--border);background:var(--surface-2);
                           color:var(--text);padding:.625rem .875rem;font-size:.875rem;
                           font-family:inherit;box-sizing:border-box;outline:none;
                           line-height:1.5"></textarea>
            </div>
        </div>

        {{-- Send row --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem">
            <span style="font-size:.68rem;color:var(--text-muted)">Ctrl+Invio per inviare</span>
            <button class="send-btn" id="send-btn" type="button" disabled>
                <svg width="16" height="16" fill="none" stroke="currentColor"
                     viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
                <span id="send-label">Invia</span>
            </button>
        </div>

        {{-- AI hint --}}
        <div id="ai-hint" class="banner banner-hint fade-up"
             style="margin-top:.75rem;display:none">
            Dimmi: come si chiama il tuo brand e cosa fate?
        </div>
        {{-- Show initial hint immediately --}}
        <script>
            document.getElementById('ai-hint').style.display = 'block';
        </script>

        {{-- Toast raccolto --}}
        <div id="toast-ok" class="banner banner-ok fade-up" style="margin-top:.75rem">
            <div style="display:flex;align-items:center;gap:.5rem">
                <svg style="width:16px;height:16px;flex-shrink:0" fill="none"
                     stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M5 13l4 4L19 7"/>
                </svg>
                <span id="toast-text"></span>
            </div>
        </div>
    </div>

    {{-- ── DATI RACCOLTI ── --}}
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
            <span class="label-xs">Dati raccolti</span>
            <span id="fill-count" style="font-size:.7rem;font-weight:600;color:var(--text-muted)">0/6 obbligatori</span>
        </div>

        <div id="chips-wrap" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
            @php
            $fields = [
                ['key'=>'business_name','label'=>'Nome brand',  'req'=>true],
                ['key'=>'industry',     'label'=>'Settore',      'req'=>true],
                ['key'=>'services',     'label'=>'Servizi',      'req'=>true],
                ['key'=>'target',       'label'=>'Audience',     'req'=>true],
                ['key'=>'default_tone', 'label'=>'Tono voce',    'req'=>true],
                ['key'=>'default_goal', 'label'=>'Obiettivo',    'req'=>true],
                ['key'=>'default_platforms','label'=>'Piattaforme','req'=>false],
                ['key'=>'vision',       'label'=>'Visione',      'req'=>false],
                ['key'=>'values',       'label'=>'Valori',       'req'=>false],
                ['key'=>'cta',          'label'=>'CTA',          'req'=>false],
                ['key'=>'notes',        'label'=>'Note',         'req'=>false],
            ];
            @endphp
            @foreach($fields as $f)
            <span class="chip empty" id="chip-{{ $f['key'] }}">
                <span class="chip-dot"></span>
                {{ $f['label'] }}
                @if($f['req'])<span id="req-{{ $f['key'] }}" style="font-size:9px;opacity:.4">●</span>@endif
                <svg id="check-{{ $f['key'] }}" width="12" height="12" fill="none"
                     stroke="currentColor" viewBox="0 0 24 24" style="display:none">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                </svg>
            </span>
            @endforeach
        </div>

        <div class="progress-track">
            <div class="progress-fill" id="progress-fill" style="width:0%"></div>
        </div>

        {{-- Valori estratti --}}
        <div id="values-wrap" style="display:none;margin-top:.875rem;padding-top:.875rem;
                                      border-top:1px solid var(--border)">
            @foreach($fields as $f)
            <div id="val-row-{{ $f['key'] }}" style="display:none;margin-bottom:.35rem">
                <span style="display:inline-flex;gap:.35rem;font-size:.72rem">
                    <strong style="color:var(--text-muted);min-width:5.5rem;flex-shrink:0">{{ $f['label'] }}:</strong>
                    <span id="val-{{ $f['key'] }}" style="color:var(--text);word-break:break-word"></span>
                </span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── IMMAGINI ── --}}
    <div class="card">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
            <span class="label-xs">Foto brand</span>
            <span class="badge-danger">richieste per la generazione</span>
        </div>
        <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .75rem">
            Almeno 1 foto — più ne carichi, migliori i risultati.
        </p>

        <label class="dropzone" id="dropzone" for="images-input">
            <svg style="width:28px;height:28px;color:var(--text-muted);margin-bottom:.5rem"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0
                         012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0
                         00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span id="drop-label"
                  style="font-size:.875rem;font-weight:500;color:var(--text)">
                Clicca o trascina le foto
            </span>
            <span style="font-size:.7rem;color:var(--text-muted);margin-top:.25rem">
                PNG, JPG, WebP · max 10 MB
            </span>
            <input type="file" id="images-input" class="sr-only" style="display:none"
                   accept="image/png,image/jpeg,image/webp" multiple>
        </label>

        <div id="thumbs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem"></div>

        {{-- Logo --}}
        <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border)">
            <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;
                          border-radius:.75rem;padding:.5rem .75rem;background:var(--surface-2)"
                   for="logo-input">
                <div id="logo-placeholder"
                     style="width:32px;height:32px;border-radius:.5rem;flex-shrink:0;
                            display:flex;align-items:center;justify-content:center;
                            background:var(--surface-3)">
                    <svg style="width:16px;height:16px;color:var(--text-muted)"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011
                                 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                </div>
                <img id="logo-preview" style="width:32px;height:32px;border-radius:.5rem;
                                              object-fit:contain;display:none;
                                              border:1px solid var(--border)">
                <span id="logo-label" style="font-size:.75rem;color:var(--text-muted)">
                    Carica logo (opzionale)
                </span>
                <input type="file" id="logo-input" style="display:none"
                       accept="image/png,image/jpeg,image/webp,image/svg+xml">
            </label>
        </div>
    </div>

    {{-- ── ERRORE SUBMIT ── --}}
    <div id="submit-error" class="banner banner-error" style="display:none"></div>

    {{-- ── CTA ── --}}
    <div style="padding-bottom:2rem">
        <button class="cta-btn not-ready" id="cta-btn" type="button" disabled>
            <svg style="width:20px;height:20px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <span id="cta-label">Parla per raccogliere i dati (0/6)</span>
        </button>
    </div>

</div>
</main>

<script>
(function () {
    'use strict';

    /* ── CONFIG ─────────────────────────────────────────────────────────── */
    const CHAT_URL     = '{{ route('ai.brand.chat') }}';
    const APPLY_URL    = '{{ route('ai.brand.apply') }}';
    const ASSETS_URL   = '{{ route('onboarding.assets') }}';
    const COMPLETE_URL = '{{ route('ai.brand.onboarding-complete') }}';
    const CSRF         = document.querySelector('meta[name="csrf-token"]').content;

    const REQUIRED = ['business_name','industry','services','target','default_tone','default_goal'];
    const ALL_FIELDS = [
        {key:'business_name',label:'Nome brand'},
        {key:'industry',     label:'Settore'},
        {key:'services',     label:'Servizi'},
        {key:'target',       label:'Audience'},
        {key:'default_tone', label:'Tono voce'},
        {key:'default_goal', label:'Obiettivo'},
        {key:'default_platforms',label:'Piattaforme'},
        {key:'vision',       label:'Visione'},
        {key:'values',       label:'Valori'},
        {key:'cta',          label:'CTA'},
        {key:'notes',        label:'Note'},
    ];

    /* ── STATE ──────────────────────────────────────────────────────────── */
    let history    = [];
    let extracted  = {};
    let imageFiles = [];
    let logoFile   = null;
    let listening  = false;
    let sending    = false;
    let completing = false;
    let recognition = null;
    let toastTimer  = null;

    /* ── ELEMENTS ───────────────────────────────────────────────────────── */
    const $ = id => document.getElementById(id);
    const micWrap   = $('mic-wrap');
    const micBtn    = $('mic-btn');
    const iconMic   = $('icon-mic');
    const iconStop  = $('icon-stop');
    const micStatus = $('mic-status');
    const textInput = $('text-input');
    const sendBtn   = $('send-btn');
    const sendLabel = $('send-label');
    const aiHint    = $('ai-hint');
    const toastOk   = $('toast-ok');
    const toastText = $('toast-text');
    const apiError  = $('api-error');
    const apiErrTxt = $('api-error-text');
    const fillCount = $('fill-count');
    const progFill  = $('progress-fill');
    const valuesWrap= $('values-wrap');
    const ctaBtn    = $('cta-btn');
    const ctaLabel  = $('cta-label');
    const thumbsEl  = $('thumbs');
    const dropzone  = $('dropzone');
    const dropLabel = $('drop-label');
    const submitErr = $('submit-error');
    const imagesInput=$('images-input');

    /* ── INIT ───────────────────────────────────────────────────────────── */
    function init() {
        micBtn.addEventListener('click', toggleMic);
        sendBtn.addEventListener('click', sendInput);
        textInput.addEventListener('input', onTextChange);
        textInput.addEventListener('keydown', function(e){
            if ((e.ctrlKey||e.metaKey) && e.key==='Enter') { e.preventDefault(); sendInput(); }
        });
        imagesInput.addEventListener('change', function(e){ addImages(Array.from(e.target.files)); e.target.value=''; });
        $('logo-input').addEventListener('change', function(e){ setLogo(e.target.files[0]); });
        dropzone.addEventListener('dragover', function(e){ e.preventDefault(); dropzone.classList.add('over'); });
        dropzone.addEventListener('dragleave', function(){ dropzone.classList.remove('over'); });
        dropzone.addEventListener('drop', function(e){
            e.preventDefault(); dropzone.classList.remove('over');
            addImages(Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/')));
        });
        ctaBtn.addEventListener('click', uploadAndComplete);

        if (!window.SpeechRecognition && !window.webkitSpeechRecognition) {
            micBtn.disabled = true;
            micBtn.title = 'Microfono non supportato — usa la tastiera';
            micBtn.style.opacity = '.4';
        }
    }

    function onTextChange() {
        sendBtn.disabled = !textInput.value.trim() || sending;
    }

    /* ── MIC ─────────────────────────────────────────────────────────────── */
    function toggleMic() {
        if (listening) { stopMic(); return; }
        startMic();
    }

    function startMic() {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return;
        recognition = new SR();
        recognition.lang = 'it-IT';
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = function() {
            listening = true;
            micBtn.className = 'mic-btn listen';
            micWrap.classList.add('listening');
            iconMic.style.display = 'none';
            iconStop.style.display = 'block';
            micStatus.textContent = 'In ascolto — tocca per fermare';
            micStatus.style.color = '#DC2626';
        };

        recognition.onresult = function(e) {
            /* Prendi sempre l'ultimo transcript disponibile */
            let last = '';
            for (let i = 0; i < e.results.length; i++) {
                last = e.results[i][0].transcript;
            }
            textInput.value = last;
            onTextChange();
        };

        recognition.onerror = function(e) {
            stopMicUI();
            if (e.error === 'not-allowed') {
                showApiError('Microfono non autorizzato. Usa la tastiera.');
            } else if (e.error !== 'no-speech' && e.error !== 'aborted') {
                showApiError('Errore microfono: ' + e.error);
            }
        };

        recognition.onend = function() {
            stopMicUI();
            /* Testo rimane in textarea — utente preme Invia */
        };

        try { recognition.start(); }
        catch(err) { stopMicUI(); showApiError('Impossibile avviare il microfono: ' + err.message); }
    }

    function stopMic() {
        if (recognition) { try { recognition.stop(); } catch(_){} recognition = null; }
        stopMicUI();
    }

    function stopMicUI() {
        listening = false;
        micBtn.className = 'mic-btn idle';
        micWrap.classList.remove('listening');
        iconMic.style.display = 'block';
        iconStop.style.display = 'none';
        micStatus.textContent = 'Scrivi oppure usa il microfono';
        micStatus.style.color = '';
    }

    /* ── SEND ────────────────────────────────────────────────────────────── */
    async function sendInput() {
        const text = textInput.value.trim();
        if (!text || sending) return;
        if (listening) stopMic();

        sending = true;
        sendBtn.disabled = true;
        sendLabel.textContent = 'Elaboro…';
        hideApiError();

        history.push({ role: 'user', content: text });

        try {
            const res = await fetch(CHAT_URL, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    messages:         history,
                    existing_profile: nonNull(extracted),
                }),
            });

            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); }
            catch(_) {
                throw new Error(
                    'Risposta non JSON (HTTP ' + res.status + '). ' +
                    'Prova a ricaricare la pagina — potrebbe essere scaduta la sessione.'
                );
            }

            if (!res.ok) throw new Error(data.message || 'Errore HTTP ' + res.status);

            /* AI reply */
            if (data.reply) {
                history.push({ role: 'assistant', content: data.reply });
                setHint(data.reply);
                speak(data.reply);
            }

            /* Merge extracted */
            if (data.extracted && typeof data.extracted === 'object') {
                const newLabels = [];
                let hasNew = false;
                for (const [k, v] of Object.entries(data.extracted)) {
                    if (v !== null && v !== '' && v !== undefined && v !== 'null') {
                        if (!extracted[k]) {
                            const f = ALL_FIELDS.find(x => x.key === k);
                            if (f) newLabels.push(f.label);
                            hasNew = true;
                        }
                        extracted[k] = v;
                    }
                }
                updateChips();

                /* Salva in DB ogni volta che ci sono dati nuovi */
                if (hasNew) {
                    saveToDb(nonNull(extracted));
                    if (newLabels.length) showToast('Raccolto: ' + newLabels.join(', '));
                }
            }

            textInput.value = '';

        } catch(e) {
            console.error('[BrandAI] sendInput error:', e);
            showApiError(e.message);
            history.pop(); /* rimuovi l'ultimo messaggio utente fallito */
        } finally {
            sending = false;
            sendBtn.disabled = !textInput.value.trim();
            sendLabel.textContent = 'Invia';
        }
    }

    /* ── CHIPS / PROGRESS ────────────────────────────────────────────────── */
    function updateChips() {
        const filled = REQUIRED.filter(k => extracted[k]).length;

        REQUIRED.forEach(k => {
            const chip  = $('chip-' + k);
            const req   = $('req-' + k);
            const check = $('check-' + k);
            if (!chip) return;
            if (extracted[k]) {
                chip.className  = 'chip filled';
                if (req)   req.style.display   = 'none';
                if (check) check.style.display  = 'inline';
            } else {
                chip.className  = 'chip empty';
                if (req)   req.style.display   = '';
                if (check) check.style.display  = 'none';
            }
        });

        ['default_platforms','vision','values','cta','notes'].forEach(k => {
            const chip  = $('chip-' + k);
            const check = $('check-' + k);
            if (!chip) return;
            if (extracted[k]) {
                chip.className = 'chip filled';
                if (check) check.style.display = 'inline';
            } else {
                chip.className = 'chip empty';
                if (check) check.style.display = 'none';
            }
        });

        progFill.style.width = Math.round((filled / 6) * 100) + '%';
        fillCount.textContent = filled + '/6 obbligatori';

        /* Valori estratti */
        let anyVal = false;
        ALL_FIELDS.forEach(f => {
            const row = $('val-row-' + f.key);
            const val = $('val-' + f.key);
            if (!row || !val) return;
            if (extracted[f.key]) {
                anyVal = true;
                const v = extracted[f.key];
                val.textContent = Array.isArray(v) ? v.join(', ') : String(v);
                row.style.display = 'block';
            } else {
                row.style.display = 'none';
            }
        });
        valuesWrap.style.display = anyVal ? 'block' : 'none';

        /* CTA */
        const canComplete = filled === 6 && imageFiles.length > 0;
        if (canComplete) {
            ctaBtn.disabled = false;
            ctaBtn.className = 'cta-btn ready';
            ctaLabel.textContent = 'Genera i contenuti demo';
        } else {
            ctaBtn.disabled = true;
            ctaBtn.className = 'cta-btn not-ready';
            if (filled < 6) {
                ctaLabel.textContent = 'Parla per raccogliere i dati (' + filled + '/6)';
            } else {
                ctaLabel.textContent = 'Carica almeno 1 foto per continuare';
            }
        }
    }

    /* ── FILES ───────────────────────────────────────────────────────────── */
    function addImages(files) {
        files.forEach(file => {
            imageFiles.push(file);
            const url    = URL.createObjectURL(file);
            const idx    = imageFiles.length - 1;
            const wrap   = document.createElement('div');
            wrap.className = 'thumb-wrap';
            const img    = document.createElement('img');
            img.src      = url;
            const del    = document.createElement('button');
            del.className = 'thumb-del';
            del.type     = 'button';
            del.textContent = '✕';
            del.addEventListener('click', function(){ removeImage(idx, wrap, url); });
            wrap.appendChild(img);
            wrap.appendChild(del);
            thumbsEl.appendChild(wrap);
        });
        dropLabel.textContent = imageFiles.length + ' foto — clicca per aggiungerne';
        updateChips();
    }

    function removeImage(idx, wrap, url) {
        imageFiles.splice(idx, 1);
        URL.revokeObjectURL(url);
        wrap.remove();
        dropLabel.textContent = imageFiles.length
            ? imageFiles.length + ' foto — clicca per aggiungerne'
            : 'Clicca o trascina le foto';
        updateChips();
    }

    function setLogo(file) {
        if (!file) return;
        logoFile = file;
        $('logo-preview').src = URL.createObjectURL(file);
        $('logo-preview').style.display = 'block';
        $('logo-placeholder').style.display = 'none';
        $('logo-label').textContent = file.name;
    }

    /* ── COMPLETE ────────────────────────────────────────────────────────── */
    async function uploadAndComplete() {
        if (completing) return;
        completing = true;
        ctaBtn.disabled = true;
        ctaLabel.textContent = 'Avvio generazione…';
        submitErr.style.display = 'none';

        try {
            const fd = new FormData();
            if (logoFile) fd.append('logo', logoFile);
            imageFiles.forEach((f, i) => fd.append('images[' + i + ']', f));

            const up = await fetch(ASSETS_URL, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: fd,
            });
            if (!up.ok) { const d = await up.json(); throw new Error(d.message || 'Errore upload'); }

            const co = await fetch(COMPLETE_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json' },
                body: JSON.stringify({ extracted: nonNull(extracted) }),
            });
            const data = await co.json();
            if (!co.ok) throw new Error(data.message || 'Errore generazione');
            window.location.href = data.redirect_url;

        } catch(e) {
            console.error('[BrandAI] uploadAndComplete error:', e);
            submitErr.textContent = e.message;
            submitErr.style.display = 'block';
            completing = false;
            updateChips();
        }
    }

    /* ── SAVE TO DB ─────────────────────────────────────────────────────── */
    function saveToDb(data) {
        fetch(APPLY_URL, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'Accept':       'application/json',
            },
            body: JSON.stringify({ extracted: data }),
        }).then(function(res) {
            if (!res.ok) console.warn('[BrandAI] saveToDb failed', res.status);
        }).catch(function(e) {
            console.warn('[BrandAI] saveToDb error', e);
        });
    }

    /* ── UTILS ───────────────────────────────────────────────────────────── */
    function nonNull(obj) {
        return Object.fromEntries(Object.entries(obj).filter(([,v]) => v !== null && v !== ''));
    }

    function setHint(text) {
        aiHint.textContent = text.length > 130 ? text.slice(0, 127) + '…' : text;
        aiHint.style.display = 'block';
        aiHint.classList.remove('fade-up');
        void aiHint.offsetWidth; /* reflow per restart animation */
        aiHint.classList.add('fade-up');
    }

    function showToast(msg) {
        if (toastTimer) clearTimeout(toastTimer);
        toastText.textContent = msg;
        toastOk.classList.add('show');
        toastOk.classList.remove('fade-up');
        void toastOk.offsetWidth;
        toastOk.classList.add('fade-up');
        toastTimer = setTimeout(function(){ toastOk.classList.remove('show'); }, 4000);
    }

    function showApiError(msg) {
        apiErrTxt.textContent = msg;
        apiError.classList.add('show');
    }

    function hideApiError() {
        apiError.classList.remove('show');
    }

    function speak(text) {
        if (!window.speechSynthesis) return;
        try {
            const u = new SpeechSynthesisUtterance(text.replace(/[*_`#\[\]]/g,'').slice(0, 200));
            u.lang = 'it-IT'; u.rate = 1.05;
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(u);
        } catch(_) {}
    }

    /* ── START ───────────────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
</script>

</body>
</html>
