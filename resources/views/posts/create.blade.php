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

    $defaultFormat = old('format', $isReelPreset ? 'reel' : 'post');
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
    $overlayMode          = old('overlay_mode', (bool) ($overlayPreferences['auto_enabled'] ?? true) ? 'auto' : 'off');
    $overlayPreset        = old('overlay_preset', (string) ($overlayPreferences['preset'] ?? 'modern_split_caption'));
    $overlayFontFamily    = old('overlay_font_family', (string) ($overlayPreferences['font_family'] ?? 'arial'));
    $overlayFontFallback  = old('overlay_font_fallback', (string) ($overlayPreferences['fallback_font_family'] ?? 'segoe_ui'));
    $overlayFontPreset    = (string) ($overlayPreferences['font_preset'] ?? $overlayPreferences['tone_preset'] ?? 'modern');
    $brandHookIntensity   = (string) ($overlayPreferences['preferred_hook_intensity'] ?? 'medium');
    $brandTrendAppetite   = (string) ($overlayPreferences['trend_appetite'] ?? 'medium');
    $brandProfessionalismGuardrail = (string) ($overlayPreferences['professionalism_guardrail_level'] ?? 'high');
    $overlayFontWeight    = old('overlay_font_weight', '700');
    $overlayFontSizeMode  = old('overlay_font_size_mode', $isReelPreset ? 'xl' : 'large');
    $overlayTextCase      = old('overlay_text_case', 'sentence');
    $overlayAlignment     = old('overlay_alignment', 'left');
    $overlayPosition      = old('overlay_position', $isReelPreset ? 'upper_center' : 'upper_left');
    $overlaySafeArea      = old('overlay_safe_area', (string) ($overlayPreferences['safe_area'] ?? 'upper_third'));
    $overlayMaxLines      = (int) old('overlay_max_lines', $isReelPreset ? 2 : 3);
    $overlayColor         = old('overlay_color', '#FFFFFF');
    $overlayStrokeColor   = old('overlay_stroke_color', '#111827');
    $overlayShadow        = (bool) old('overlay_shadow', true);
    $overlayBackgroundStyle  = old('overlay_background_style', $isReelPreset ? 'dark_box' : 'gradient_strip');
    $overlayAnimationStyle   = old('overlay_animation_style', $isReelPreset ? 'pop' : 'fade');
    $overlayCtaText          = old('overlay_cta_text', '');

    $defaultSchedule = old('scheduled_at', now($tz)->addHour()->startOfHour()->format('Y-m-d\\TH:i'));
    $defaultVideoDurationSeconds = (int) old('video_duration_seconds_requested', $isReelPreset ? 20 : 0);

    $referenceImages = $referenceImages ?? [];
    if ($referenceImages instanceof \Illuminate\Support\Collection) { $referenceImages = $referenceImages->values()->all(); }
    if (!is_array($referenceImages)) { $referenceImages = []; }
    $selectedReferenceIds = old('reference_asset_ids', []);
    if (!is_array($selectedReferenceIds)) { $selectedReferenceIds = []; }
    $selectedReferenceIds = array_values(array_unique(array_map(fn($v) => (int)$v, array_filter($selectedReferenceIds, fn($v) => (int)$v > 0))));

    $assetVariables    = is_array($assetVariables ?? null) ? $assetVariables : [];
    $selectedVariableIds = old('asset_variable_ids', []);
    if (!is_array($selectedVariableIds)) { $selectedVariableIds = []; }
    $selectedVariableIds = array_values(array_unique(array_map(fn($v) => (int)$v, array_filter($selectedVariableIds, fn($v) => (int)$v > 0))));
    $selectedPresenterId    = (int) old('presenter_variable_id', 0);
    $selectedProductId      = (int) old('product_variable_id', 0);
    $selectedPlaceId        = (int) old('place_variable_id', 0);
    $selectedConsistencyMode = old('consistency_mode', 'balanced');
    $seasonalOverlay        = old('seasonal_overlay', '');

    $initialMode = $isReelPreset ? 'reel' : (old('format') ?: '');
@endphp

