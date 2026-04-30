@extends('layouts.app')

@section('content')
@php
    $platformOptions = [
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'tiktok'    => 'TikTok',
        'linkedin'  => 'LinkedIn',
        'youtube'   => 'YouTube',
        'threads'   => 'Threads',
    ];

    $tz = config('app.timezone', 'Europe/Rome');
    $createPreset = ($createPreset ?? request()->query('preset', 'default'));
    $createPreset = $createPreset === 'reel' ? 'reel' : 'default';
    $isReelPreset = $createPreset === 'reel';

    $videoProviderOptions = is_array($videoProviderOptions ?? null) && !empty($videoProviderOptions)
        ? $videoProviderOptions
        : \App\Support\VideoProviderResolver::configuredWithLabels();
    $imageProviderOptions = is_array($imageProviderOptions ?? null) && !empty($imageProviderOptions)
        ? $imageProviderOptions
        : \App\Support\ImageProviderResolver::configuredWithLabels();

    $defaultPlatforms = old('platforms', $profile?->default_platforms ?? ['instagram']);
    if (!is_array($defaultPlatforms)) { $defaultPlatforms = ['instagram']; }

    $defaultVideoProvider = old('video_provider', \App\Support\VideoProviderResolver::default());
    if (!array_key_exists($defaultVideoProvider, $videoProviderOptions)) {
        $defaultVideoProvider = array_key_first($videoProviderOptions) ?? \App\Support\VideoProviderResolver::default();
    }
    $defaultImageProvider = old('image_provider', \App\Support\ImageProviderResolver::default());
    if (!array_key_exists($defaultImageProvider, $imageProviderOptions)) {
        $defaultImageProvider = array_key_first($imageProviderOptions) ?? \App\Support\ImageProviderResolver::default();
    }

    $overlayPreferences = is_array($profile?->overlay_preferences ?? null) ? $profile->overlay_preferences : [];
    $overlayFonts   = (array) config('overlays.fonts', []);
    $overlayPresets = (array) config('overlays.presets', []);
    $overlayUi      = (array) config('overlays.ui', []);
    $overlayMode         = old('overlay_mode', (bool)($overlayPreferences['auto_enabled'] ?? true) ? 'auto' : 'off');
    $overlayPreset       = old('overlay_preset', (string)($overlayPreferences['preset'] ?? 'modern_split_caption'));
    $overlayFontFamily   = old('overlay_font_family', (string)($overlayPreferences['font_family'] ?? 'arial'));
    $overlayFontFallback = old('overlay_font_fallback', (string)($overlayPreferences['fallback_font_family'] ?? 'segoe_ui'));
    $overlayFontPreset   = (string)($overlayPreferences['font_preset'] ?? $overlayPreferences['tone_preset'] ?? 'modern');
    $brandHookIntensity  = (string)($overlayPreferences['preferred_hook_intensity'] ?? 'medium');
    $brandTrendAppetite  = (string)($overlayPreferences['trend_appetite'] ?? 'medium');
    $brandProfessionalismGuardrail = (string)($overlayPreferences['professionalism_guardrail_level'] ?? 'high');
    $overlayFontWeight       = old('overlay_font_weight', '700');
    $overlayFontSizeMode     = old('overlay_font_size_mode', $isReelPreset ? 'xl' : 'large');
    $overlayTextCase         = old('overlay_text_case', 'sentence');
    $overlayAlignment        = old('overlay_alignment', 'left');
    $overlayPosition         = old('overlay_position', $isReelPreset ? 'upper_center' : 'upper_left');
    $overlaySafeArea         = old('overlay_safe_area', (string)($overlayPreferences['safe_area'] ?? 'upper_third'));
    $overlayMaxLines         = (int)old('overlay_max_lines', $isReelPreset ? 2 : 3);
    $overlayColor            = old('overlay_color', '#FFFFFF');
    $overlayStrokeColor      = old('overlay_stroke_color', '#111827');
    $overlayShadow           = (bool)old('overlay_shadow', true);
    $overlayBackgroundStyle  = old('overlay_background_style', $isReelPreset ? 'dark_box' : 'gradient_strip');
    $overlayAnimationStyle   = old('overlay_animation_style', $isReelPreset ? 'pop' : 'fade');
    $overlayCtaText          = old('overlay_cta_text', '');

    $defaultSchedule = old('scheduled_at', now($tz)->addHour()->startOfHour()->format('Y-m-d\\TH:i'));
    $defaultVideoDurationSeconds = (int)old('video_duration_seconds_requested', $isReelPreset ? 20 : 0);

    $referenceImages = $referenceImages ?? [];
    if ($referenceImages instanceof \Illuminate\Support\Collection) { $referenceImages = $referenceImages->values()->all(); }
    if (!is_array($referenceImages)) { $referenceImages = []; }
    $selectedReferenceIds = old('reference_asset_ids', []);
    if (!is_array($selectedReferenceIds)) { $selectedReferenceIds = []; }
    $selectedReferenceIds = array_values(array_unique(array_map(fn($v) => (int)$v, array_filter($selectedReferenceIds, fn($v) => (int)$v > 0))));

    $assetVariables = is_array($assetVariables ?? null) ? $assetVariables : [];
    $selectedVariableIds = old('asset_variable_ids', []);
    if (!is_array($selectedVariableIds)) { $selectedVariableIds = []; }
    $selectedVariableIds = array_values(array_unique(array_map(fn($v) => (int)$v, array_filter($selectedVariableIds, fn($v) => (int)$v > 0))));
    $selectedPresenterId     = (int)old('presenter_variable_id', 0);
    $selectedProductId       = (int)old('product_variable_id', 0);
    $selectedPlaceId         = (int)old('place_variable_id', 0);
    $selectedConsistencyMode = old('consistency_mode', 'balanced');
    $seasonalOverlay         = old('seasonal_overlay', '');
    $initialMode             = $isReelPreset ? 'reel' : (old('format') ?: '');
