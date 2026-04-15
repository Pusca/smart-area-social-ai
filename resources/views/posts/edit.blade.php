@extends('layouts.app')

@section('content')
@php
    $metaVideoPath = trim((string) data_get($contentItem->ai_meta, 'video_generation.video_path', ''));
    $assetList = is_array($contentItem->assets ?? null) ? $contentItem->assets : [];
    $videoPath = $metaVideoPath !== '' ? $metaVideoPath : null;
    if ($videoPath === null) {
        foreach ($assetList as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $assetPath = trim((string) ($asset['path'] ?? ''));
            if ($assetPath === '') {
                continue;
            }
            $assetType = strtolower(trim((string) ($asset['type'] ?? '')));
            $ext = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
            if (str_contains($assetType, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
                $videoPath = $assetPath;
                break;
            }
        }
    }

    $aiInfo = \App\Support\UiStatus::ai((string) $contentItem->ai_status);
    $publicationInfo = \App\Support\UiStatus::publication((string) $contentItem->status);
    $publicationOptions = \App\Support\UiStatus::publicationOptions();

    $videoProviderOptions = [
        'openai' => 'Sora + GPT (OpenAI)',
        'runway' => 'Runway',
        'google_veo' => 'Google Veo 3.1 (direct)',
        'kling' => 'Kling (coerenza persona)',
    ];
    $imageProviderOptions = [
        'nanobanana' => 'Nano Banana (consigliato)',
        'openai' => 'GPT Image (OpenAI)',
    ];
    $videoProviderValue = old('video_provider', (string) data_get($contentItem->ai_meta, 'video_provider', config('generation.video_provider_default', 'openai')));
    if (!array_key_exists($videoProviderValue, $videoProviderOptions)) {
        $videoProviderValue = 'openai';
    }
    $imageProviderValue = old('image_provider', (string) data_get($contentItem->ai_meta, 'image_provider', config('generation.image_provider_default', 'nanobanana')));
    if (!array_key_exists($imageProviderValue, $imageProviderOptions)) {
        $imageProviderValue = 'nanobanana';
    }
    $videoProviderRequested = (string) data_get($contentItem->ai_meta, 'video_generation.provider_requested', data_get($contentItem->ai_meta, 'video_provider_requested', $videoProviderValue));
    $videoProviderLastUsed = (string) data_get($contentItem->ai_meta, 'video_provider_last_used', $videoProviderValue);
    $videoProviderEffective = (string) data_get($contentItem->ai_meta, 'video_generation.provider_effective', data_get($contentItem->ai_meta, 'video_generation.provider', $videoProviderLastUsed));
    $videoProviderLabels = [
        'openai' => 'Sora + GPT',
        'runway' => 'Runway',
        'google_veo' => 'Google Veo 3.1',
        'kling' => 'Kling',
    ];
    $videoProviderFallback = data_get($contentItem->ai_meta, 'video_generation.provider_fallback');
    $videoDurationSeconds = (int) old('video_duration_seconds_requested', (int) data_get($contentItem->ai_meta, 'requested_video_duration_seconds', strtolower(trim((string) $contentItem->format)) === 'reel' ? 20 : 0));
    $isVideoFormat = in_array(strtolower(trim((string) $contentItem->format)), ['reel', 'story'], true);
    $feedbackEntries = $contentItem->feedbackEntries ?? collect();
    $latestFeedback = $feedbackEntries->first();
    $feedbackCategories = \App\Models\ContentFeedbackEntry::selectableCategoryLabels();
    $feedbackSeverityOptions = \App\Models\ContentFeedbackEntry::SEVERITY_LABELS;
    $activeFeedbackSentiment = $latestFeedback?->sentiment;
    $feedbackSentiment = old('sentiment', 'like');
    $feedbackAction = old('action', 'record_only');
    $activeFeedbackRequest = data_get($contentItem->ai_meta, 'feedback_loop.active_request');
    $lastAppliedFeedback = data_get($contentItem->ai_meta, 'feedback_loop.last_applied');
    $shouldOpenFeedbackModal = old('sentiment') === 'dislike' || $errors->has('category') || $errors->has('reason');
    $alterEgoConsistency = is_array(data_get($contentItem->ai_meta, 'alter_ego_consistency')) ? (array) data_get($contentItem->ai_meta, 'alter_ego_consistency') : [];
    $qualityScorecard = is_array(data_get($contentItem->ai_meta, 'quality_scorecard')) ? (array) data_get($contentItem->ai_meta, 'quality_scorecard') : [];
    $qualityScoreSources = is_array(data_get($qualityScorecard, 'score_sources')) ? (array) data_get($qualityScorecard, 'score_sources') : [];
    $qualityStatus = (string) ($qualityScorecard['publish_readiness_status'] ?? '');
    $qualityBadge = match ($qualityStatus) {
        'pass' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'pass_with_warnings' => 'border-amber-200 bg-amber-50 text-amber-700',
        'manual_review_required' => 'border-orange-200 bg-orange-50 text-orange-700',
        'blocked' => 'border-red-200 bg-red-50 text-red-700',
        default => 'border-gray-200 bg-gray-50 text-gray-700',
    };
    $qualityScores = [
        'brand_voice_score' => 'Brand voice',
        'visual_identity_score' => 'Visual identity',
        'cta_compliance_score' => 'CTA compliance',
        'reference_match_score' => 'Reference match',
        'realism_score' => 'Realism',
        'caption_quality_score' => 'Caption quality',
        'professionalism_score' => 'Professionalism',
        'trend_relevance_score' => 'Trend relevance',
        'trend_brand_fit_score' => 'Trend brand fit',
        'hook_strength_score' => 'Hook strength',
        'first_seconds_strength_score' => 'First seconds',
        'overlay_readability_score' => 'Overlay readability',
        'mobile_legibility_score' => 'Mobile legibility',
        'viral_readiness_score' => 'Viral readiness',
    ];
    $overlayMeta = is_array(data_get($contentItem->ai_meta, 'overlay_meta')) ? (array) data_get($contentItem->ai_meta, 'overlay_meta') : [];
    $overlaySettings = is_array(data_get($contentItem->ai_meta, 'overlay_settings')) ? (array) data_get($contentItem->ai_meta, 'overlay_settings') : [];
    $overlayBrandPreferences = is_array(data_get($contentItem->ai_meta, 'tenant_profile.overlay_preferences')) ? (array) data_get($contentItem->ai_meta, 'tenant_profile.overlay_preferences') : [];
    $overlayFonts = (array) config('overlays.fonts', []);
    $overlayPresets = (array) config('overlays.presets', []);
    $overlayUi = (array) config('overlays.ui', []);
    $overlayMode = old('overlay_mode', (string) ($overlaySettings['mode'] ?? ((bool) ($overlayBrandPreferences['auto_enabled'] ?? true) ? 'auto' : 'off')));
    $overlayPreset = old('overlay_preset', (string) ($overlaySettings['preset'] ?? ($overlayBrandPreferences['preset'] ?? 'modern_split_caption')));
    $overlayManual = is_array($overlaySettings['manual_override'] ?? null) ? $overlaySettings['manual_override'] : [];
    $overlayFontPreset = (string) ($overlayBrandPreferences['font_preset'] ?? $overlayBrandPreferences['tone_preset'] ?? 'modern');
    $brandHookIntensity = (string) ($overlayBrandPreferences['preferred_hook_intensity'] ?? 'medium');
    $brandTrendAppetite = (string) ($overlayBrandPreferences['trend_appetite'] ?? 'medium');
    $brandProfessionalismGuardrail = (string) ($overlayBrandPreferences['professionalism_guardrail_level'] ?? 'high');
    $overlayCtaText = old('overlay_cta_text', (string) ($overlayManual['cta_text'] ?? data_get($overlayMeta, 'caption_card_final', '')));
@endphp

<style>
    .post-preview-media {
        display: block;
        width: auto;
        max-width: 100%;
        height: auto;
        max-height: 72vh;
        margin-inline: auto;
    }

    @media (min-width: 1024px) {
        .post-preview-media {
            max-height: 56vh;
        }
    }
</style>

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Content Editor</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Modifica contenuto</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Aggiorna pianificazione, caption, prompt immagine e stato AI del contenuto.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ strtoupper((string) $contentItem->platform) }} - {{ strtoupper((string) $contentItem->format) }}
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $aiInfo['badge'] }}">
                        AI {{ $aiInfo['label'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $publicationInfo['badge'] }}">
                        {{ $publicationInfo['label'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        Video richiesto: {{ $videoProviderLabels[$videoProviderRequested] ?? ($videoProviderLabels[$videoProviderValue] ?? 'Video AI') }}
                    </span>
                    @if($videoProviderEffective !== '' && $videoProviderEffective !== $videoProviderRequested)
                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                            Video effettivo: {{ $videoProviderLabels[$videoProviderEffective] ?? 'Video AI' }}
                        </span>
                    @endif
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        Immagini: {{ $imageProviderValue === 'openai' ? 'GPT Image' : 'Nano Banana' }}
                    </span>
                    @if($contentItem->scheduled_at)
                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ $contentItem->scheduled_at->format('d/m H:i') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri libreria
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Vai alla pianificazione
                    </a>
                    <a href="{{ route('posts.create') }}" class="ui-btn-primary justify-center">
                        Apri area crea
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Controlla i campi:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border {{ $aiInfo['badge'] }} px-4 py-3 text-sm">
        <p class="font-semibold">Stato AI: {{ $aiInfo['label'] }}</p>
        <p class="mt-1 text-xs opacity-90">{{ $aiInfo['description'] }}</p>
        @if(!empty($contentItem->ai_error))
            <p class="mt-2 text-xs">
                Dettaglio tecnico: {{ \Illuminate\Support\Str::limit((string) $contentItem->ai_error, 220, '') }}
            </p>
        @endif
        @if(is_array($videoProviderFallback) && !empty($videoProviderFallback))
            <p class="mt-2 text-xs">
                Fallback video applicato: {{ ($videoProviderFallback['from'] ?? 'provider') }} -> {{ ($videoProviderFallback['to'] ?? 'provider') }}.
                Motivo: {{ \Illuminate\Support\Str::limit((string) ($videoProviderFallback['reason'] ?? ''), 180, '') }}
            </p>
        @endif
    </div>

    @if(!empty($alterEgoConsistency))
    @php
        $aeScore    = (int) ($alterEgoConsistency['score'] ?? 0);
        $aeBadge    = \App\Services\AlterEgoConsistencyService::scoreBadgeClass($aeScore);
        $aeLabel    = \App\Services\AlterEgoConsistencyService::scoreLabel($aeScore);
        $aeName     = (string) ($alterEgoConsistency['alter_ego_name'] ?? '');
        $aeFeedback = (string) ($alterEgoConsistency['feedback'] ?? '');
    @endphp
    <div class="rounded-2xl border border-indigo-100 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50">
                    <svg class="h-5 w-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zm-4 7a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-900">Alter Ego{{ $aeName ? ': ' . $aeName : '' }}</p>
                    @if($aeFeedback)
                        <p class="mt-0.5 text-xs text-gray-500">{{ $aeFeedback }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-950">{{ $aeScore }}<span class="text-sm font-normal text-gray-400">/100</span></p>
                    <p class="text-xs text-gray-500">Aderenza voce</p>
                </div>
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $aeBadge }}">{{ $aeLabel }}</span>
            </div>
        </div>
        @php
            $aeAspects = [
                'tone_score'       => 'Tono',
                'vocabulary_score' => 'Vocabolario',
                'style_score'      => 'Stile frasi',
            ];
        @endphp
        <div class="mt-4 grid grid-cols-3 gap-2">
            @foreach($aeAspects as $key => $label)
                @php $val = (int) ($alterEgoConsistency[$key] ?? 0); @endphp
                <div class="rounded-xl border border-indigo-50 bg-indigo-50/50 px-3 py-2 text-center">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="mt-0.5 text-sm font-bold text-indigo-700">{{ $val }}%</p>
                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-indigo-100">
                        <div class="h-full rounded-full bg-indigo-400" style="width: {{ $val }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(!empty($qualityScorecard))
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Quality scorecard</h2>
                    <p class="mt-1 text-sm text-gray-600">Valutazione finale di qualita e publish readiness basata sui segnali reali salvati nel run.</p>
                </div>
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $qualityBadge }}">
                    {{ $qualityStatus !== '' ? $qualityStatus : 'n/a' }}
                </span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($qualityScores as $scoreKey => $label)
                    @php
                        $score = data_get($qualityScorecard, $scoreKey);
                        $scoreSource = is_array(data_get($qualityScoreSources, $scoreKey)) ? (array) data_get($qualityScoreSources, $scoreKey) : [];
                    @endphp
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">
                            {{ is_numeric($score) ? number_format(((float) $score) * 100, 0) . '%' : '-' }}
                        </div>
                        <div class="mt-1 text-[11px] text-gray-500">
                            {{ (string) ($scoreSource['mode'] ?? 'missing') }} | {{ (string) ($scoreSource['source'] ?? 'n/a') }}
                        </div>
                    </div>
                @endforeach
            </div>

            @if(!empty($qualityScorecard['warnings']))
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-900">Warnings</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800">
                        @foreach((array) $qualityScorecard['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($qualityScorecard['blocking_reasons']))
                <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-sm font-semibold text-red-900">Blocking reasons</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-800">
                        @foreach((array) $qualityScorecard['blocking_reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('ai.content.generate', $contentItem) }}" class="js-generation-submit" data-generation-kind="full">
                        @csrf
                        <input type="hidden" name="video_provider" value="{{ $videoProviderValue }}" class="js-video-provider-sync">
                        <input type="hidden" name="image_provider" value="{{ $allowsCustomImageProvider ? $imageProviderValue : 'nanobanana' }}" class="js-image-provider-sync">
                        <button type="submit" class="ui-btn-primary">
                            Rigenera AI completo
                        </button>
                    </form>

                    <form method="POST" action="{{ route('ai.content.generateImage', $contentItem) }}" class="js-generation-submit" data-generation-kind="visual">
                        @csrf
                        <input type="hidden" name="video_provider" value="{{ $videoProviderValue }}" class="js-video-provider-sync">
                        <input type="hidden" name="image_provider" value="{{ $allowsCustomImageProvider ? $imageProviderValue : 'nanobanana' }}" class="js-image-provider-sync">
                        <button type="submit" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Rigenera visual
                        </button>
                    </form>
                </div>
            </div>

            <div id="feedback-loop" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Feedback che allena la macchina</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Segna cosa ti convince e cosa no. Ogni feedback resta nella memoria del tenant e viene riusato nelle prossime generazioni.
                        </p>
                    </div>
                    @if($latestFeedback)
                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $latestFeedback->sentiment === 'like' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                            Ultimo feedback: {{ $latestFeedback->sentiment === 'like' ? 'Mi piace' : 'Da correggere' }}
                        </span>
                    @endif
                </div>

                @if(is_array($activeFeedbackRequest) && !empty($activeFeedbackRequest))
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p class="font-semibold">Rigenerazione con correzione attiva</p>
                        <p class="mt-1">{{ $activeFeedbackRequest['instruction'] ?? ($activeFeedbackRequest['reason'] ?? 'Correzione in lavorazione') }}</p>
                    </div>
                @elseif(is_array($lastAppliedFeedback) && !empty($lastAppliedFeedback))
                    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        <p class="font-semibold">Ultima correzione applicata</p>
                        <p class="mt-1">{{ $lastAppliedFeedback['instruction'] ?? ($lastAppliedFeedback['reason'] ?? 'Correzione applicata') }}</p>
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('posts.feedback.store', $contentItem) }}" class="js-feedback-like-submit">
                        @csrf
                        <input type="hidden" name="sentiment" value="like">
                        <button type="submit"
                            class="inline-flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ $activeFeedbackSentiment === 'like' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}"
                            @disabled($activeFeedbackSentiment === 'like')>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M2 21h4V9H2v12Zm19.6-11.2c-.3-.5-.9-.8-1.5-.8h-6.2l.9-4.3.1-.8c0-.4-.2-.8-.4-1.1L13.4 2 7.6 7.8C7.2 8.2 7 8.7 7 9.2V19c0 1.1.9 2 2 2h8.2c.8 0 1.6-.5 1.9-1.3l2.7-6.3c.1-.2.2-.5.2-.8v-1.8c0-.3-.1-.7-.4-1Z"/>
                            </svg>
                            <span>{{ $activeFeedbackSentiment === 'like' ? 'Mi piace salvato' : 'Mi piace' }}</span>
                        </button>
                    </form>

                    <button type="button"
                        class="js-open-feedback-modal inline-flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ $activeFeedbackSentiment === 'dislike' ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M22 3h-4v12h4V3ZM3.9 14.2c.3.5.9.8 1.5.8h6.2l-.9 4.3-.1.8c0 .4.2.8.4 1.1l1.1 1 5.8-5.8c.4-.4.6-.9.6-1.4V5c0-1.1-.9-2-2-2H8.3c-.8 0-1.6.5-1.9 1.3l-2.7 6.3c-.1.2-.2.5-.2.8v1.8c0 .3.1.7.4 1Z"/>
                        </svg>
                        <span>{{ $activeFeedbackSentiment === 'dislike' ? 'Correzione attiva' : 'Non mi piace' }}</span>
                    </button>

                    <p class="text-xs text-gray-500">
                        Pollice su salva subito il contenuto come riferimento valido. Pollice giu apre il funnel di correzione e, se vuoi, rigenera.
                    </p>
                </div>

                <div id="feedback-modal" class="{{ $shouldOpenFeedbackModal ? '' : 'hidden' }} fixed inset-0 z-[70] overflow-y-auto bg-gray-900/60 px-4 py-6">
                    <div class="flex min-h-full items-center justify-center">
                        <div class="w-full max-w-2xl rounded-3xl border border-gray-200 bg-white p-6 shadow-2xl">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">Dimmi cosa non va</h3>
                                    <p class="mt-1 text-sm text-gray-600">
                                        La macchina usera questa nota come obiezione del tenant. Puoi anche far partire subito una rigenerazione corretta.
                                    </p>
                                </div>
                                <button type="button" class="js-close-feedback-modal inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-50">
                                    <span class="sr-only">Chiudi</span>
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M4.3 4.3a1 1 0 0 1 1.4 0L10 8.6l4.3-4.3a1 1 0 1 1 1.4 1.4L11.4 10l4.3 4.3a1 1 0 0 1-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 0 1-1.4-1.4L8.6 10 4.3 5.7a1 1 0 0 1 0-1.4Z"/>
                                    </svg>
                                </button>
                            </div>

                            <form method="POST" action="{{ route('posts.feedback.store', $contentItem) }}" class="js-feedback-submit mt-5 space-y-5">
                                @csrf
                                <input type="hidden" name="sentiment" value="dislike">

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="feedback_category" class="mb-1 block text-sm font-semibold text-gray-700">Motivo principale</label>
                                        <select id="feedback_category" name="category" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                            <option value="">Seleziona il punto da correggere</option>
                                            @foreach($feedbackCategories as $key => $label)
                                                <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="feedback_severity" class="mb-1 block text-sm font-semibold text-gray-700">Severita</label>
                                        <select id="feedback_severity" name="severity" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                            <option value="">Calcolo automatico</option>
                                            @foreach($feedbackSeverityOptions as $key => $label)
                                                <option value="{{ $key }}" @selected(old('severity') === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">Se non la scegli, viene derivata da categoria, motivo e azione.</p>
                                    </div>
                                </div>

                                <div>
                                    <label for="feedback_reason" class="mb-1 block text-sm font-semibold text-gray-700">Cosa non va</label>
                                    <textarea id="feedback_reason" name="reason" rows="4" class="js-autogrow block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Esempio: la sala deve restare com e, il video deve mostrare le aree in sequenza e il testo social deve sembrare piu da ristorante vero.">{{ old('reason') }}</textarea>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Scrivilo come lo diresti a un collaboratore: concreto, breve, utile.
                                    </p>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-gray-700">Azione</label>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="rounded-2xl border {{ $feedbackAction === 'record_only' ? 'border-gray-300 bg-gray-50' : 'border-gray-200 bg-white' }} p-4">
                                            <span class="flex items-start gap-3">
                                                <input type="radio" name="action" value="record_only" class="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-400" @checked($feedbackAction === 'record_only')>
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">Salva solo feedback</span>
                                                    <span class="mt-1 block text-xs text-gray-600">Memorizza l obiezione per i prossimi contenuti.</span>
                                                </span>
                                            </span>
                                        </label>
                                        <label class="rounded-2xl border {{ $feedbackAction === 'regenerate' ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 bg-white' }} p-4">
                                            <span class="flex items-start gap-3">
                                                <input type="radio" name="action" value="regenerate" class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked($feedbackAction === 'regenerate')>
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">Salva e rigenera</span>
                                                    <span class="mt-1 block text-xs text-gray-600">Rifai subito il contenuto con questa correzione come priorita.</span>
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
                                    <p class="text-xs text-gray-500">
                                        Il pollice giu non si perde: dopo il refresh resta attivo sull ultimo feedback negativo registrato.
                                    </p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="js-close-feedback-modal inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                            Annulla
                                        </button>
                                        <button type="submit" class="ui-btn-primary">
                                            Salva feedback
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="mt-6 border-t border-gray-100 pt-5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Storico recente</h3>
                        <span class="text-xs text-gray-500">{{ $feedbackEntries->count() }} feedback mostrati</span>
                    </div>

                    @if($feedbackEntries->isNotEmpty())
                        <div class="mt-3 space-y-3">
                            @foreach($feedbackEntries as $entry)
                                @php
                                    $entryCategory = (string) ($entry->normalized_category ?: $entry->resolvedCategory());
                                    $entryLabel = $entry->resolvedCategoryLabel() ?? 'Feedback';
                                    $entrySeverity = (string) ($entry->severity ?: $entry->resolvedSeverity());
                                    $entrySeverityLabel = $entry->resolvedSeverityLabel();
                                    $entrySeverityBadge = match ($entrySeverity) {
                                        'low' => 'border-gray-200 bg-white text-gray-700',
                                        'medium' => 'border-amber-200 bg-amber-50 text-amber-700',
                                        'high' => 'border-orange-200 bg-orange-50 text-orange-700',
                                        'blocking' => 'border-red-200 bg-red-50 text-red-700',
                                        default => 'border-gray-200 bg-white text-gray-700',
                                    };
                                @endphp
                                <article class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold {{ $entry->sentiment === 'like' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                                {{ $entry->sentiment === 'like' ? 'Mi piace' : 'Non mi piace' }}
                                            </span>
                                            @if($entryCategory !== '')
                                                <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-[11px] font-semibold text-gray-700">
                                                    {{ $entryLabel }}
                                                </span>
                                            @endif
                                            @if($entry->sentiment === 'dislike' && $entrySeverityLabel)
                                                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold {{ $entrySeverityBadge }}">
                                                    Severita {{ strtolower($entrySeverityLabel) }}
                                                </span>
                                            @endif
                                            @if($entry->action === 'regenerate')
                                                <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700">
                                                    Rigenerazione richiesta
                                                </span>
                                            @endif
                                        </div>
                                        <span class="text-[11px] text-gray-500">
                                            {{ optional($entry->created_at)->format('d/m H:i') }}@if($entry->user) · {{ $entry->user->name }}@endif
                                        </span>
                                    </div>

                                    @if(!empty($entry->reason))
                                        <p class="mt-2 text-sm text-gray-700">{{ $entry->reason }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-sm text-gray-600">
                            Nessun feedback ancora registrato su questo contenuto.
                        </div>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('posts.update', $contentItem) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Dati contenuto</h2>
                    <p class="mt-1 text-sm text-gray-600">Metadati di pubblicazione e stato operativo.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="title" class="mb-1 block text-sm font-semibold text-gray-700">Titolo</label>
                            <input id="title" type="text" name="title" value="{{ old('title', $contentItem->title) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="platform" class="mb-1 block text-sm font-semibold text-gray-700">Piattaforma</label>
                                <input id="platform" type="text" name="platform" value="{{ old('platform', $contentItem->platform) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                            </div>
                            <div>
                                <label for="format" class="mb-1 block text-sm font-semibold text-gray-700">Formato</label>
                                <input id="format" type="text" name="format" value="{{ old('format', $contentItem->format) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                            </div>
                        </div>

                        @if($allowsCustomImageProvider)
                            <div>
                                <label for="image_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore immagini</label>
                                <select id="image_provider" name="image_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    @foreach($imageProviderOptions as $key => $label)
                                        <option value="{{ $key }}" @selected($imageProviderValue === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">
                                    Impostazione valida solo per questo contenuto manuale. Il resto dei flussi continua a usare Nano Banana come base.
                                </p>
                            </div>
                        @else
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                                <p class="text-sm font-semibold text-emerald-900">Motore immagini fisso: Nano Banana</p>
                                <p class="mt-1 text-xs text-emerald-800">
                                    Questo contenuto fa parte di un flusso piano/demo, quindi la rigenerazione visual resta allineata al motore base.
                                </p>
                            </div>
                        @endif

                        <div>
                            <label for="video_provider" class="mb-1 block text-sm font-semibold text-gray-700">Motore video (solo reel/story)</label>
                            <select id="video_provider" name="video_provider" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($videoProviderOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($videoProviderValue === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Se il formato e video, la rigenerazione usera questo provider.
                            </p>
                        </div>
                        @if($isVideoFormat)
                        <div>
                            <label for="video_duration_seconds_requested" class="mb-1 block text-sm font-semibold text-gray-700">Durata video (secondi)</label>
                            <input
                                id="video_duration_seconds_requested"
                                type="number"
                                name="video_duration_seconds_requested"
                                min="4"
                                max="45"
                                step="1"
                                value="{{ $videoDurationSeconds > 0 ? $videoDurationSeconds : 20 }}"
                                class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Se supera il limite del provider, il reel viene spezzato in segmenti coerenti e ricucito automaticamente.
                            </p>
                        </div>
                        @endif

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="scheduled_at" class="mb-1 block text-sm font-semibold text-gray-700">Programmazione</label>
                                <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', optional($contentItem->scheduled_at)->format('Y-m-d\TH:i')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            </div>
                            <div>
                                <label for="status" class="mb-1 block text-sm font-semibold text-gray-700">Stato</label>
                                <select id="status" name="status" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    @php $statusValue = old('status', $contentItem->status); @endphp
                                    @foreach($publicationOptions as $statusKey => $statusLabel)
                                        <option value="{{ $statusKey }}" @selected($statusValue === $statusKey)>{{ $statusLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Caption AI</h2>
                    <textarea name="ai_caption" rows="10" class="js-autogrow mt-4 block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('ai_caption', $contentItem->ai_caption) }}</textarea>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Prompt visual AI</h2>
                    <textarea name="ai_image_prompt" rows="8" class="js-autogrow mt-4 block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('ai_image_prompt', $contentItem->ai_image_prompt) }}</textarea>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Overlay typography</h2>
                            <p class="mt-1 text-sm text-gray-600">Override manuale opzionale per hook, intro reel e CTA finale. Dopo il salvataggio va rilanciata la rigenerazione visual per applicarlo all asset.</p>
                        </div>
                        <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ (bool) data_get($overlayMeta, 'rendering.applied', false) ? 'Render applicato' : 'Da renderizzare' }}
                        </span>
                    </div>

                    <div class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Default brand attivi</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-700">
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Font preset: {{ ucfirst($overlayFontPreset) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Trend appetite: {{ ucfirst($brandTrendAppetite) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Hook intensity: {{ ucfirst($brandHookIntensity) }}</span>
                            <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1">Guardrail: {{ ucfirst($brandProfessionalismGuardrail) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label for="overlay_mode" class="mb-1 block text-sm font-semibold text-gray-700">Overlay</label>
                            <select id="overlay_mode" name="overlay_mode" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['modes'] ?? ['auto', 'manual', 'off']) as $mode)
                                    <option value="{{ $mode }}" @selected($overlayMode === $mode)>{{ ucfirst($mode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_preset" class="mb-1 block text-sm font-semibold text-gray-700">Preset</label>
                            <select id="overlay_preset" name="overlay_preset" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayPresets as $key => $row)
                                    <option value="{{ $key }}" @selected($overlayPreset === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_family" class="mb-1 block text-sm font-semibold text-gray-700">Font</label>
                            <select id="overlay_font_family" name="overlay_font_family" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayFonts as $key => $row)
                                    <option value="{{ $key }}" @selected(old('overlay_font_family', (string) ($overlayManual['font_family'] ?? ($overlayBrandPreferences['font_family'] ?? 'arial'))) === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_position" class="mb-1 block text-sm font-semibold text-gray-700">Posizione</label>
                            <select id="overlay_position" name="overlay_position" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['positions'] ?? ['upper_left', 'upper_center', 'center_left', 'center', 'lower_left', 'lower_center']) as $position)
                                    <option value="{{ $position }}" @selected(old('overlay_position', (string) ($overlayManual['position'] ?? 'upper_left')) === $position)>{{ ucfirst(str_replace('_', ' ', $position)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="overlay_text" class="mb-1 block text-sm font-semibold text-gray-700">Testo primario</label>
                            <input id="overlay_text" type="text" name="overlay_text" value="{{ old('overlay_text', (string) ($overlayManual['text'] ?? '')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-3">
                            <label for="overlay_secondary_text" class="mb-1 block text-sm font-semibold text-gray-700">Testo secondario</label>
                            <input id="overlay_secondary_text" type="text" name="overlay_secondary_text" value="{{ old('overlay_secondary_text', (string) ($overlayManual['secondary_text'] ?? '')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-3">
                            <label for="overlay_cta_text" class="mb-1 block text-sm font-semibold text-gray-700">CTA finale overlay</label>
                            <input id="overlay_cta_text" type="text" name="overlay_cta_text" value="{{ $overlayCtaText }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    </div>
                    <details class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white">
                        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900">
                            Opzioni avanzate typography
                        </summary>
                        <div class="border-t border-gray-100 p-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="overlay_font_fallback" class="mb-1 block text-sm font-semibold text-gray-700">Fallback</label>
                            <select id="overlay_font_fallback" name="overlay_font_fallback" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach($overlayFonts as $key => $row)
                                    <option value="{{ $key }}" @selected(old('overlay_font_fallback', (string) ($overlayManual['fallback_font_family'] ?? ($overlayBrandPreferences['fallback_font_family'] ?? 'segoe_ui'))) === $key)>{{ $row['label'] ?? $key }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_weight" class="mb-1 block text-sm font-semibold text-gray-700">Weight</label>
                            <select id="overlay_font_weight" name="overlay_font_weight" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['font_weights'] ?? ['400', '500', '600', '700', '800']) as $weight)
                                    <option value="{{ $weight }}" @selected(old('overlay_font_weight', (string) ($overlayManual['font_weight'] ?? '700')) === $weight)>{{ $weight }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_font_size_mode" class="mb-1 block text-sm font-semibold text-gray-700">Size mode</label>
                            <select id="overlay_font_size_mode" name="overlay_font_size_mode" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['font_size_modes'] ?? ['small', 'medium', 'large', 'xl']) as $sizeMode)
                                    <option value="{{ $sizeMode }}" @selected(old('overlay_font_size_mode', (string) ($overlayManual['font_size_mode'] ?? 'large')) === $sizeMode)>{{ ucfirst($sizeMode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_text_case" class="mb-1 block text-sm font-semibold text-gray-700">Text case</label>
                            <select id="overlay_text_case" name="overlay_text_case" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['text_cases'] ?? ['sentence', 'title', 'uppercase']) as $case)
                                    <option value="{{ $case }}" @selected(old('overlay_text_case', (string) ($overlayManual['text_case'] ?? 'sentence')) === $case)>{{ ucfirst($case) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_alignment" class="mb-1 block text-sm font-semibold text-gray-700">Alignment</label>
                            <select id="overlay_alignment" name="overlay_alignment" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['alignments'] ?? ['left', 'center', 'right']) as $alignment)
                                    <option value="{{ $alignment }}" @selected(old('overlay_alignment', (string) ($overlayManual['alignment'] ?? 'left')) === $alignment)>{{ ucfirst($alignment) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_safe_area" class="mb-1 block text-sm font-semibold text-gray-700">Safe area</label>
                            <select id="overlay_safe_area" name="overlay_safe_area" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['safe_areas'] ?? ['upper_third', 'center_safe', 'lower_third']) as $safeArea)
                                    <option value="{{ $safeArea }}" @selected(old('overlay_safe_area', (string) ($overlayManual['safe_area'] ?? ($overlayBrandPreferences['safe_area'] ?? 'upper_third'))) === $safeArea)>{{ ucfirst(str_replace('_', ' ', $safeArea)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_max_lines" class="mb-1 block text-sm font-semibold text-gray-700">Max lines</label>
                            <input id="overlay_max_lines" type="number" min="1" max="4" name="overlay_max_lines" value="{{ old('overlay_max_lines', (int) ($overlayManual['max_lines'] ?? 2)) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_color" class="mb-1 block text-sm font-semibold text-gray-700">Color</label>
                            <input id="overlay_color" type="text" name="overlay_color" value="{{ old('overlay_color', (string) ($overlayManual['color'] ?? '#FFFFFF')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_stroke_color" class="mb-1 block text-sm font-semibold text-gray-700">Stroke</label>
                            <input id="overlay_stroke_color" type="text" name="overlay_stroke_color" value="{{ old('overlay_stroke_color', (string) ($overlayManual['stroke_color'] ?? '#111827')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_background_style" class="mb-1 block text-sm font-semibold text-gray-700">Background</label>
                            <select id="overlay_background_style" name="overlay_background_style" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['background_styles'] ?? ['none', 'dark_box', 'light_box', 'gradient_strip']) as $background)
                                    <option value="{{ $background }}" @selected(old('overlay_background_style', (string) ($overlayManual['background_style'] ?? 'dark_box')) === $background)>{{ ucfirst(str_replace('_', ' ', $background)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="overlay_animation_style" class="mb-1 block text-sm font-semibold text-gray-700">Animazione</label>
                            <select id="overlay_animation_style" name="overlay_animation_style" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                @foreach(($overlayUi['animation_styles'] ?? ['none', 'fade', 'pop', 'slide_up']) as $animation)
                                    <option value="{{ $animation }}" @selected(old('overlay_animation_style', (string) ($overlayManual['animation_style'] ?? 'fade')) === $animation)>{{ ucfirst(str_replace('_', ' ', $animation)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-700">Shadow</label>
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-700">
                                <input type="hidden" name="overlay_shadow" value="0">
                                <input type="checkbox" name="overlay_shadow" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked((bool) old('overlay_shadow', (bool) ($overlayManual['shadow'] ?? true))) />
                                Attivo
                            </label>
                        </div>
                        <div>
                            <label for="overlay_timing_start_ms" class="mb-1 block text-sm font-semibold text-gray-700">Start ms</label>
                            <input id="overlay_timing_start_ms" type="number" min="0" max="300000" name="overlay_timing_start_ms" value="{{ old('overlay_timing_start_ms', (int) ($overlayManual['timing_start_ms'] ?? 0)) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label for="overlay_timing_end_ms" class="mb-1 block text-sm font-semibold text-gray-700">End ms</label>
                            <input id="overlay_timing_end_ms" type="number" min="0" max="300000" name="overlay_timing_end_ms" value="{{ old('overlay_timing_end_ms', (int) ($overlayManual['timing_end_ms'] ?? 0)) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-2">
                            <label for="overlay_emphasis_words" class="mb-1 block text-sm font-semibold text-gray-700">Emphasis words</label>
                            <input id="overlay_emphasis_words" type="text" name="overlay_emphasis_words" value="{{ old('overlay_emphasis_words', implode(', ', (array) ($overlayManual['emphasis_words'] ?? []))) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    </div>
                        </div>
                    </details>

                    <p class="mt-3 text-xs text-gray-500">
                        In `auto` il sistema ricalcola hook e CTA dal blueprint corrente. In `manual` usa questi override mantenendo preset, font e safe area brand-aware.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="ui-btn-primary">
                        Salva modifiche
                    </button>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Annulla
                    </a>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <aside class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Preview asset</h2>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ ($contentItem->ai_image_path || $videoPath) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-indigo-200 bg-indigo-50 text-indigo-700' }}">
                        {{ ($contentItem->ai_image_path || $videoPath) ? 'Generato' : 'Nessun asset' }}
                    </span>
                </div>

                @if($videoPath)
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white p-1">
                        <video class="post-preview-media rounded-lg bg-black" controls preload="none">
                            <source src="{{ asset('storage/' . ltrim($videoPath, '/')) }}" type="video/mp4">
                        </video>
                    </div>
                    <p class="mt-2 break-words text-xs text-gray-500">Video: <span class="font-mono">{{ $videoPath }}</span></p>
                    @if($contentItem->ai_image_path)
                        <p class="mt-1 text-xs text-gray-500">Anteprima griglia: <span class="font-mono">{{ $contentItem->ai_image_path }}</span></p>
                    @endif
                @elseif($contentItem->ai_image_path)
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white p-1">
                        <img
                            src="{{ asset('storage/' . ltrim($contentItem->ai_image_path, '/')) }}"
                            alt="AI image"
                            class="post-preview-media rounded-lg"
                            loading="lazy"
                        >
                    </div>
                    <p class="mt-2 break-words text-xs text-gray-500">Path: <span class="font-mono">{{ $contentItem->ai_image_path }}</span></p>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600">
                        Nessun asset visual disponibile.
                    </div>
                @endif
            </aside>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Info contenuto</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">ID</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">#{{ $contentItem->id }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Creato il</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ optional($contentItem->created_at)->format('d/m/Y H:i') ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">AI generato il</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ optional($contentItem->ai_generated_at)->format('d/m/Y H:i') ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Overlay</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($overlayMeta, 'preset.label', data_get($overlayMeta, 'preset.key', 'n/d')) }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            contrast {{ is_numeric(data_get($overlayMeta, 'readability.contrast_score')) ? number_format(((float) data_get($overlayMeta, 'readability.contrast_score')) * 100, 0) . '%' : '-' }}
                            • safe area {{ is_numeric(data_get($overlayMeta, 'readability.safe_area_score')) ? number_format(((float) data_get($overlayMeta, 'readability.safe_area_score')) * 100, 0) . '%' : '-' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@include('partials.generation-loader')
<script>
    (function () {
        const autosize = (el) => {
            el.style.height = 'auto';
            el.style.overflowY = 'hidden';
            el.style.height = (el.scrollHeight + 2) + 'px';
        };

        document.querySelectorAll('textarea.js-autogrow').forEach((el) => {
            autosize(el);
            el.addEventListener('input', () => autosize(el));
        });

        const providerSelect = document.getElementById('video_provider');
        const providerHiddenInputs = document.querySelectorAll('input.js-video-provider-sync');
        const imageProviderSelect = document.getElementById('image_provider');
        const imageProviderHiddenInputs = document.querySelectorAll('input.js-image-provider-sync');
        const formatInput = document.getElementById('format');
        const generationForms = document.querySelectorAll('form.js-generation-submit');
        const likeFeedbackForm = document.querySelector('form.js-feedback-like-submit');
        const feedbackForm = document.querySelector('form.js-feedback-submit');
        const feedbackModal = document.getElementById('feedback-modal');
        const feedbackModalOpeners = document.querySelectorAll('.js-open-feedback-modal');
        const feedbackModalClosers = document.querySelectorAll('.js-close-feedback-modal');
        const feedbackCategorySelect = document.getElementById('feedback_category');
        const feedbackActionInputs = document.querySelectorAll('input[name=\"action\"]');
        const shouldOpenFeedbackModal = @json($shouldOpenFeedbackModal);
        const estimateSeconds = (kind = 'full') => {
            const format = (formatInput?.value || 'post').toLowerCase();
            const videoProvider = (providerSelect?.value || 'openai').toLowerCase();
            const imageProvider = (imageProviderSelect?.value || 'nanobanana').toLowerCase();

            if (kind === 'visual') {
                return imageProvider === 'openai' ? 30 : 50;
            }

            if (format === 'reel') {
                if (videoProvider === 'runway') {
                    return 150;
                }
                if (videoProvider === 'google_veo') {
                    return 185;
                }
                if (videoProvider === 'kling') {
                    return 175;
                }
                return 190;
            }
            if (format === 'story') {
                if (videoProvider === 'runway') {
                    return 120;
                }
                if (videoProvider === 'google_veo') {
                    return 140;
                }
                if (videoProvider === 'kling') {
                    return 145;
                }
                return 150;
            }

            return imageProvider === 'openai' ? 40 : 60;
        };
        if (providerSelect && providerHiddenInputs.length > 0) {
            const syncProvider = () => {
                providerHiddenInputs.forEach((input) => {
                    input.value = providerSelect.value || 'openai';
                });
            };
            syncProvider();
            providerSelect.addEventListener('change', syncProvider);
        }

        if (imageProviderSelect && imageProviderHiddenInputs.length > 0) {
            const syncImageProvider = () => {
                imageProviderHiddenInputs.forEach((input) => {
                    input.value = imageProviderSelect.value || 'nanobanana';
                });
            };
            syncImageProvider();
            imageProviderSelect.addEventListener('change', syncImageProvider);
        }

        if (generationForms.length > 0 && window.HostupGenerationLoader) {
            generationForms.forEach((form) => {
                form.addEventListener('submit', () => {
                    const kind = String(form.getAttribute('data-generation-kind') || 'full');
                    const format = (formatInput?.value || 'post').toLowerCase();
                    const isVisualOnly = kind === 'visual';

                    window.HostupGenerationLoader.show({
                        title: isVisualOnly ? 'Sto rigenerando il visual' : 'Sto rigenerando il contenuto',
                        subtitle: isVisualOnly
                            ? 'Sto ricostruendo il visual con prompt, brand e riferimenti aggiornati.'
                            : (format === 'reel' || format === 'story'
                                ? 'Sto aggiornando testo, visual e parte video. Questo richiede qualche passaggio in piu.'
                                : 'Sto aggiornando caption, prompt e output visuale finale.'),
                        estimateSeconds: estimateSeconds(kind),
                        stages: isVisualOnly
                            ? ['Analisi prompt e riferimenti', 'Generazione visuale', 'Controllo resa finale']
                            : ['Analisi contenuto e strategia', 'Scrittura e rigenerazione asset', 'Rifinitura output finale'],
                    });
                });
            });
        }

        if (feedbackForm && window.HostupGenerationLoader) {
            feedbackForm.addEventListener('submit', () => {
                const selectedAction = Array.from(feedbackActionInputs).find((input) => input.checked)?.value || 'record_only';
                if (selectedAction !== 'regenerate') {
                    return;
                }

                const category = (feedbackCategorySelect?.value || '').toLowerCase();
                const subtitle = category === 'location_not_consistent' || category === 'location_integrity'
                    ? 'Sto rigenerando il visual mantenendo il luogo reale come ancora principale, senza inventare nuove sale o ambienti.'
                    : category === 'person_not_consistent' || category === 'low_quality_visual' || category === 'realism'
                        ? 'Sto rigenerando il contenuto con priorita sulla resa realistica e naturale del visual.'
                        : 'Sto rigenerando il contenuto usando la tua obiezione come correzione prioritaria.';

                window.HostupGenerationLoader.show({
                    title: 'Sto applicando le tue correzioni',
                    subtitle,
                    estimateSeconds: estimateSeconds('full'),
                    stages: [
                        'Lettura feedback e memoria del tenant',
                        'Rigenerazione con correzioni prioritarie',
                        'Salvataggio del nuovo output finale',
                    ],
                });
            });
        }

        if (likeFeedbackForm) {
            likeFeedbackForm.addEventListener('submit', (event) => {
                const button = likeFeedbackForm.querySelector('button[type=\"submit\"]');
                if (button && button.disabled) {
                    event.preventDefault();
                }
            });
        }

        if (feedbackModal) {
            const openModal = () => {
                feedbackModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                feedbackCategorySelect?.focus();
            };
            const closeModal = () => {
                feedbackModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            };

            feedbackModalOpeners.forEach((button) => {
                button.addEventListener('click', openModal);
            });
            feedbackModalClosers.forEach((button) => {
                button.addEventListener('click', closeModal);
            });
            feedbackModal.addEventListener('click', (event) => {
                if (event.target === feedbackModal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !feedbackModal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            if (shouldOpenFeedbackModal) {
                openModal();
            }
        }
    })();
</script>
@endsection

