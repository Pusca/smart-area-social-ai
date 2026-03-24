<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                @php
                    $headerAiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                    $headerPublicationInfo = \App\Support\UiStatus::publication((string) $item->status);
                @endphp
                <h2 class="ui-title-lg">{{ $item->title }}</h2>
                <div class="mt-1 text-sm text-muted">
                    {{ strtoupper($item->platform) }} - {{ $item->format }} -
                    {{ optional($item->scheduled_at)->format('d/m/Y H:i') }}
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $headerAiInfo['badge'] }}">
                        AI {{ $headerAiInfo['label'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $headerPublicationInfo['badge'] }}">
                        {{ $headerPublicationInfo['label'] }}
                    </span>
                </div>
            </div>

            <a href="{{ route('content-items.index') }}" class="ui-btn-secondary">Torna alla lista</a>
        </div>
    </x-slot>

    @php
        $img = $item->ai_image_path ? asset('storage/' . $item->ai_image_path) : null;
        $hashtags = $item->ai_hashtags;
        $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
        $video = null;
        $metaVideoPath = trim((string) data_get($item->ai_meta, 'video_generation.video_path', ''));
        if ($metaVideoPath !== '') {
            $video = asset('storage/' . ltrim($metaVideoPath, '/'));
        }
        $assetsRaw = $item->assets ?? [];
        $assetsList = is_string($assetsRaw) ? (json_decode($assetsRaw, true) ?: []) : (is_array($assetsRaw) ? $assetsRaw : []);
        if ($video === null) {
            foreach ($assetsList as $asset) {
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
                    $video = asset('storage/' . ltrim($assetPath, '/'));
                    break;
                }
            }
        }

        if (is_string($hashtags)) {
            $decoded = json_decode($hashtags, true);
            $hashtags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($hashtags)) $hashtags = [];
        $qualityScorecard = is_array(data_get($item->ai_meta, 'quality_scorecard')) ? (array) data_get($item->ai_meta, 'quality_scorecard') : [];
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
        $overlayMeta = is_array(data_get($item->ai_meta, 'overlay_meta')) ? (array) data_get($item->ai_meta, 'overlay_meta') : [];
        $hookMeta = is_array(data_get($item->ai_meta, 'hook_meta')) ? (array) data_get($item->ai_meta, 'hook_meta') : [];
        $trendBasis = is_array(data_get($item->ai_meta, 'item_brain.trend_basis')) ? (array) data_get($item->ai_meta, 'item_brain.trend_basis') : [];
        $trendOpportunity = is_array(data_get($item->ai_meta, 'item_brain.trend_opportunity')) ? (array) data_get($item->ai_meta, 'item_brain.trend_opportunity') : [];
        $selectedImageRefs = array_values(array_filter((array) data_get($item->ai_meta, 'image_references.selected', []), fn ($row) => is_array($row)));
        $selectedImagePaths = array_values(array_filter(array_map('strval', (array) data_get($item->ai_meta, 'image_references.selected_paths', []))));
        $resolvedVariables = array_values(array_filter((array) data_get($item->ai_meta, 'asset_variables.resolved', []), fn ($row) => is_array($row)));
        $assetRanking = array_values(array_filter((array) data_get($item->ai_meta, 'asset_scoring.asset_ranking', []), fn ($row) => is_array($row)));
        $assetIdentityConfidence = data_get($item->ai_meta, 'asset_scoring.identity_confidence');
        $scoreHighlights = [
            'Professionalism' => data_get($qualityScorecard, 'professionalism_score'),
            'Trend' => data_get($qualityScorecard, 'trend_relevance_score'),
            'Viral' => data_get($qualityScorecard, 'viral_readiness_score'),
        ];
        $trendSummary = array_filter([
            'Trend topic' => (string) data_get($trendBasis, 'topic', data_get($trendOpportunity, 'topic', '')),
            'Source' => (string) data_get($trendBasis, 'source', ''),
            'Why now' => (string) data_get($item->ai_meta, 'item_brain.reason_why_now', data_get($trendBasis, 'reason_why_now', '')),
            'Brand fit' => (string) data_get($item->ai_meta, 'item_brain.reason_why_brand_fit', data_get($trendBasis, 'reason_why_brand_fit', '')),
            'Engagement goal' => (string) data_get($item->ai_meta, 'item_brain.expected_engagement_goal', data_get($trendBasis, 'expected_engagement_goal', '')),
        ], fn ($value) => trim((string) $value) !== '');
    @endphp

    <section class="ui-shell ui-page">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="ui-card overflow-hidden">
                <div class="bg-surface-2">
                    @if($video)
                        <video class="h-auto w-full object-cover" controls preload="none">
                            <source src="{{ $video }}" type="video/mp4" />
                        </video>
                    @elseif($img)
                        <img src="{{ $img }}" alt="AI image" class="h-auto w-full object-cover" />
                    @else
                        <div class="flex aspect-square w-full items-center justify-center text-muted">Nessun visual generato</div>
                    @endif
                </div>

                <div class="border-t border-app p-4">
                    <div class="text-sm text-muted">
                        File visual: <span class="font-mono">{{ $item->ai_image_path ?? '-' }}</span>
                    </div>

                    <div class="mt-2 rounded-lg border p-3 text-sm {{ $aiInfo['badge'] }}">
                        <p class="font-semibold">Stato AI: {{ $aiInfo['label'] }}</p>
                        <p class="mt-1 text-xs opacity-90">{{ $aiInfo['description'] }}</p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Caption</div>
                    <div class="whitespace-pre-line text-text">{{ $item->ai_caption ?? '-' }}</div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Hashtags</div>
                    @if(count($hashtags))
                        <div class="flex flex-wrap gap-2">
                            @foreach($hashtags as $h)
                                <span class="rounded-full bg-surface-2 px-2 py-1 text-sm text-text">{{ $h }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="text-muted">-</div>
                    @endif
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">CTA</div>
                    <div class="text-text">{{ $item->ai_cta ?? '-' }}</div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Image prompt</div>
                    <div class="text-text">{{ $item->ai_image_prompt ?? '-' }}</div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Quality highlights</div>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach($scoreHighlights as $label => $score)
                            <div class="rounded-lg bg-surface-2 px-3 py-2">
                                <div class="text-xs text-muted">{{ $label }}</div>
                                <div class="mt-1 text-sm font-semibold text-text">
                                    {{ is_numeric($score) ? number_format(((float) $score) * 100, 0) . '%' : '-' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Overlay system</div>
                    <div class="text-text">
                        {{ data_get($overlayMeta, 'preset.label', data_get($overlayMeta, 'preset.key', 'Nessun preset')) }}
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Mode</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($overlayMeta, 'mode', '-') }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Render</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ (bool) data_get($overlayMeta, 'rendering.applied', false) ? 'applicato' : 'non applicato' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Contrast</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ is_numeric(data_get($overlayMeta, 'readability.contrast_score')) ? number_format(((float) data_get($overlayMeta, 'readability.contrast_score')) * 100, 0) . '%' : '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Mobile readability</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ is_numeric(data_get($overlayMeta, 'readability.mobile_readability')) ? number_format(((float) data_get($overlayMeta, 'readability.mobile_readability')) * 100, 0) . '%' : '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Font</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($overlayMeta, 'brand_preferences.font_family', data_get($overlayMeta, 'templates.0.font_family', '-')) }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Position</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($overlayMeta, 'templates.0.position', '-') }}</div>
                        </div>
                    </div>

                    @if(!empty($overlayMeta['templates']))
                        <div class="mt-3 space-y-2">
                            @foreach((array) $overlayMeta['templates'] as $template)
                                <div class="rounded-lg border border-gray-200 px-3 py-2">
                                    <div class="text-xs text-muted">{{ ucfirst((string) ($template['role'] ?? 'template')) }}</div>
                                    <div class="mt-1 text-sm font-semibold text-text">{{ $template['text'] ?? '-' }}</div>
                                    @if(!empty($template['secondary_text']))
                                        <div class="mt-1 text-xs text-muted">{{ $template['secondary_text'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if(trim((string) data_get($overlayMeta, 'caption_card_final', '')) !== '')
                        <div class="mt-3 rounded-lg border border-gray-200 px-3 py-2">
                            <div class="text-xs text-muted">CTA finale overlay</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($overlayMeta, 'caption_card_final') }}</div>
                        </div>
                    @endif
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Trend + hook strategy</div>

                    @if(!empty($trendSummary))
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach($trendSummary as $label => $value)
                                <div class="rounded-lg bg-surface-2 px-3 py-2">
                                    <div class="text-xs text-muted">{{ $label }}</div>
                                    <div class="mt-1 text-sm font-semibold text-text">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-sm text-muted">Nessun trend basis strutturato su questo contenuto.</div>
                    @endif

                    <div class="mt-3 space-y-2">
                        <div class="rounded-lg border border-gray-200 px-3 py-2">
                            <div class="text-xs text-muted">Main hook</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($hookMeta, 'main_hook', '-') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2">
                            <div class="text-xs text-muted">Alternative hook</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($hookMeta, 'alternative_hook', '-') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2">
                            <div class="text-xs text-muted">Narrative angle</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($hookMeta, 'narrative_angle', data_get($item->ai_meta, 'item_brain.narrative_angle', '-')) }}</div>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="rounded-lg bg-surface-2 px-3 py-2">
                                <div class="text-xs text-muted">CTA mode</div>
                                <div class="mt-1 text-sm font-semibold text-text">{{ data_get($hookMeta, 'cta_mode', '-') }}</div>
                            </div>
                            <div class="rounded-lg bg-surface-2 px-3 py-2">
                                <div class="text-xs text-muted">Opening structure</div>
                                <div class="mt-1 text-sm font-semibold text-text">{{ data_get($hookMeta, 'platform_specific_opening_structure', '-') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Asset selection summary</div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Selected image refs</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ count($selectedImageRefs) > 0 ? count($selectedImageRefs) : count($selectedImagePaths) }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Resolved variables</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ count($resolvedVariables) }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Identity confidence</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ is_numeric($assetIdentityConfidence) ? number_format(((float) $assetIdentityConfidence) * 100, 0) . '%' : '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Ranked candidates</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ count($assetRanking) }}</div>
                        </div>
                    </div>

                    @if(!empty($selectedImagePaths))
                        <div class="mt-3 space-y-2">
                            @foreach(array_slice($selectedImagePaths, 0, 3) as $path)
                                <div class="rounded-lg border border-gray-200 px-3 py-2">
                                    <div class="text-xs text-muted">Selected path</div>
                                    <div class="mt-1 text-sm font-semibold text-text break-all">{{ $path }}</div>
                                </div>
                            @endforeach
                        </div>
                    @elseif(!empty($assetRanking))
                        <div class="mt-3 space-y-2">
                            @foreach(array_slice($assetRanking, 0, 3) as $rankedAsset)
                                <div class="rounded-lg border border-gray-200 px-3 py-2">
                                    <div class="text-xs text-muted">Ranked asset</div>
                                    <div class="mt-1 text-sm font-semibold text-text break-all">{{ $rankedAsset['path'] ?? '-' }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if(!empty($qualityScorecard))
                    <div class="ui-card p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm text-muted">Quality scorecard</div>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $qualityBadge }}">
                                {{ $qualityStatus !== '' ? $qualityStatus : 'n/a' }}
                            </span>
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach($qualityScores as $scoreKey => $label)
                                @php
                                    $score = data_get($qualityScorecard, $scoreKey);
                                    $scoreSource = is_array(data_get($qualityScoreSources, $scoreKey)) ? (array) data_get($qualityScoreSources, $scoreKey) : [];
                                @endphp
                                <div class="rounded-lg bg-surface-2 px-3 py-2">
                                    <div class="text-xs text-muted">{{ $label }}</div>
                                    <div class="mt-1 text-sm font-semibold text-text">
                                        {{ is_numeric($score) ? number_format(((float) $score) * 100, 0) . '%' : '-' }}
                                    </div>
                                    <div class="mt-1 text-[11px] text-muted">
                                        {{ (string) ($scoreSource['mode'] ?? 'missing') }} | {{ (string) ($scoreSource['source'] ?? 'n/a') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if(!empty($qualityScorecard['warnings']))
                            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                                <div class="font-semibold">Warnings</div>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach((array) $qualityScorecard['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(!empty($qualityScorecard['blocking_reasons']))
                            <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-900">
                                <div class="font-semibold">Blocking reasons</div>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach((array) $qualityScorecard['blocking_reasons'] as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-app-layout>