<style>
.cr-type {
    display: flex; flex-direction: column; align-items: flex-start;
    padding: 1.25rem 1.25rem 1rem;
    border: 1px solid rgba(10,45,111,0.1);
    border-radius: 1.25rem;
    background: #fff;
    cursor: pointer;
    transition: border-color 150ms, background 150ms;
    text-align: left; width: 100%;
}
.cr-type:hover { border-color: rgba(10,45,111,0.25); background: rgba(248,250,255,0.8); }
.cr-type.active { border-color: #0A2D6F; background: rgba(10,45,111,0.04); }
.cr-card { border: 1px solid rgba(10,45,111,0.08); border-radius: 1.25rem; background: #fff; }
.cr-input {
    width: 100%; background: transparent; outline: none;
    border: none; box-shadow: none; padding: 0;
    font-size: .875rem; color: #1e3a5f;
}
.cr-select {
    width: 100%; border: none; outline: none; box-shadow: none;
    background: transparent; font-size: .875rem; color: #1e3a5f;
    padding: 0; appearance: none; cursor: pointer;
}
.cr-field {
    background: rgba(10,45,111,0.03);
    border-radius: .875rem;
    padding: .625rem .875rem;
}
</style>

<div
    x-data="createPage()"
    class="w-full px-4 py-6 sm:px-6 lg:px-8"
    style="max-width:860px;margin:0 auto;"
>

    {{-- ── Header ── --}}
    <div style="margin-bottom:1.5rem;">
        <p class="text-xs font-semibold uppercase tracking-widest" style="color:#94a3b8;letter-spacing:.15em;">Crea</p>
        <h1 class="mt-1 text-2xl font-semibold" style="color:#0A2D6F;">Nuovo contenuto</h1>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" style="margin-bottom:1.25rem;">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── Scelta tipo ── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" style="margin-bottom:1.75rem;">

        {{-- Post --}}
        <button type="button" @click="setMode('post')" :class="mode === 'post' && 'active'" class="cr-type">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl" style="background:rgba(10,45,111,0.07);">
                <svg class="h-5 w-5" style="color:#0A2D6F;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" stroke-width="1.5"/><path stroke-linecap="round" stroke-width="1.5" d="M3 9h18M9 21V9"/></svg>
            </div>
            <p class="mt-3 text-sm font-semibold" style="color:#0A2D6F;">Post</p>
            <p class="mt-0.5 text-xs" style="color:#64748b;">Immagine o carosello</p>
        </button>

        {{-- Reel --}}
        <button type="button" @click="setMode('reel')" :class="mode === 'reel' && 'active'" class="cr-type">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl" style="background:rgba(10,45,111,0.07);">
                <svg class="h-5 w-5" style="color:#0A2D6F;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </div>
            <p class="mt-3 text-sm font-semibold" style="color:#0A2D6F;">Reel</p>
            <p class="mt-0.5 text-xs" style="color:#64748b;">Video con script e shot plan</p>
        </button>

        {{-- Piano editoriale --}}
        <a href="{{ route('wizard.start') }}" class="cr-type">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl" style="background:rgba(10,45,111,0.07);">
                <svg class="h-5 w-5" style="color:#0A2D6F;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <p class="mt-3 text-sm font-semibold" style="color:#0A2D6F;">Piano editoriale</p>
            <p class="mt-0.5 text-xs" style="color:#64748b;">Sequenza di contenuti organizzati</p>
        </a>
    </div>

    {{-- ── Form (appare dopo la scelta) ── --}}
    <div x-show="mode !== ''" x-transition.opacity style="display:none;">
        <form id="single-content-form" method="POST" action="{{ route('posts.store') }}" class="space-y-3">
            @csrf

            {{-- Format hidden (guidato dalla scelta sopra) --}}
            <input type="hidden" id="format" name="format" :value="mode">
            <input type="hidden" name="presenter_variable_id" id="js-presenter-variable-id" value="{{ $selectedPresenterId ?: '' }}">
            <input type="hidden" name="product_variable_id" id="js-product-variable-id" value="{{ $selectedProductId ?: '' }}">
            <input type="hidden" name="place_variable_id" id="js-place-variable-id" value="{{ $selectedPlaceId ?: '' }}">
            <input type="hidden" name="consistency_mode" id="js-consistency-mode" value="{{ $selectedConsistencyMode }}">
            <input type="hidden" name="seasonal_overlay" id="js-seasonal-overlay" value="{{ $seasonalOverlay }}">

            {{-- Brief --}}
            <div class="cr-card" style="padding:1.25rem;">
                <label class="mb-2 block text-sm font-semibold" style="color:#0A2D6F;">Descrivi cosa vuoi generare</label>
                <textarea
                    id="generation_brief"
                    name="generation_brief"
                    rows="5"
                    class="cr-input"
                    style="resize:none;"
                    placeholder="Es. Un post per Instagram che mostri l'aperitivo del venerdì sera, atmosfera calda, pubblico giovane adulto…"
                    required
                >{{ old('generation_brief', old('caption', old('title', ''))) }}</textarea>

                {{-- Variabili riconosciute --}}
                @if(!empty($assetVariables))
                <div id="recognizedAssetVariables" class="mt-3 flex flex-wrap gap-1.5 text-xs" style="color:#475569;min-height:1.2rem;"></div>
                @endif
            </div>

            {{-- Data + Piattaforme --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="cr-card" style="padding:1rem 1.25rem;">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.08em;">Programmazione</label>
                    <div class="cr-field">
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ $defaultSchedule }}"
                            class="cr-input" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;" required />
                    </div>
                </div>
                <div class="cr-card" style="padding:1rem 1.25rem;">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide" style="color:#94a3b8;letter-spacing:.08em;">Piattaforme</label>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($platformOptions as $key => $label)
                            <label class="flex cursor-pointer items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold transition" style="background:rgba(10,45,111,0.05);color:#475569;">
                                <input type="checkbox" name="platforms[]" value="{{ $key }}" @checked(in_array($key, $defaultPlatforms, true))
                                    class="h-3 w-3 accent-[#0A2D6F]">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Riferimenti immagine (collassato) --}}
            @if(!empty($referenceImages))
            <details class="cr-card" style="padding:0;">
                <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 text-sm font-semibold" style="color:#0A2D6F;">
                    <span>Immagini di riferimento</span>
                    <span class="text-xs font-normal" style="color:#94a3b8;">{{ count($referenceImages) }} disponibili</span>
                </summary>
                <div style="border-top:1px solid rgba(10,45,111,0.07);padding:1rem 1.25rem;">
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                        @foreach($referenceImages as $img)
                        @php
                            $assetId  = (int) ($img['id'] ?? 0);
                            $refNum   = (int) ($img['ref_number'] ?? ($loop->index + 1));
                            $assetPath = (string) ($img['path'] ?? '');
                            $assetName = trim((string) ($img['original_name'] ?? ''));
                            $isChecked = $assetId > 0 && in_array($assetId, $selectedReferenceIds, true);
                        @endphp
                        <label class="group relative overflow-hidden rounded-xl border cursor-pointer" style="border-color:rgba(10,45,111,0.1);">
                            <div class="absolute left-1.5 top-1.5 z-10 rounded-full px-1.5 py-0.5 text-[10px] font-bold text-white" style="background:rgba(7,29,78,0.8);">#{{ $refNum }}</div>
                            <div class="absolute right-1.5 top-1.5 z-10 rounded-md bg-white p-0.5">
                                <input type="checkbox" name="reference_asset_ids[]" value="{{ $assetId }}" class="h-3 w-3 accent-[#0A2D6F]" @checked($isChecked) />
                            </div>
                            <div class="aspect-square overflow-hidden" style="background:rgba(10,45,111,0.05);">
                                <img src="{{ asset('storage/' . ltrim($assetPath, '/')) }}" alt="#{{ $refNum }}" class="h-full w-full object-cover" loading="lazy">
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
            </details>
            @endif

            {{-- Asset e identità (collassato) --}}
            @if(!empty($assetVariables))
            <details class="cr-card advanced-only" style="padding:0;">
                <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 text-sm font-semibold" style="color:#0A2D6F;">
                    <span>Asset e identità brand</span>
                    <span class="text-xs font-normal" style="color:#94a3b8;">{{ count($assetVariables) }} disponibili</span>
                </summary>
                <div style="border-top:1px solid rgba(10,45,111,0.07);padding:1rem 1.25rem;">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($assetVariables as $var)
                        @php
                            $varId   = (int) ($var['id'] ?? 0);
                            $varName = (string) ($var['name'] ?? '');
                            $varSlug = (string) ($var['slug'] ?? '');
                            $varKind = (string) ($var['kind'] ?? 'custom');
                            $varDesc = trim((string) ($var['description'] ?? ''));
                            $checked = $varId > 0 && in_array($varId, $selectedVariableIds, true);
                            $kindColors = ['person' => '#7c3aed', 'product' => '#0f9f6e', 'location' => '#b45309'];
                            $kindLabels = ['person' => 'Persona', 'product' => 'Prodotto', 'location' => 'Luogo'];
                        @endphp
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl px-3 py-2.5 transition" style="border:1px solid rgba(10,45,111,0.08);background:#fff;">
                            <input type="checkbox" name="asset_variable_ids[]" value="{{ $varId }}"
                                class="mt-0.5 h-3.5 w-3.5 accent-[#0A2D6F] js-asset-variable-checkbox"
                                data-variable-id="{{ $varId }}"
                                data-variable-name="{{ strtolower($varName) }}"
                                data-variable-slug="{{ strtolower($varSlug) }}"
                                data-variable-kind="{{ $varKind }}"
                                @checked($checked)>
                            <span>
                                <span class="block text-sm font-semibold" style="color:#1e3a5f;">{{ $varName }}</span>
                                <span class="text-[11px] font-semibold" style="color:{{ $kindColors[$varKind] ?? '#64748b' }};">{{ $kindLabels[$varKind] ?? $varKind }}</span>
                                @if($varDesc) <span class="ml-1 text-xs" style="color:#94a3b8;">· {{ $varDesc }}</span>@endif
                            </span>
                        </label>
                        @endforeach
                    </div>
                    <div id="js-identity-summary" class="mt-2 hidden rounded-xl px-3 py-2 text-xs font-medium" style="background:rgba(10,45,111,0.05);color:#475569;"></div>
                </div>
            </details>
            @endif

            {{-- Alter Ego (collassato) --}}
            @php $alterEgos = $alterEgos ?? collect(); $defaultAlterEgoId = (int) old('alter_ego_id', 0); @endphp
            @if($alterEgos->isNotEmpty())
            <details class="cr-card advanced-only" style="padding:0;">
                <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 text-sm font-semibold" style="color:#0A2D6F;">
                    <span>Alter Ego</span>
                    <span class="text-xs font-normal" style="color:#94a3b8;">Voce narrativa</span>
                </summary>
                <div style="border-top:1px solid rgba(10,45,111,0.07);padding:1rem 1.25rem;">
                    <div class="cr-field">
                        <select name="alter_ego_id" id="alter_ego_id" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
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

            {{-- Motori AI + Overlay (collassati, advanced) --}}
            <details class="cr-card advanced-only" id="provider-settings-details" style="padding:0;">
                <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 text-sm font-semibold" style="color:#0A2D6F;">
                    <span>Motori AI</span>
                    <span class="text-xs font-normal" id="provider-summary-label" style="color:#94a3b8;">—</span>
                </summary>
                <div style="border-top:1px solid rgba(10,45,111,0.07);padding:1rem 1.25rem;">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Motore immagini</label>
                            <div class="cr-field">
                                <select id="image_provider" name="image_provider" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach($imageProviderOptions as $key => $label)
                                        <option value="{{ $key }}" @selected($defaultImageProvider === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Motore video</label>
                            <div class="cr-field">
                                <select id="video_provider" name="video_provider" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach($videoProviderOptions as $key => $label)
                                        <option value="{{ $key }}" @selected($defaultVideoProvider === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div x-show="mode === 'reel'">
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Durata video (secondi)</label>
                            <div class="cr-field">
                                <input id="video_duration_seconds_requested" type="number" name="video_duration_seconds_requested"
                                    min="4" max="45" step="1" value="{{ $defaultVideoDurationSeconds > 0 ? $defaultVideoDurationSeconds : 20 }}"
                                    class="cr-input" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <details class="cr-card advanced-only" id="overlay-settings-details" style="padding:0;">
                <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 text-sm font-semibold" style="color:#0A2D6F;">
                    <span>Text overlay e tipografia</span>
                    <span class="text-xs font-normal" style="color:#94a3b8;">Auto</span>
                </summary>
                <div style="border-top:1px solid rgba(10,45,111,0.07);padding:1rem 1.25rem;">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Overlay</label>
                            <div class="cr-field">
                                <select id="overlay_mode" name="overlay_mode" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach(($overlayUi['modes'] ?? ['auto','manual','off']) as $m)
                                        <option value="{{ $m }}" @selected($overlayMode === $m)>{{ ucfirst($m) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Preset</label>
                            <div class="cr-field">
                                <select id="overlay_preset" name="overlay_preset" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach($overlayPresets as $key => $row)
                                        <option value="{{ $key }}" @selected($overlayPreset === $key)>{{ $row['label'] ?? $key }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Font</label>
                            <div class="cr-field">
                                <select id="overlay_font_family" name="overlay_font_family" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach($overlayFonts as $key => $row)
                                        <option value="{{ $key }}" @selected($overlayFontFamily === $key)>{{ $row['label'] ?? $key }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Posizione</label>
                            <div class="cr-field">
                                <select id="overlay_position" name="overlay_position" class="cr-select" style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                                    @foreach(($overlayUi['positions'] ?? ['upper_left','upper_center','center_left','center','lower_left','lower_center']) as $pos)
                                        <option value="{{ $pos }}" @selected($overlayPosition === $pos)>{{ ucfirst(str_replace('_',' ',$pos)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">Testo primario</label>
                            <div class="cr-field">
                                <input id="overlay_text" type="text" name="overlay_text" value="{{ old('overlay_text') }}"
                                    placeholder="Lascia vuoto per hook automatico" class="cr-input"
                                    style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold" style="color:#64748b;">CTA overlay</label>
                            <div class="cr-field">
                                <input id="overlay_cta_text" type="text" name="overlay_cta_text" value="{{ $overlayCtaText }}"
                                    placeholder="Lascia vuoto per CTA automatica" class="cr-input"
                                    style="border:none;outline:none;box-shadow:none;border-radius:0;padding:0;background:transparent;">
                            </div>
                        </div>
                    </div>

                    {{-- Hidden overlay fields --}}
                    <input type="hidden" name="overlay_font_fallback" value="{{ $overlayFontFallback }}">
                    <input type="hidden" name="overlay_font_weight" value="{{ $overlayFontWeight }}">
                    <input type="hidden" name="overlay_font_size_mode" value="{{ $overlayFontSizeMode }}">
                    <input type="hidden" name="overlay_text_case" value="{{ $overlayTextCase }}">
                    <input type="hidden" name="overlay_alignment" value="{{ $overlayAlignment }}">
                    <input type="hidden" name="overlay_safe_area" value="{{ $overlaySafeArea }}">
                    <input type="hidden" name="overlay_max_lines" value="{{ $overlayMaxLines }}">
                    <input type="hidden" name="overlay_background_style" value="{{ $overlayBackgroundStyle }}">
                    <input type="hidden" name="overlay_animation_style" value="{{ $overlayAnimationStyle }}">
                    <input type="hidden" name="overlay_color" value="{{ $overlayColor }}">
                    <input type="hidden" name="overlay_stroke_color" value="{{ $overlayStrokeColor }}">
                    <input type="hidden" name="overlay_shadow" value="{{ $overlayShadow ? '1' : '0' }}">
                    <input type="hidden" name="overlay_secondary_text" value="{{ old('overlay_secondary_text') }}">
                    <input type="hidden" name="overlay_timing_start_ms" value="{{ old('overlay_timing_start_ms', 0) }}">
                    <input type="hidden" name="overlay_timing_end_ms" value="{{ old('overlay_timing_end_ms', 0) }}">
                    <input type="hidden" name="overlay_emphasis_words" value="{{ old('overlay_emphasis_words') }}">
                </div>
            </details>

            {{-- Obiettivo (hidden, passato silenziosamente) --}}
            <input type="hidden" name="goal_hint" value="{{ old('goal_hint', $profile?->default_goal ?: '') }}">

            {{-- Azioni --}}
            <div class="flex items-center justify-between pt-1">
                <div class="flex items-center gap-3">
                    <button type="button" id="mode-toggle-btn" class="text-xs font-semibold" style="color:#94a3b8;">
                        <span id="mode-toggle-label">Avanzata</span>
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('posts.index') }}" class="ui-btn-secondary text-sm">Annulla</a>
                    <button type="submit" class="ui-btn-primary gap-2">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
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
            this.$nextTick(() => {
                document.getElementById('generation_brief')?.focus();
            });
        },
    };
}

(function () {
    const briefEl = document.getElementById('generation_brief');
    const recognizedWrap = document.getElementById('recognizedAssetVariables');
    const variableCheckboxes = Array.from(document.querySelectorAll('.js-asset-variable-checkbox'));
    const createForm = document.querySelector('form[action="{{ route('posts.store') }}"]');
    const formatEl = document.getElementById('format');
    const videoProviderEl = document.getElementById('video_provider');
    const imageProviderEl = document.getElementById('image_provider');
    const presenterIdEl = document.getElementById('js-presenter-variable-id');
    const productIdEl = document.getElementById('js-product-variable-id');
    const placeIdEl = document.getElementById('js-place-variable-id');
    const identitySummaryEl = document.getElementById('js-identity-summary');
    const providerSummaryLabel = document.getElementById('provider-summary-label');
    let videoProviderTouched = false;

    const normalize = (v) => (v||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9@\s_-]+/g,' ').replace(/\s+/g,' ').trim();
    const containsToken = (hay, token) => {
        if (!token) return false;
        if ((' '+hay+' ').includes(' '+token+' ')) return true;
        const compact = token.replace(/\s+/g,'');
        return compact && hay.includes('@'+compact);
    };

    const syncIdentitySlots = () => {
        const checked = variableCheckboxes.filter(cb => cb.checked);
        const first = (kind) => checked.find(cb => cb.dataset.variableKind === kind);
        const presenter = first('person'), product = first('product'), place = first('location');
        if (presenterIdEl) presenterIdEl.value = presenter ? presenter.value : '';
        if (productIdEl)   productIdEl.value   = product   ? product.value   : '';
        if (placeIdEl)     placeIdEl.value     = place     ? place.value     : '';
        if (identitySummaryEl) {
            const parts = [];
            if (presenter) parts.push('Persona: '+(presenter.dataset.variableName||''));
            if (product)   parts.push('Prodotto: '+(product.dataset.variableName||''));
            if (place)     parts.push('Luogo: '+(place.dataset.variableName||''));
            identitySummaryEl.textContent = parts.join(' · ');
            identitySummaryEl.classList.toggle('hidden', parts.length === 0);
        }
    };

    const updateProviderSummary = () => {
        if (!providerSummaryLabel) return;
        const video = (videoProviderEl?.options[videoProviderEl.selectedIndex]?.text||'').split(' ')[0];
        const image = (imageProviderEl?.options[imageProviderEl.selectedIndex]?.text||'').split(' ')[0];
        providerSummaryLabel.textContent = [video, image].filter(Boolean).join(' · ') || '—';
    };

    const renderRecognized = (items) => {
        if (!recognizedWrap) return;
        recognizedWrap.innerHTML = '';
        if (!items.length) return;
        items.forEach(item => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold';
            chip.style = 'background:rgba(10,45,111,0.08);color:#0A2D6F;';
            chip.textContent = item.label;
            recognizedWrap.appendChild(chip);
        });
    };

    const syncRecognition = () => {
        if (!briefEl || !recognizedWrap || !variableCheckboxes.length) return;
        const text = normalize(briefEl.value||'');
        const recognized = [];
        variableCheckboxes.forEach(cb => {
            const name = normalize(cb.getAttribute('data-variable-name')||'');
            const slug = normalize(cb.getAttribute('data-variable-slug')||'');
            const label = (cb.getAttribute('data-variable-name')||'').trim() || slug || 'variabile';
            if ((name && containsToken(text,name)) || (slug && containsToken(text,slug))) {
                recognized.push({ id: cb.value, label });
                cb.checked = true;
            }
        });
        const selected = variableCheckboxes.filter(cb => cb.checked).map(cb => ({
            id: cb.value,
            label: (cb.getAttribute('data-variable-name')||'').trim() || (cb.getAttribute('data-variable-slug')||'').trim() || 'variabile',
        }));
        const seen = new Set(), unique = [];
        [...recognized, ...selected].forEach(r => { const k = String(r.id||r.label); if (!seen.has(k)) { seen.add(k); unique.push(r); } });
        renderRecognized(unique);
    };

    if (briefEl && recognizedWrap && variableCheckboxes.length) {
        briefEl.addEventListener('input', syncRecognition);
        variableCheckboxes.forEach(cb => cb.addEventListener('change', syncRecognition));
        syncRecognition();
    }
    variableCheckboxes.forEach(cb => cb.addEventListener('change', syncIdentitySlots));
    syncIdentitySlots();
    if (videoProviderEl) videoProviderEl.addEventListener('change', () => { videoProviderTouched = true; updateProviderSummary(); });
    if (imageProviderEl) imageProviderEl.addEventListener('change', updateProviderSummary);
    updateProviderSummary();

    // Simple / Advanced toggle
    (function () {
        const STORAGE_KEY = 'sa_create_mode';
        const advancedEls = () => Array.from(document.querySelectorAll('.advanced-only'));
        const toggleBtn   = document.getElementById('mode-toggle-btn');
        const toggleLabel = document.getElementById('mode-toggle-label');
        let isAdvanced = localStorage.getItem(STORAGE_KEY) === 'advanced';
        const apply = () => {
            advancedEls().forEach(el => { el.style.display = isAdvanced ? '' : 'none'; });
            if (toggleLabel) toggleLabel.textContent = isAdvanced ? 'Semplice' : 'Avanzata';
        };
        apply();
        if (toggleBtn) toggleBtn.addEventListener('click', () => {
            isAdvanced = !isAdvanced;
            localStorage.setItem(STORAGE_KEY, isAdvanced ? 'advanced' : 'simple');
            apply();
        });
    })();

    // Generation loader
    if (createForm && window.HostupGenerationLoader) {
        const estimateSeconds = () => {
            const format = (formatEl?.value||'post').toLowerCase();
            const vp = (videoProviderEl?.value||'').toLowerCase();
            if (format === 'reel') return vp === 'runway' ? 150 : vp === 'google_veo' ? 185 : 175;
            if (format === 'story') return vp === 'runway' ? 120 : 140;
            return (imageProviderEl?.value||'').toLowerCase() === 'openai' ? 35 : 55;
        };
        const buildStages = () => {
            const format = (formatEl?.value||'post').toLowerCase();
            return format === 'reel' || format === 'story'
                ? ['Analisi brief e strategia','Scrittura caption e visual direction','Generazione video e rifinitura finale']
                : ['Analisi brief e brand assets','Scrittura caption e prompt visuale','Generazione immagine e controllo finale'];
        };
        if (videoProviderEl) videoProviderEl.addEventListener('change', () => { videoProviderTouched = true; });
        createForm.addEventListener('submit', () => {
            const format = (formatEl?.value||'post').toLowerCase();
            window.HostupGenerationLoader.show({
                title: 'Sto generando il contenuto',
                subtitle: format === 'reel' || format === 'story'
                    ? 'Sto preparando script, shot plan, caption e video finale. I reel richiedono più tempo.'
                    : 'Sto preparando caption, prompt e visual finale in linea con il brand.',
                estimateSeconds: estimateSeconds(),
                stages: buildStages(),
            });
        });
    }
})();
</script>
@endsection
