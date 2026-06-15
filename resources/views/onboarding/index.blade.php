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

        /* ── MIC ── */
        .mic-wrap{position:relative;width:72px;height:72px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .mic-btn{width:72px;height:72px;border-radius:50%;border:none;outline:none;cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:transform .12s,background .2s,box-shadow .2s;z-index:2;position:relative}
        .mic-btn:active{transform:scale(.91)}
        .mic-btn.idle   {background:var(--primary);box-shadow:0 4px 18px rgba(10,45,111,.3)}
        .mic-btn.listen {background:#DC2626;box-shadow:0 4px 22px rgba(220,38,38,.4)}
        .mic-btn.working{background:var(--primary);opacity:.7;cursor:not-allowed}
        .mic-ring{position:absolute;border-radius:50%;border:2px solid var(--accent);
            pointer-events:none;opacity:0;animation:ring-out 1.8s ease-out infinite}
        .mic-ring:nth-child(2){animation-delay:.6s}
        .mic-ring:nth-child(3){animation-delay:1.2s}
        @keyframes ring-out{
            0%  {width:72px;height:72px;opacity:.7;top:50%;left:50%;transform:translate(-50%,-50%)}
            100%{width:170px;height:170px;opacity:0;top:50%;left:50%;transform:translate(-50%,-50%)}}
        .mic-wrap.listening .mic-ring{opacity:1}

        /* ── SEND BTN ── */
        .send-btn{display:flex;align-items:center;gap:.45rem;
            background:var(--primary);color:#fff;border:none;border-radius:.75rem;
            padding:.6rem 1.1rem;font-size:.85rem;font-weight:600;cursor:pointer;transition:opacity .15s}
        .send-btn:disabled{opacity:.35;cursor:not-allowed}

        /* ── BANNERS ── */
        .banner{border-radius:.875rem;padding:.7rem 1rem;font-size:.84rem}
        .banner-error{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);color:var(--danger)}
        .banner-hint {background:var(--accent-soft);border:1px solid rgba(59,200,255,.3);color:var(--primary);font-weight:500;line-height:1.4}
        .banner-said {background:var(--surface-2);border:1px solid var(--border);color:var(--text-muted);font-size:.78rem;line-height:1.45}

        /* ── FIELD ROWS ── */
        .field-row{display:flex;align-items:flex-start;gap:.75rem;padding:.6rem 0;
            border-bottom:1px solid var(--border);transition:all .35s cubic-bezier(.22,1,.36,1)}
        .field-row:last-child{border-bottom:none;padding-bottom:0}
        .field-icon{width:22px;height:22px;border-radius:50%;flex-shrink:0;margin-top:.05rem;
            display:flex;align-items:center;justify-content:center;transition:all .35s}
        .field-icon.empty{background:var(--surface-3);border:1.5px solid var(--border)}
        .field-icon.filled{background:rgba(15,159,110,.15)}
        .field-label{font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;
            letter-spacing:.04em;margin-bottom:.1rem}
        .field-value{font-size:.84rem;color:var(--text);line-height:1.35;word-break:break-word;font-weight:500}
        .field-value.empty{color:var(--border);font-weight:400;font-style:italic}
        @keyframes fieldIn{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:none}}
        .field-row.just-filled .field-value{animation:fieldIn .3s ease both}

        /* ── PROGRESS ── */
        .progress-track{height:5px;border-radius:99px;overflow:hidden;background:var(--surface-3)}
        .progress-fill{height:100%;border-radius:99px;background:var(--accent);transition:width .7s cubic-bezier(.22,1,.36,1)}

        /* ── DROPZONE ── */
        .dropzone{border:2px dashed var(--border);border-radius:1rem;cursor:pointer;
            display:flex;flex-direction:column;align-items:center;padding:1.1rem;text-align:center;
            transition:border-color .2s,background .2s}
        .dropzone.over{border-color:var(--accent);background:var(--accent-soft)}

        /* ── CTA ── */
        @keyframes shine{0%{background-position:200% center}100%{background-position:-200% center}}
        .cta-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:.6rem;
            border-radius:1rem;padding:1rem;font-size:1rem;font-weight:700;color:#fff;border:none;
            cursor:pointer;transition:opacity .2s}
        .cta-btn:disabled{opacity:.38;cursor:not-allowed;background:var(--surface-3)!important;color:var(--text-muted)!important}
        .cta-btn.ready{background:linear-gradient(90deg,var(--primary),var(--accent) 50%,var(--primary));
            background-size:200% auto;animation:shine 2.5s linear infinite}
        .cta-btn.not-ready{background:var(--surface-3);color:var(--text-muted)!important}

        @keyframes fadeUp{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
        .fade-up{animation:fadeUp .22s ease both}

        .card{border-radius:1rem;padding:1.25rem;background:var(--surface);border:1px solid var(--border)}
        .label-xs{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)}
        .badge-danger{font-size:.65rem;font-weight:700;padding:.18rem .45rem;border-radius:.35rem;
            background:rgba(220,38,38,.1);color:var(--danger)}
        .thumb-wrap{position:relative;display:inline-block}
        .thumb-wrap img{width:56px;height:56px;border-radius:.75rem;object-fit:cover;border:1px solid var(--border);display:block}
        .thumb-del{position:absolute;top:-4px;right:-4px;width:18px;height:18px;border-radius:50%;
            background:var(--danger);color:#fff;border:none;cursor:pointer;font-size:9px;font-weight:700;
            display:none;align-items:center;justify-content:center}
        .thumb-wrap:hover .thumb-del{display:flex}
        .opt-toggle{font-size:.72rem;color:var(--text-muted);cursor:pointer;text-decoration:none;
            display:inline-flex;align-items:center;gap:.3rem;margin-top:.5rem;border:none;background:none;padding:0}
        .opt-toggle:hover{color:var(--primary)}
    </style>