@endphp

<style>
/* ── Spaziatura base ── */
:root {
    --sp-xs:  .5rem;
    --sp-sm:  .75rem;
    --sp-md:  1rem;
    --sp-lg:  1.5rem;
    --sp-xl:  2rem;
    --sp-2xl: 3rem;
    --radius-card: 1.25rem;
    --radius-inner: .875rem;
    --border-soft: 1px solid rgba(10,45,111,0.09);
    --bg-soft: rgba(10,45,111,0.04);
    --navy: #0A2D6F;
    --navy-dark: #071D4E;
    --slate: #64748b;
    --slate-light: #94a3b8;
}

/* ── Scelta tipo ── */
.cr-choice {
    display: flex;
    flex-direction: column;
    padding: var(--sp-xl);
    border: 1.5px solid rgba(10,45,111,0.1);
    border-radius: var(--radius-card);
    background: #fff;
    cursor: pointer;
    transition: border-color 200ms, box-shadow 200ms, transform 200ms, background 200ms;
    text-align: left;
    width: 100%;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}
.cr-choice::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3BC8FF 0%, #1498F3 100%);
    border-radius: 3px 3px 0 0;
    opacity: 0;
    transition: opacity 200ms;
}
.cr-choice:hover {
    border-color: rgba(10,45,111,0.22);
    box-shadow: 0 16px 40px rgba(10,45,111,0.12);
    transform: translateY(-4px);
    background: rgba(10,45,111,0.015);
}
.cr-choice:hover::after { opacity: 1; }
.cr-choice-icon {
    width: 3.25rem; height: 3.25rem;
    border-radius: var(--radius-inner);
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, rgba(59,200,255,.18) 0%, rgba(10,45,111,.09) 100%);
    margin-bottom: var(--sp-lg);
    flex-shrink: 0;
}
.cr-choice-title { font-size: 1.05rem; font-weight: 700; color: var(--navy); margin-bottom: calc(var(--sp-xs) * .75); }
.cr-choice-desc  { font-size: .8rem; color: var(--slate); line-height: 1.55; }
.cr-choice-arrow {
    margin-top: auto; padding-top: var(--sp-md);
    display: flex; align-items: center; gap: .3rem;
    font-size: .72rem; font-weight: 600;
    color: var(--slate-light);
    transition: color 200ms, gap 200ms;
}
.cr-choice:hover .cr-choice-arrow { color: #1498F3; gap: .5rem; }

/* ── Card form ── */
.cr-card {
    border: var(--border-soft);
    border-radius: var(--radius-card);
    background: #fff;
}
.cr-section { padding: var(--sp-lg); }
.cr-section + .cr-section { border-top: var(--border-soft); }

/* ── Campi ── */
.cr-field-wrap {
    background: var(--bg-soft);
    border-radius: var(--radius-inner);
    padding: var(--sp-sm) var(--sp-md);
}
.cr-field-label {
    display: block;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--slate-light);
    margin-bottom: var(--sp-xs);
}
.cr-native {
    width: 100%;
    background: transparent;
    border: none !important; outline: none !important; box-shadow: none !important;
    border-radius: 0; padding: 0;
    font-size: .875rem;
    color: #1e3a5f;
    appearance: none;
}
.cr-native:focus,
.cr-native:active,
.cr-native:focus-visible,
.cr-field-wrap input:focus,
.cr-field-wrap input:active,
.cr-field-wrap input:focus-visible,
.cr-field-wrap select:focus,
.cr-field-wrap select:active,
.cr-field-wrap select:focus-visible,
.cr-field-wrap textarea:focus,
.cr-field-wrap textarea:active,
.cr-field-wrap textarea:focus-visible {
    outline: none !important;
    border: none !important;
    box-shadow: none !important;
}