</head>
<body>

{{-- HEADER --}}
<header style="display:flex;align-items:center;justify-content:space-between;
               padding:.875rem 1.25rem;background:var(--surface);border-bottom:1px solid var(--border)">
    <x-application-logo variant="full" class="h-7 w-auto"/>
    <form action="{{ route('logout') }}" method="POST">@csrf
        <button type="submit" style="font-size:.75rem;padding:.375rem .75rem;border-radius:.5rem;
                border:none;background:none;cursor:pointer;color:var(--text-muted)">Esci</button>
    </form>
</header>

<main style="min-height:calc(100dvh - 57px);display:flex;flex-direction:column;
             align-items:center;padding:2rem 1rem 3rem">
<div style="width:100%;max-width:480px;display:flex;flex-direction:column;gap:1.25rem">

    {{-- Title --}}
    <div style="text-align:center">
        <h1 style="font-size:1.5rem;font-weight:700;margin:0;color:var(--text)">Raccontaci il tuo brand</h1>
        <p style="margin:.4rem 0 0;font-size:.84rem;color:var(--text-muted);line-height:1.5">
            Parla liberamente — l'AI raccoglierà le informazioni per te.
        </p>
    </div>

    {{-- ── ERRORE API ── --}}
    <div id="api-error" style="display:none" class="banner banner-error" role="alert">
        <div style="display:flex;justify-content:space-between;align-items:start;gap:.5rem">
            <span id="api-error-text" style="font-size:.8rem"></span>
            <button onclick="document.getElementById('api-error').style.display='none'"
                    style="border:none;background:none;cursor:pointer;font-size:.72rem;
                           color:inherit;text-decoration:underline;flex-shrink:0;white-space:nowrap">Chiudi</button>
        </div>
    </div>

    {{-- ── INPUT PANEL ── --}}
    <div class="card">

        {{-- AI question --}}
        <div id="ai-hint" class="banner banner-hint fade-up" style="margin-bottom:.875rem">
            Dimmi: come si chiama il tuo brand e cosa fate?
        </div>

        {{-- Mic + textarea --}}
        <div style="display:flex;align-items:flex-start;gap:.875rem">

            {{-- Mic --}}
            <div style="display:flex;flex-direction:column;align-items:center;gap:.4rem;flex-shrink:0">
                <div class="mic-wrap" id="mic-wrap">
                    <div class="mic-ring"></div>
                    <div class="mic-ring"></div>
                    <div class="mic-ring"></div>
                    <button class="mic-btn idle" id="mic-btn" type="button" aria-label="Registra voce">
                        <svg id="icon-mic" width="28" height="28" fill="none" stroke="white" stroke-width="1.75" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>
                        </svg>
                        <svg id="icon-stop" width="20" height="20" fill="white" viewBox="0 0 24 24" style="display:none">
                            <rect x="5" y="5" width="14" height="14" rx="2.5"/>
                        </svg>
                        <svg id="icon-spin" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="display:none">
                            <path stroke-linecap="round" d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83">
                                <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur=".9s" repeatCount="indefinite"/>
                            </path>
                        </svg>
                    </button>
                </div>
                <span id="mic-label" style="font-size:.6rem;font-weight:600;color:var(--text-muted);
                      text-transform:uppercase;letter-spacing:.04em;text-align:center;line-height:1.2">Parla</span>
            </div>

            {{-- Text input --}}
            <div style="flex:1;display:flex;flex-direction:column;gap:.5rem">
                <textarea id="text-input" rows="3"
                    placeholder="Oppure scrivi qui..."
                    style="width:100%;resize:none;border-radius:.75rem;border:1px solid var(--border);
                           background:var(--surface-2);color:var(--text);padding:.6rem .85rem;
                           font-size:.875rem;font-family:inherit;box-sizing:border-box;outline:none;
                           line-height:1.5"></textarea>
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:.5rem">
                    <span style="font-size:.65rem;color:var(--text-muted)">Ctrl+Invio</span>
                    <button class="send-btn" id="send-btn" type="button" disabled>
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                        <span id="send-label">Invia</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Transcript box (appare dopo analisi voce) --}}
        <div id="transcript-box" class="banner banner-said fade-up" style="display:none;margin-top:.75rem">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem">
                <div>
                    <span style="font-weight:600;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;
                                 letter-spacing:.04em">Hai detto</span>
                    <p id="transcript-text" style="margin:.2rem 0 0;color:var(--text);font-size:.82rem;line-height:1.4"></p>
                </div>
                <button id="transcript-edit" type="button"
                        style="border:none;background:none;cursor:pointer;color:var(--primary);
                               font-size:.72rem;font-weight:600;flex-shrink:0;padding:.1rem 0;white-space:nowrap">
                    ✎ Modifica
                </button>
            </div>
        </div>

    </div>

    {{-- ── DATI RACCOLTI ── --}}
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
            <span class="label-xs">Profilo brand</span>
            <span id="fill-count" style="font-size:.7rem;font-weight:600;color:var(--text-muted)">0 / 6</span>
        </div>

        <div class="progress-track" style="margin-bottom:1rem">
            <div class="progress-fill" id="progress-fill" style="width:0%"></div>
        </div>

        {{-- Campi obbligatori --}}
        @php
        $required = [
            ['key'=>'business_name','label'=>'Nome brand'],
            ['key'=>'industry',     'label'=>'Settore'],
            ['key'=>'services',     'label'=>'Servizi'],
            ['key'=>'target',       'label'=>'Audience'],
            ['key'=>'default_tone', 'label'=>'Tono di voce'],
            ['key'=>'default_goal', 'label'=>'Obiettivo social'],
        ];
        $optional = [
            ['key'=>'default_platforms','label'=>'Piattaforme'],
            ['key'=>'vision',           'label'=>'Visione'],
            ['key'=>'values',           'label'=>'Valori'],
            ['key'=>'cta',              'label'=>'Call to action'],
            ['key'=>'notes',            'label'=>'Note aggiuntive'],
        ];
        $allFields = array_merge($required, $optional);
        @endphp

        <div id="req-fields">
            @foreach($required as $f)
            <div class="field-row" id="frow-{{ $f['key'] }}">
                <div class="field-icon empty" id="ficon-{{ $f['key'] }}">
                    {{-- check svg hidden until filled --}}
                    <svg id="fcheck-{{ $f['key'] }}" width="12" height="12" fill="none" stroke="#0F9F6E"
                         viewBox="0 0 24 24" style="display:none">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="field-label">{{ $f['label'] }}</div>
                    <div class="field-value empty" id="fval-{{ $f['key'] }}">Non ancora raccolto</div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Opzionali collassabili --}}
        <button class="opt-toggle" id="opt-toggle" type="button">
            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="opt-chevron">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 18l6-6-6-6"/>
            </svg>
            <span id="opt-toggle-label">Dettagli opzionali (0)</span>
        </button>

        <div id="opt-fields" style="display:none;margin-top:.25rem">
            @foreach($optional as $f)
            <div class="field-row" id="frow-{{ $f['key'] }}" style="opacity:.55">
                <div class="field-icon empty" id="ficon-{{ $f['key'] }}">
                    <svg id="fcheck-{{ $f['key'] }}" width="12" height="12" fill="none" stroke="#0F9F6E"
                         viewBox="0 0 24 24" style="display:none">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="field-label">{{ $f['label'] }}</div>
                    <div class="field-value empty" id="fval-{{ $f['key'] }}">—</div>
                </div>
            </div>
            @endforeach
        </div>

    </div>

    {{-- ── IMMAGINI ── --}}
    <div class="card">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
            <span class="label-xs">Foto brand</span>
            <span class="badge-danger">richieste</span>
        </div>
        <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .75rem;line-height:1.4">
            Almeno 1 foto — più ne carichi, migliori i contenuti generati.
        </p>

        <label class="dropzone" id="dropzone" for="images-input">
            <svg style="width:26px;height:26px;color:var(--text-muted);margin-bottom:.5rem"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span id="drop-label" style="font-size:.875rem;font-weight:500;color:var(--text)">Clicca o trascina le foto</span>
            <span style="font-size:.7rem;color:var(--text-muted);margin-top:.2rem">PNG, JPG, WebP · max 10 MB</span>
            <input type="file" id="images-input" style="display:none" accept="image/png,image/jpeg,image/webp" multiple>
        </label>

        <div id="thumbs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem"></div>

        {{-- Logo --}}
        <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border)">
            <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;
                          border-radius:.75rem;padding:.5rem .75rem;background:var(--surface-2)" for="logo-input">
                <div id="logo-placeholder" style="width:32px;height:32px;border-radius:.5rem;flex-shrink:0;
                     display:flex;align-items:center;justify-content:center;background:var(--surface-3)">
                    <svg style="width:16px;height:16px;color:var(--text-muted)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                </div>
                <img id="logo-preview" style="width:32px;height:32px;border-radius:.5rem;object-fit:contain;
                                              display:none;border:1px solid var(--border)">
                <span id="logo-label" style="font-size:.75rem;color:var(--text-muted)">Carica logo (opzionale)</span>
                <input type="file" id="logo-input" style="display:none" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            </label>
        </div>
    </div>

    {{-- ── ERRORE SUBMIT ── --}}
    <div id="submit-error" class="banner banner-error" style="display:none"></div>

    {{-- ── CTA ── --}}
    <div style="padding-bottom:1rem">
        <button class="cta-btn not-ready" id="cta-btn" type="button" disabled>
            <svg style="width:18px;height:18px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <span id="cta-label">Raccolta dati in corso (0/6)</span>
        </button>
    </div>