/* ── Platform pills ── */
.cr-platform {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .3rem .75rem;
    border-radius: 999px;
    font-size: .72rem; font-weight: 600;
    color: var(--slate);
    cursor: pointer;
    transition: background 140ms, color 140ms;
    background: var(--bg-soft);
    border: none;
}
.cr-platform input:checked ~ * { color: var(--navy); }
.cr-platform:has(input:checked) { background: rgba(10,45,111,0.12); color: var(--navy); }

/* ── Details accordion ── */
.cr-accordion { border: var(--border-soft); border-radius: var(--radius-card); background: #fff; overflow: hidden; }
.cr-accordion summary {
    display: flex; align-items: center; justify-content: space-between;
    padding: var(--sp-md) var(--sp-lg);
    cursor: pointer; list-style: none;
    font-size: .875rem; font-weight: 600; color: var(--navy);
    transition: background 140ms;
}
.cr-accordion summary:hover { background: rgba(10,45,111,0.03); }
.cr-accordion summary::marker, .cr-accordion summary::-webkit-details-marker { display: none; }
.cr-accordion-body { border-top: var(--border-soft); padding: var(--sp-lg); }
.cr-accordion[open] summary { border-bottom: var(--border-soft); }

/* ── Breadcrumb selected ── */
.cr-breadcrumb {
    display: inline-flex; align-items: center; gap: var(--sp-sm);
    padding: var(--sp-xs) var(--sp-sm);
    border-radius: 999px;
    background: rgba(10,45,111,0.07);
    font-size: .8rem; font-weight: 600; color: var(--navy);
    cursor: pointer; border: none;
    margin-bottom: var(--sp-lg);
    transition: background 140ms;
}
.cr-breadcrumb:hover { background: rgba(10,45,111,0.12); }

/* ── Grid scelte responsive ── */
.cr-choice-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    width: 100%;
    max-width: 700px;
}
@media (max-width: 600px) {
    .cr-choice-grid {
        grid-template-columns: 1fr;
        max-width: 100%;
    }
}
</style>

<div
    x-data="createPage()"
    class="w-full"
    :style="mode === '' ? 'padding:0;' : 'padding:2rem 1.5rem;max-width:860px;margin:0 auto;'"