</div>
</main>

<script>
(function () {
    'use strict';

    /* ── CONFIG ── */
    const TRANSCRIBE_URL = '{{ route('ai.brand.transcribe-chat') }}';
    const CHAT_URL       = '{{ route('ai.brand.chat') }}';
    const ASSETS_URL     = '{{ route('onboarding.assets') }}';
    const COMPLETE_URL   = '{{ route('ai.brand.onboarding-complete') }}';
    const APPLY_URL      = '{{ route('ai.brand.apply') }}';
    const CSRF           = document.querySelector('meta[name="csrf-token"]').content;

    const REQUIRED = ['business_name','industry','services','target','default_tone','default_goal'];
    const ALL_FIELDS = [
        {key:'business_name',    label:'Nome brand'},
        {key:'industry',         label:'Settore'},
        {key:'services',         label:'Servizi'},
        {key:'target',           label:'Audience'},
        {key:'default_tone',     label:'Tono di voce'},
        {key:'default_goal',     label:'Obiettivo social'},
        {key:'default_platforms',label:'Piattaforme'},
        {key:'vision',           label:'Visione'},
        {key:'values',           label:'Valori'},
        {key:'cta',              label:'Call to action'},
        {key:'notes',            label:'Note aggiuntive'},
    ];

    /* ── STATE ── */
    let history       = [];
    let extracted     = {};
    let imageFiles    = [];
    let logoFile      = null;
    let recording     = false;
    let sending       = false;
    let completing    = false;
    let mediaRecorder = null;
    let audioChunks   = [];
    let micStream     = null;
    let optOpen       = false;
    let optFilledCount = 0;
    let lastTranscript = '';

    /* ── ELEMENTS ── */
    const $ = id => document.getElementById(id);
    const micWrap      = $('mic-wrap');
    const micBtn       = $('mic-btn');
    const micLabel     = $('mic-label');
    const iconMic      = $('icon-mic');
    const iconStop     = $('icon-stop');
    const iconSpin     = $('icon-spin');
    const textInput    = $('text-input');
    const sendBtn      = $('send-btn');
    const sendLabel    = $('send-label');
    const aiHint       = $('ai-hint');
    const transcriptBox= $('transcript-box');
    const transcriptTxt= $('transcript-text');
    const transcriptEdt= $('transcript-edit');
    const apiError     = $('api-error');
    const apiErrTxt    = $('api-error-text');
    const fillCount    = $('fill-count');
    const progFill     = $('progress-fill');
    const ctaBtn       = $('cta-btn');
    const ctaLabel     = $('cta-label');
    const thumbsEl     = $('thumbs');
    const dropzone     = $('dropzone');
    const dropLabel    = $('drop-label');
    const submitErr    = $('submit-error');
    const imagesInput  = $('images-input');
    const optToggle    = $('opt-toggle');
    const optFields    = $('opt-fields');
    const optChevron   = $('opt-chevron');
    const optLbl       = $('opt-toggle-label');

    /* ── INIT ── */
    function init() {
        micBtn.addEventListener('click', toggleMic);
        sendBtn.addEventListener('click', sendText);
        textInput.addEventListener('input', onTextChange);
        textInput.addEventListener('keydown', function(e){
            if ((e.ctrlKey||e.metaKey) && e.key==='Enter') { e.preventDefault(); sendText(); }
        });
        transcriptEdt.addEventListener('click', function(){
            textInput.value = lastTranscript;
            textInput.focus();
            onTextChange();
            transcriptBox.style.display = 'none';
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
        optToggle.addEventListener('click', toggleOpt);

        if (!navigator.mediaDevices || !window.MediaRecorder) {
            micBtn.disabled = true;
            micBtn.title = 'Microfono non supportato — usa la tastiera';
            micBtn.style.opacity = '.4';
            micLabel.textContent = 'Non disp.';
        }
    }

    function onTextChange() {
        sendBtn.disabled = !textInput.value.trim() || sending;
    }

    function toggleOpt() {
        optOpen = !optOpen;
        optFields.style.display = optOpen ? 'block' : 'none';
        optChevron.style.transform = optOpen ? 'rotate(90deg)' : '';
    }

    /* ── MIC ── */
    function toggleMic() {
        if (sending) return;
        if (recording) { stopMic(); return; }
        startMic();
    }

    function startMic() {
        if (!navigator.mediaDevices) { showApiError('Microfono non disponibile.'); return; }
        navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl:  true,
            }
        }).then(function(stream) {
            micStream   = stream;
            audioChunks = [];
            const mimeType = ['audio/webm;codecs=opus','audio/webm','audio/ogg','audio/mp4','']
                .find(function(t){ return t==='' || MediaRecorder.isTypeSupported(t); });
            mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : {});
            mediaRecorder.ondataavailable = function(e){ if(e.data&&e.data.size>0) audioChunks.push(e.data); };
            mediaRecorder.onstop = function(){
                if(micStream){ micStream.getTracks().forEach(function(t){t.stop();}); micStream=null; }
                const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType||'audio/webm' });
                audioChunks = [];
                if (blob.size === 0) { showApiError('Nessun dato audio ricevuto. Controlla il microfono e riprova.'); setMicIdle(); return; }
                sendAudio(blob);
            };
            mediaRecorder.start(500);
            recording = true;
            micBtn.className = 'mic-btn listen';
            micWrap.classList.add('listening');
            iconMic.style.display  = 'none';
            iconStop.style.display = 'block';
            iconSpin.style.display = 'none';
            micLabel.textContent   = 'Stop';
            micLabel.style.color   = '#DC2626';
            hideApiError();
            /* Nascondi vecchio transcript quando si inizia a registrare di nuovo */
            transcriptBox.style.display = 'none';
        }).catch(function(err){
            showApiError('Microfono non autorizzato. Controlla i permessi del browser.');
            console.error('[BrandAI] getUserMedia:', err);
        });
    }

    function stopMic() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
        recording = false;
        micBtn.className = 'mic-btn working';
        micWrap.classList.remove('listening');
        iconMic.style.display  = 'none';
        iconStop.style.display = 'none';
        iconSpin.style.display = 'block';
        micLabel.textContent   = 'Analisi…';
        micLabel.style.color   = 'var(--accent)';
    }

    function setMicIdle() {
        micBtn.className = 'mic-btn idle';
        micWrap.classList.remove('listening');
        iconMic.style.display  = 'block';
        iconStop.style.display = 'none';
        iconSpin.style.display = 'none';
        micLabel.textContent   = 'Parla';
        micLabel.style.color   = '';
        recording = false;
        sending   = false;
    }

    /* ── SEND AUDIO ── */
    async function sendAudio(blob) {
        if (sending) return;
        sending = true;
        hideApiError();

        try {
            const ext = blob.type.includes('ogg') ? 'ogg' : blob.type.includes('mp4') ? 'mp4' : 'webm';
            const fd  = new FormData();
            fd.append('audio', blob, 'audio.' + ext);
            history.forEach(function(m,i){
                fd.append('messages['+i+'][role]',    m.role);
                fd.append('messages['+i+'][content]', m.content);
            });
            const ep = nonNull(extracted);
            Object.keys(ep).forEach(function(k){ fd.append('existing_profile['+k+']', String(ep[k])); });

            const res = await fetch(TRANSCRIBE_URL, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: fd,
            });
            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); } catch(_){ throw new Error('Risposta non valida (HTTP '+res.status+'). Ricarica.'); }
            if (res.status === 422 && data.error) { showApiError(data.error); return; }
            if (!res.ok) throw new Error(data.message || 'Errore HTTP '+res.status);

            /* Mostra il trascritto nel banner "Hai detto" — NON in textarea */
            if (data.transcript) {
                lastTranscript = data.transcript;
                transcriptTxt.textContent = data.transcript;
                transcriptBox.style.display = 'block';
                transcriptBox.classList.remove('fade-up');
                void transcriptBox.offsetWidth;
                transcriptBox.classList.add('fade-up');
            }

            processAiResult(data, true);

        } catch(e) {
            console.error('[BrandAI] sendAudio error:', e);
            showApiError(e.message);
        } finally {
            setMicIdle();
        }
    }

    /* ── SEND TEXT ── */
    async function sendText() {
        const text = textInput.value.trim();
        if (!text || sending) return;
        sending = true;
        sendBtn.disabled = true;
        sendLabel.textContent = 'Elaboro…';
        hideApiError();
        history.push({ role:'user', content:text });
        try {
            const res = await fetch(CHAT_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json' },
                body: JSON.stringify({ messages:history, existing_profile:nonNull(extracted) }),
            });
            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); } catch(_){ throw new Error('Risposta non valida (HTTP '+res.status+'). Ricarica.'); }
            if (!res.ok) throw new Error(data.message || 'Errore HTTP '+res.status);
            textInput.value = '';
            processAiResult(data, false);
        } catch(e) {
            console.error('[BrandAI] sendText error:', e);
            showApiError(e.message);
            history.pop();
        } finally {
            sending = false;
            sendBtn.disabled = !textInput.value.trim();
            sendLabel.textContent = 'Invia';
        }
    }

    /* ── PROCESS AI RESULT ── */
    function processAiResult(data, fromAudio) {
        if (data.reply) {
            const last = history[history.length-1];
            if (!last || last.role !== 'assistant') history.push({ role:'assistant', content:data.reply });
            setHint(data.reply);
        }
        if (data.extracted && typeof data.extracted === 'object') {
            const newKeys = [];
            for (const [k,v] of Object.entries(data.extracted)) {
                if (v !== null && v !== '' && v !== undefined && v !== 'null') {
                    if (!extracted[k]) newKeys.push(k);
                    extracted[k] = v;
                }
            }
            updateFields(newKeys);
            /* Salva in DB — per voce lo fa già il backend; per testo lo facciamo qui */
            if (!fromAudio && newKeys.length) {
                fetch(APPLY_URL, {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json' },
                    body: JSON.stringify({ extracted: nonNull(extracted) }),
                }).catch(function(e){ console.warn('[BrandAI] apply error', e); });
            }
        }
    }

    /* ── UPDATE FIELDS ── */
    function updateFields(newKeys) {
        const filled = REQUIRED.filter(k => extracted[k]).length;
        let optFilled = 0;

        ALL_FIELDS.forEach(function(f) {
            const row   = $('frow-'  + f.key);
            const icon  = $('ficon-' + f.key);
            const check = $('fcheck-'+ f.key);
            const val   = $('fval-'  + f.key);
            if (!row || !val) return;

            const isOpt = !REQUIRED.includes(f.key);

            if (extracted[f.key]) {
                const v = extracted[f.key];
                const display = Array.isArray(v) ? v.join(', ') : String(v);

                if (newKeys.includes(f.key)) {
                    row.classList.add('just-filled');
                    setTimeout(function(){ row.classList.remove('just-filled'); }, 600);
                }

                val.textContent = display;
                val.className   = 'field-value';
                icon.className  = 'field-icon filled';
                if (check) check.style.display = 'inline';
                if (isOpt) { row.style.opacity = '1'; optFilled++; }
            } else {
                val.className   = 'field-value empty';
                val.textContent = isOpt ? '—' : 'Non ancora raccolto';
                icon.className  = 'field-icon empty';
                if (check) check.style.display = 'none';
                if (isOpt) row.style.opacity = '.55';
            }
        });

        /* Opzionali: aggiorna contatore nel toggle */
        optFilledCount = optFilled;
        optLbl.textContent = 'Dettagli opzionali (' + optFilled + ')';
        /* Se ne arriva uno nuovo, apri automaticamente la sezione */
        if (newKeys.some(k => !REQUIRED.includes(k)) && !optOpen) toggleOpt();

        progFill.style.width = Math.round((filled/6)*100) + '%';
        fillCount.textContent = filled + ' / 6';

        /* CTA */
        const canComplete = filled === 6 && imageFiles.length > 0;
        ctaBtn.disabled   = !canComplete;
        ctaBtn.className  = canComplete ? 'cta-btn ready' : 'cta-btn not-ready';
        ctaLabel.textContent = canComplete
            ? 'Genera i contenuti demo'
            : filled < 6
                ? 'Raccolta dati in corso (' + filled + '/6)'
                : 'Carica almeno 1 foto per continuare';
    }

    /* ── FILES ── */
    function addImages(files) {
        files.forEach(function(file) {
            imageFiles.push(file);
            const url  = URL.createObjectURL(file);
            const idx  = imageFiles.length - 1;
            const wrap = document.createElement('div');
            wrap.className = 'thumb-wrap';
            const img  = document.createElement('img');
            img.src    = url;
            const del  = document.createElement('button');
            del.className   = 'thumb-del';
            del.type        = 'button';
            del.textContent = '✕';
            del.addEventListener('click', function(){ removeImage(idx, wrap, url); });
            wrap.appendChild(img);
            wrap.appendChild(del);
            thumbsEl.appendChild(wrap);
        });
        dropLabel.textContent = imageFiles.length + ' foto — clicca per aggiungerne';
        updateFields([]);
    }

    function removeImage(idx, wrap, url) {
        imageFiles.splice(idx, 1);
        URL.revokeObjectURL(url);
        wrap.remove();
        dropLabel.textContent = imageFiles.length ? imageFiles.length+' foto — clicca per aggiungerne' : 'Clicca o trascina le foto';
        updateFields([]);
    }

    function setLogo(file) {
        if (!file) return;
        logoFile = file;
        $('logo-preview').src = URL.createObjectURL(file);
        $('logo-preview').style.display = 'block';
        $('logo-placeholder').style.display = 'none';
        $('logo-label').textContent = file.name;
    }

    /* ── COMPLETE ── */
    async function uploadAndComplete() {
        if (completing) return;
        completing = true;
        ctaBtn.disabled = true;
        ctaLabel.textContent = 'Avvio generazione…';
        submitErr.style.display = 'none';
        try {
            const fd = new FormData();
            if (logoFile) fd.append('logo', logoFile);
            imageFiles.forEach(function(f,i){ fd.append('images['+i+']', f); });
            const up = await fetch(ASSETS_URL, {
                method:'POST', credentials:'same-origin',
                headers:{'X-CSRF-TOKEN':CSRF,'Accept':'application/json'}, body:fd,
            });
            if (!up.ok) { const d=await up.json(); throw new Error(d.message||'Errore upload'); }
            const co = await fetch(COMPLETE_URL, {
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
                body: JSON.stringify({ extracted: nonNull(extracted) }),
            });
            const data = await co.json();
            if (!co.ok) throw new Error(data.message||'Errore generazione');
            window.location.href = data.redirect_url;
        } catch(e) {
            console.error('[BrandAI] uploadAndComplete error:', e);
            submitErr.textContent   = e.message;
            submitErr.style.display = 'block';
            completing = false;
            updateFields([]);
        }
    }

    /* ── UTILS ── */
    function nonNull(obj) {
        return Object.fromEntries(Object.entries(obj).filter(function([,v]){ return v!==null&&v!==''; }));
    }

    function setHint(text) {
        aiHint.textContent = text.length > 140 ? text.slice(0,137)+'…' : text;
        aiHint.classList.remove('fade-up');
        void aiHint.offsetWidth;
        aiHint.classList.add('fade-up');
    }

    function showApiError(msg) {
        apiErrTxt.textContent    = msg;
        apiError.style.display   = 'block';
    }
    function hideApiError() { apiError.style.display = 'none'; }

    /* ── START ── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
</script>

</body>
</html>