>

    @if($errors->any())
        <div style="border-radius:var(--radius-card);border:1px solid #fecaca;background:#fef2f2;padding:var(--sp-md) var(--sp-lg);margin-bottom:var(--sp-lg);font-size:.875rem;color:#b91c1c;">
            <ul style="list-style:disc inside;display:flex;flex-direction:column;gap:.25rem;">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ══════════════════════════════════════
         SCELTA INIZIALE — centrata nella pagina
    ══════════════════════════════════════ --}}
    <div
        x-show="mode === ''"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="min-height:calc(100dvh - 4rem);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1.5rem;"
    >
        <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:var(--slate-light);margin-bottom:.625rem;">Nuovo contenuto</p>
        <h1 style="font-size:1.75rem;font-weight:800;color:var(--navy);margin-bottom:.5rem;letter-spacing:-.02em;">Cosa vuoi creare?</h1>
        <p style="font-size:.875rem;color:var(--slate);margin-bottom:2.75rem;">Scegli il tipo di contenuto per iniziare.</p>

        <div class="cr-choice-grid">

            {{-- Post --}}
            <button type="button" @click="setMode('post')" class="cr-choice">
                <div class="cr-choice-icon">
                    <svg width="22" height="22" fill="none" stroke="#0A2D6F" stroke-width="1.6" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2.5"/>
                        <path stroke-linecap="round" d="M3 9h18M9 21V9"/>
                    </svg>
                </div>
                <p class="cr-choice-title">Post</p>
                <p class="cr-choice-desc">Immagine singola o carosello per il feed</p>
                <div class="cr-choice-arrow">
                    <span>Inizia</span>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </button>

            {{-- Reel --}}
            <button type="button" @click="setMode('reel')" class="cr-choice">
                <div class="cr-choice-icon">
                    <svg width="22" height="22" fill="none" stroke="#0A2D6F" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="cr-choice-title">Reel</p>
                <p class="cr-choice-desc">Video con script, shot plan e voiceover</p>
                <div class="cr-choice-arrow">
                    <span>Inizia</span>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </button>

            {{-- Piano --}}
            <a href="{{ route('wizard.start') }}" class="cr-choice">
                <div class="cr-choice-icon">
                    <svg width="22" height="22" fill="none" stroke="#0A2D6F" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="cr-choice-title">Piano editoriale</p>
                <p class="cr-choice-desc">Sequenza di contenuti con logica d'insieme</p>
                <div class="cr-choice-arrow">
                    <span>Inizia</span>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

        </div>
    </div>

    {{-- ══════════════════════════════════════
         FORM — appare dopo la scelta
    ══════════════════════════════════════ --}}
    <div
        x-show="mode !== ''"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        style="display:none;"
    >
        {{-- Breadcrumb / cambia scelta --}}
        <button type="button" @click="mode = ''" class="cr-breadcrumb">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            <span x-text="mode === 'post' ? 'Post' : 'Reel'"></span>
            <span style="color:var(--slate-light);font-weight:400;">· Cambia</span>
        </button>

        <form id="single-content-form" method="POST" action="{{ route('posts.store') }}" style="display:flex;flex-direction:column;gap:var(--sp-md);">
            @csrf
            <input type="hidden" id="format" name="format" :value="mode">
            <input type="hidden" name="presenter_variable_id" id="js-presenter-variable-id" value="{{ $selectedPresenterId ?: '' }}">
            <input type="hidden" name="product_variable_id"   id="js-product-variable-id"   value="{{ $selectedProductId ?: '' }}">
            <input type="hidden" name="place_variable_id"     id="js-place-variable-id"     value="{{ $selectedPlaceId ?: '' }}">
            <input type="hidden" name="consistency_mode"  id="js-consistency-mode"  value="{{ $selectedConsistencyMode }}">
            <input type="hidden" name="seasonal_overlay"  id="js-seasonal-overlay"  value="{{ $seasonalOverlay }}">
            <input type="hidden" name="goal_hint"         value="{{ old('goal_hint', $profile?->default_goal ?: '') }}">

            {{-- ── Brief ── --}}
            <div class="cr-card">
                <div class="cr-section">
                    <label class="cr-field-label">Brief di generazione</label>
                    <textarea
                        id="generation_brief"
                        name="generation_brief"
                        rows="5"
                        class="cr-native"
                        style="resize:none;line-height:1.6;"
                        placeholder="Descrivi cosa vuoi ottenere — soggetto, tono, momento, angolo narrativo…"
                        required
                    >{{ old('generation_brief', old('caption', '')) }}</textarea>
                </div>
                @if(!empty($assetVariables))
                <div class="cr-section" style="padding-top:var(--sp-sm);">
                    <div id="recognizedAssetVariables" style="display:flex;flex-wrap:wrap;gap:.375rem;min-height:1rem;"></div>
                </div>
                @endif
            </div>

            {{-- ── Data + Piattaforme ── --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-md);">
                <div class="cr-card cr-section">
                    <label class="cr-field-label">Programmazione</label>
                    <div class="cr-field-wrap">
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at"
                            value="{{ $defaultSchedule }}" required
                            style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;">
                    </div>
                </div>
                <div class="cr-card cr-section">
                    <label class="cr-field-label">Piattaforme</label>
                    <div style="display:flex;flex-wrap:wrap;gap:.375rem;">
                        @foreach($platformOptions as $key => $label)
                        <label class="cr-platform">
                            <input type="checkbox" name="platforms[]" value="{{ $key }}"
                                style="width:.75rem;height:.75rem;accent-color:#0A2D6F;margin:0;"
                                @checked(in_array($key, $defaultPlatforms, true))>
                            {{ $label }}
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── Immagini di riferimento ── --}}
            @if(!empty($referenceImages))
            <details class="cr-accordion">
                <summary>
                    <span>Immagini di riferimento</span>
                    <span style="font-size:.75rem;font-weight:400;color:var(--slate-light);">{{ count($referenceImages) }} disponibili</span>
                </summary>
                <div class="cr-accordion-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(5rem,1fr));gap:.625rem;">
                        @foreach($referenceImages as $img)
                        @php
                            $assetId  = (int)($img['id'] ?? 0);
                            $refNum   = (int)($img['ref_number'] ?? ($loop->index + 1));
                            $assetPath = (string)($img['path'] ?? '');
                            $isChecked = $assetId > 0 && in_array($assetId, $selectedReferenceIds, true);
                        @endphp
                        <label style="position:relative;overflow:hidden;border-radius:.75rem;border:1.5px solid rgba(10,45,111,.1);cursor:pointer;aspect-ratio:1;display:block;">
                            <div style="position:absolute;top:.375rem;left:.375rem;z-index:2;background:rgba(7,29,78,.8);color:#fff;font-size:.6rem;font-weight:700;padding:.15rem .4rem;border-radius:999px;">#{{ $refNum }}</div>
                            <div style="position:absolute;top:.375rem;right:.375rem;z-index:2;background:#fff;border-radius:.375rem;padding:.2rem;">
                                <input type="checkbox" name="reference_asset_ids[]" value="{{ $assetId }}"
                                    style="width:.75rem;height:.75rem;accent-color:#0A2D6F;display:block;" @checked($isChecked)>
                            </div>
                            <img src="{{ asset('storage/' . ltrim($assetPath, '/')) }}" alt="#{{ $refNum }}"
                                style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                        </label>
                        @endforeach
                    </div>
                </div>
            </details>
            @endif

            {{-- ── Asset e identità ── --}}
            @if(!empty($assetVariables))
            <details class="cr-accordion advanced-only">
                <summary>
                    <span>Asset e identità brand</span>
                    <span style="font-size:.75rem;font-weight:400;color:var(--slate-light);">{{ count($assetVariables) }} disponibili</span>
                </summary>
                <div class="cr-accordion-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.625rem;">
                        @foreach($assetVariables as $var)
                        @php
                            $varId   = (int)($var['id'] ?? 0);
                            $varName = (string)($var['name'] ?? '');
                            $varSlug = (string)($var['slug'] ?? '');
                            $varKind = (string)($var['kind'] ?? 'custom');
                            $varDesc = trim((string)($var['description'] ?? ''));
                            $checked = $varId > 0 && in_array($varId, $selectedVariableIds, true);
                            $kindDot = ['person' => '#7c3aed', 'product' => '#0f9f6e', 'location' => '#b45309'];
                            $kindLabels = ['person' => 'Persona', 'product' => 'Prodotto', 'location' => 'Luogo'];
                        @endphp
                        <label style="display:flex;align-items:flex-start;gap:.625rem;padding:.75rem var(--sp-md);border-radius:var(--radius-inner);border:1.5px solid rgba(10,45,111,.08);background:#fff;cursor:pointer;transition:border-color 140ms;">
                            <input type="checkbox" name="asset_variable_ids[]" value="{{ $varId }}"
                                class="js-asset-variable-checkbox"
                                data-variable-id="{{ $varId }}"
                                data-variable-name="{{ strtolower($varName) }}"
                                data-variable-slug="{{ strtolower($varSlug) }}"
                                data-variable-kind="{{ $varKind }}"
                                style="width:.875rem;height:.875rem;accent-color:#0A2D6F;margin-top:.15rem;flex-shrink:0;"
                                @checked($checked)>
                            <span>
                                <span style="display:block;font-size:.875rem;font-weight:600;color:#1e3a5f;">{{ $varName }}</span>
                                <span style="font-size:.7rem;font-weight:700;color:{{ $kindDot[$varKind] ?? '#64748b' }};">{{ $kindLabels[$varKind] ?? $varKind }}</span>
                                @if($varDesc)<span style="font-size:.7rem;color:var(--slate-light);"> · {{ $varDesc }}</span>@endif
                            </span>
                        </label>
                        @endforeach
                    </div>
                    <div id="js-identity-summary" style="display:none;margin-top:.75rem;padding:.5rem var(--sp-md);border-radius:var(--radius-inner);background:var(--bg-soft);font-size:.75rem;font-weight:500;color:var(--slate);"></div>
                </div>
            </details>
            @endif

            {{-- ── Alter Ego ── --}}
            @php $alterEgos = $alterEgos ?? collect(); $defaultAlterEgoId = (int)old('alter_ego_id', 0); @endphp
            @if($alterEgos->isNotEmpty())
            <details class="cr-accordion advanced-only">
                <summary>
                    <span>Alter Ego</span>
                    <span style="font-size:.75rem;font-weight:400;color:var(--slate-light);">Voce narrativa</span>
                </summary>
                <div class="cr-accordion-body">
                    <div class="cr-field-wrap">
                        <select name="alter_ego_id" id="alter_ego_id"
                            style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;appearance:none;cursor:pointer;">
                            <option value="">— Nessun alter ego —</option>
                            @foreach($alterEgos as $ego)
                                <option value="{{ $ego->id }}" @selected($defaultAlterEgoId === $ego->id || (!$defaultAlterEgoId && $ego->is_default))>
                                    {{ $ego->name }}{{ $ego->is_default ? ' (default)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>
            @endif

            {{-- ── Motori AI ── --}}
            <details class="cr-accordion advanced-only" id="provider-settings-details">
                <summary>
                    <span>Motori AI</span>
                    <span style="font-size:.75rem;font-weight:400;color:var(--slate-light);" id="provider-summary-label">—</span>
                </summary>
                <div class="cr-accordion-body" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-md);">
                    <div>
                        <label class="cr-field-label">Immagini</label>
                        <div class="cr-field-wrap">
                            <select id="image_provider" name="image_provider"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;appearance:none;cursor:pointer;">
                                @foreach($imageProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultImageProvider === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="cr-field-label">Video</label>
                        <div class="cr-field-wrap">
                            <select id="video_provider" name="video_provider"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;appearance:none;cursor:pointer;">
                                @foreach($videoProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($defaultVideoProvider === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div x-show="mode === 'reel'" style="grid-column:span 2;">
                        <label class="cr-field-label">Durata video (secondi)</label>
                        <div class="cr-field-wrap">
                            <input id="video_duration_seconds_requested" type="number" name="video_duration_seconds_requested"
                                min="4" max="45" step="1" value="{{ $defaultVideoDurationSeconds > 0 ? $defaultVideoDurationSeconds : 20 }}"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;">
                        </div>
                    </div>
                </div>
            </details>

            {{-- ── Overlay ── --}}
            <details class="cr-accordion advanced-only" id="overlay-settings-details">
                <summary>
                    <span>Text overlay e tipografia</span>
                    <span style="font-size:.75rem;font-weight:400;color:var(--slate-light);">Auto</span>
                </summary>
                <div class="cr-accordion-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:var(--sp-md);">
                    @foreach([
                        ['overlay_mode', 'Overlay', $overlayUi['modes'] ?? ['auto','manual','off'], $overlayMode, null],
                        ['overlay_preset', 'Preset', null, $overlayPreset, $overlayPresets],
                        ['overlay_font_family', 'Font', null, $overlayFontFamily, $overlayFonts],
                        ['overlay_position', 'Posizione', $overlayUi['positions'] ?? ['upper_left','upper_center','center_left','center','lower_left','lower_center'], $overlayPosition, null],
                    ] as [$name, $label, $options, $current, $assoc])
                    <div>
                        <label class="cr-field-label">{{ $label }}</label>
                        <div class="cr-field-wrap">
                            <select name="{{ $name }}" id="{{ $name }}"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;appearance:none;cursor:pointer;">
                                @if($assoc)
                                    @foreach($assoc as $k => $row)
                                        <option value="{{ $k }}" @selected($current === $k)>{{ is_array($row) ? ($row['label'] ?? $k) : $row }}</option>
                                    @endforeach
                                @else
                                    @foreach($options as $opt)
                                        <option value="{{ $opt }}" @selected($current === $opt)>{{ ucfirst(str_replace('_',' ',$opt)) }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>
                    </div>
                    @endforeach
                    <div style="grid-column:span 2;">
                        <label class="cr-field-label">Testo primario overlay</label>
                        <div class="cr-field-wrap">
                            <input id="overlay_text" type="text" name="overlay_text" value="{{ old('overlay_text') }}"
                                placeholder="Lascia vuoto per hook automatico"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;">
                        </div>
                    </div>
                    <div>
                        <label class="cr-field-label">CTA overlay</label>
                        <div class="cr-field-wrap">
                            <input id="overlay_cta_text" type="text" name="overlay_cta_text" value="{{ $overlayCtaText }}"
                                placeholder="Automatica"
                                style="width:100%;background:transparent;border:none;outline:none;box-shadow:none;border-radius:0;padding:0;font-size:.875rem;color:#1e3a5f;">
                        </div>
                    </div>

                    {{-- Hidden overlay fields --}}
                    <input type="hidden" name="overlay_font_fallback"     value="{{ $overlayFontFallback }}">
                    <input type="hidden" name="overlay_font_weight"       value="{{ $overlayFontWeight }}">
                    <input type="hidden" name="overlay_font_size_mode"    value="{{ $overlayFontSizeMode }}">
                    <input type="hidden" name="overlay_text_case"         value="{{ $overlayTextCase }}">
                    <input type="hidden" name="overlay_alignment"         value="{{ $overlayAlignment }}">
                    <input type="hidden" name="overlay_safe_area"         value="{{ $overlaySafeArea }}">
                    <input type="hidden" name="overlay_max_lines"         value="{{ $overlayMaxLines }}">
                    <input type="hidden" name="overlay_background_style"  value="{{ $overlayBackgroundStyle }}">
                    <input type="hidden" name="overlay_animation_style"   value="{{ $overlayAnimationStyle }}">
                    <input type="hidden" name="overlay_color"             value="{{ $overlayColor }}">
                    <input type="hidden" name="overlay_stroke_color"      value="{{ $overlayStrokeColor }}">
                    <input type="hidden" name="overlay_shadow"            value="{{ $overlayShadow ? '1' : '0' }}">
                    <input type="hidden" name="overlay_secondary_text"    value="{{ old('overlay_secondary_text') }}">
                    <input type="hidden" name="overlay_timing_start_ms"   value="{{ old('overlay_timing_start_ms', 0) }}">
                    <input type="hidden" name="overlay_timing_end_ms"     value="{{ old('overlay_timing_end_ms', 0) }}">
                    <input type="hidden" name="overlay_emphasis_words"    value="{{ old('overlay_emphasis_words') }}">
                </div>
            </details>

            {{-- ── Footer azioni ── --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding-top:var(--sp-xs);">
                <button type="button" id="mode-toggle-btn"
                    style="font-size:.75rem;font-weight:600;color:var(--slate-light);background:none;border:none;cursor:pointer;padding:0;">
                    <span id="mode-toggle-label">Avanzata</span>
                </button>
                <div style="display:flex;align-items:center;gap:.625rem;">
                    <a href="{{ route('posts.index') }}" class="ui-btn-secondary">Annulla</a>
                    <button type="submit" class="ui-btn-primary" style="gap:.5rem;">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Genera con AI
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>

@include('partials.generation-loader')
<script>
function createPage() {
    return {
        mode: @json($initialMode),
        setMode(m) {
            this.mode = m;
            window.scrollTo({ top: 0, behavior: 'instant' });
            this.$nextTick(() => document.getElementById('generation_brief')?.focus());
        },
    };
}

(function () {
    const briefEl          = document.getElementById('generation_brief');
    const recognizedWrap   = document.getElementById('recognizedAssetVariables');
    const varCheckboxes    = Array.from(document.querySelectorAll('.js-asset-variable-checkbox'));
    const createForm       = document.querySelector('form[action="{{ route('posts.store') }}"]');
    const formatEl         = document.getElementById('format');
    const videoProviderEl  = document.getElementById('video_provider');
    const imageProviderEl  = document.getElementById('image_provider');
    const presenterIdEl    = document.getElementById('js-presenter-variable-id');
    const productIdEl      = document.getElementById('js-product-variable-id');
    const placeIdEl        = document.getElementById('js-place-variable-id');
    const identitySummaryEl= document.getElementById('js-identity-summary');
    const providerSummary  = document.getElementById('provider-summary-label');
    let videoProviderTouched = false;

    const normalize = v => (v||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9@\s_-]+/g,' ').replace(/\s+/g,' ').trim();
    const hasToken  = (hay, tok) => {
        if (!tok) return false;
        if ((' '+hay+' ').includes(' '+tok+' ')) return true;
        return tok.replace(/\s+/g,'') && hay.includes('@'+tok.replace(/\s+/g,''));
    };

    const syncSlots = () => {
        const checked  = varCheckboxes.filter(cb => cb.checked);
        const first    = kind => checked.find(cb => cb.dataset.variableKind === kind);
        const pr = first('person'), pd = first('product'), pl = first('location');
        if (presenterIdEl) presenterIdEl.value = pr ? pr.value : '';
        if (productIdEl)   productIdEl.value   = pd ? pd.value : '';
        if (placeIdEl)     placeIdEl.value     = pl ? pl.value : '';
        if (identitySummaryEl) {
            const parts = [];
            if (pr) parts.push('Persona: '+(pr.dataset.variableName||''));
            if (pd) parts.push('Prodotto: '+(pd.dataset.variableName||''));
            if (pl) parts.push('Luogo: '+(pl.dataset.variableName||''));
            identitySummaryEl.textContent = parts.join(' · ');
            identitySummaryEl.style.display = parts.length ? '' : 'none';
        }
    };

    const updateProviderSummary = () => {
        if (!providerSummary) return;
        const v = (videoProviderEl?.options[videoProviderEl.selectedIndex]?.text||'').split(' ')[0];
        const i = (imageProviderEl?.options[imageProviderEl.selectedIndex]?.text||'').split(' ')[0];
        providerSummary.textContent = [v,i].filter(Boolean).join(' · ') || '—';
    };

    const renderRecognized = items => {
        if (!recognizedWrap) return;
        recognizedWrap.innerHTML = '';
        items.forEach(item => {
            const chip = document.createElement('span');
            chip.style = 'display:inline-flex;align-items:center;padding:.2rem .6rem;border-radius:999px;background:rgba(10,45,111,.09);font-size:.7rem;font-weight:700;color:#0A2D6F;';
            chip.textContent = item.label;
            recognizedWrap.appendChild(chip);
        });
    };

    const syncRecognition = () => {
        if (!briefEl || !recognizedWrap || !varCheckboxes.length) return;
        const text = normalize(briefEl.value||'');
        const rec = [];
        varCheckboxes.forEach(cb => {
            const name = normalize(cb.getAttribute('data-variable-name')||'');
            const slug = normalize(cb.getAttribute('data-variable-slug')||'');
            const label = (cb.getAttribute('data-variable-name')||'').trim() || slug || 'var';
            if ((name && hasToken(text,name)) || (slug && hasToken(text,slug))) { rec.push({id:cb.value,label}); cb.checked = true; }
        });
        const sel = varCheckboxes.filter(cb=>cb.checked).map(cb=>({id:cb.value,label:(cb.getAttribute('data-variable-name')||'').trim()||(cb.getAttribute('data-variable-slug')||'').trim()||'var'}));
        const seen=new Set(), unique=[];
        [...rec,...sel].forEach(r=>{const k=String(r.id||r.label);if(!seen.has(k)){seen.add(k);unique.push(r);}});
        renderRecognized(unique);
    };

    if (briefEl && recognizedWrap && varCheckboxes.length) {
        briefEl.addEventListener('input', syncRecognition);
        varCheckboxes.forEach(cb => cb.addEventListener('change', syncRecognition));
        syncRecognition();
    }
    varCheckboxes.forEach(cb => cb.addEventListener('change', syncSlots));
    syncSlots();
    if (videoProviderEl) videoProviderEl.addEventListener('change', () => { videoProviderTouched = true; updateProviderSummary(); });
    if (imageProviderEl) imageProviderEl.addEventListener('change', updateProviderSummary);
    updateProviderSummary();

    // Advanced toggle
    (function(){
        const KEY='sa_create_mode';
        const els=()=>Array.from(document.querySelectorAll('.advanced-only'));
        const btn=document.getElementById('mode-toggle-btn');
        const lbl=document.getElementById('mode-toggle-label');
        let adv=localStorage.getItem(KEY)==='advanced';
        const apply=()=>{els().forEach(el=>{el.style.display=adv?'':'none';});if(lbl)lbl.textContent=adv?'Semplice':'Avanzata';};
        apply();
        if(btn)btn.addEventListener('click',()=>{adv=!adv;localStorage.setItem(KEY,adv?'advanced':'simple');apply();});
    })();

    // Generation loader
    if (createForm && window.HostupGenerationLoader) {
        const estimate = () => {
            const fmt=(formatEl?.value||'post').toLowerCase();
            const vp=(videoProviderEl?.value||'').toLowerCase();
            if(fmt==='reel') return vp==='runway'?150:vp==='google_veo'?185:175;
            if(fmt==='story') return vp==='runway'?120:140;
            return (imageProviderEl?.value||'').toLowerCase()==='openai'?35:55;
        };
        const stages = () => {
            const fmt=(formatEl?.value||'post').toLowerCase();
            return (fmt==='reel'||fmt==='story')
                ? ['Analisi brief e strategia','Scrittura caption e visual direction','Generazione video e rifinitura finale']
                : ['Analisi brief e brand assets','Scrittura caption e prompt visuale','Generazione immagine e controllo finale'];
        };
        createForm.addEventListener('submit', () => {
            const fmt=(formatEl?.value||'post').toLowerCase();
            window.HostupGenerationLoader.show({
                title:'Sto generando il contenuto',
                subtitle:(fmt==='reel'||fmt==='story')
                    ?'Sto preparando script, shot plan, caption e video. I reel richiedono più tempo.'
                    :'Sto preparando caption, prompt e visual in linea con il brand.',
                estimateSeconds:estimate(),stages:stages(),
            });
        });
    }
})();
</script>
@endsection
