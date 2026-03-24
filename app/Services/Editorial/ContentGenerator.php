<?php

namespace App\Services\Editorial;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Services\AI\ContentAngleEngine;
use App\Services\Overlays\ContentOverlayEngine;
use App\Support\ImageProviderResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentGenerator
{
    public function __construct(
        private readonly DuplicateContentGuard $duplicateGuard,
        private readonly ContentAngleEngine $contentAngleEngine,
        private readonly ContentOverlayEngine $contentOverlayEngine
    ) {
    }

    public function generateForPlan(
        ContentPlan $plan,
        array $planItems,
        array $context = []
    ): array {
        $tenantId = (int) $plan->tenant_id;
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : null;
        $profileData = (array) ($context['profile_data'] ?? []);
        $strategy = (array) ($context['strategy'] ?? []);
        $memory = (array) ($context['memory'] ?? []);
        $assets = (array) ($context['assets'] ?? []);
        $assetVariables = (array) ($context['asset_variables'] ?? data_get($strategy, 'brand_references.asset_variables', []));
        $constraints = (array) data_get($strategy, 'constraints', []);

        $hardWindow = (int) ($constraints['no_repeat_days'] ?? config('editorial.duplicate_window_days', 180));
        $softThreshold = (float) ($constraints['soft_similarity_threshold'] ?? config('editorial.soft_similarity_threshold', 0.78));
        $maxAttempts = (int) ($constraints['max_regeneration_attempts'] ?? config('editorial.max_regeneration_attempts', 2));

        $created = [];
        $inMemoryFingerprints = [];

        DB::transaction(function () use (
            $planItems,
            $tenantId,
            $plan,
            $userId,
            $profileData,
            $strategy,
            $memory,
            $assets,
            $assetVariables,
            $hardWindow,
            $softThreshold,
            $maxAttempts,
            &$created,
            &$inMemoryFingerprints
        ) {
            foreach ($planItems as $index => $item) {
                $resolved = $this->resolveUniqueness(
                    tenantId: $tenantId,
                    payload: $item,
                    localFingerprints: $inMemoryFingerprints,
                    hardWindow: $hardWindow,
                    softThreshold: $softThreshold,
                    maxAttempts: $maxAttempts
                );

                $fingerprint = $resolved['fingerprint'];
                $similarityGroup = $resolved['similarity_group'];
                $inMemoryFingerprints[$fingerprint] = true;
                $strategicPayload = $this->enrichPayloadWithContentStrategy(
                    plan: $plan,
                    payload: (array) $resolved['payload'],
                    strategy: $strategy,
                    profileData: $profileData
                );
                $contentStrategyMeta = $this->buildContentStrategyMeta(
                    plan: $plan,
                    payload: $strategicPayload,
                    strategy: $strategy,
                    profileData: $profileData
                );
                $overlayPack = $this->contentOverlayEngine->build([
                    'platform' => (string) ($item['platform'] ?? 'instagram'),
                    'format' => (string) ($item['format'] ?? 'post'),
                    'tenant_profile' => $profileData,
                    'strategy' => $strategy,
                    'item_brain' => $strategicPayload,
                    'hook_meta' => (array) data_get($contentStrategyMeta, 'hook_meta', data_get($contentStrategyMeta, 'item_brain.hook_meta', [])),
                    'overlay_settings' => [
                        'mode' => 'auto',
                        'preset' => (string) data_get($profileData, 'overlay_preferences.preset', 'modern_split_caption'),
                    ],
                    'caption' => (string) ($strategicPayload['content_angle'] ?? ''),
                    'ai_cta' => (string) ($strategicPayload['primary_cta'] ?? ''),
                ]);
                $overlayMeta = $this->contentOverlayEngine->toMetaFragment($overlayPack);

                $scheduledAt = !empty($item['scheduled_at'])
                    ? Carbon::parse((string) $item['scheduled_at'])
                    : null;

                $record = ContentItem::create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => $plan->id,
                    'created_by' => $userId,
                    'platform' => (string) ($item['platform'] ?? 'instagram'),
                    'format' => (string) ($item['format'] ?? 'post'),
                    'scheduled_at' => $scheduledAt,
                    'status' => 'draft',
                    'title' => (string) ($item['title_hint'] ?? ('Post ' . ($index + 1))),
                    'caption' => null,
                    'hashtags' => [],
                    'assets' => [],
                    'rubric' => (string) ($strategicPayload['rubric'] ?? ''),
                    'series_key' => data_get($strategicPayload, 'series_key'),
                    'episode_number' => data_get($strategicPayload, 'episode_number'),
                    'pillar' => (string) ($strategicPayload['pillar'] ?? ''),
                    'content_angle' => (string) ($strategicPayload['content_angle'] ?? ''),
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'source_refs' => array_merge(
                        (array) ($strategicPayload['source_refs'] ?? []),
                        $this->buildSourceRefsFromAssetVariables((array) ($strategicPayload['asset_variable_refs'] ?? []))
                    ),
                    'ai_status' => 'queued',
                    'ai_meta' => array_replace_recursive($contentStrategyMeta, $overlayMeta, [
                        'image_provider' => ImageProviderResolver::default(),
                        'tenant_profile' => $profileData,
                        'brand_assets' => $assets,
                        'asset_variables_catalog' => $assetVariables,
                        'asset_variables' => $this->buildAssetVariableMeta((array) ($strategicPayload['asset_variable_refs'] ?? [])),
                        'plan' => [
                            'goal' => data_get($plan->settings, 'goal'),
                            'tone' => data_get($plan->settings, 'tone'),
                            'posts_total' => data_get($plan->settings, 'posts_total'),
                            'platforms' => data_get($plan->settings, 'platforms'),
                            'formats' => data_get($plan->settings, 'formats'),
                            'date_range' => [$plan->start_date?->toDateString(), $plan->end_date?->toDateString()],
                        ],
                        'memory_summary' => $memory,
                        'strategy' => [
                            'brand_voice' => data_get($strategy, 'brand_voice', []),
                            'pillars' => data_get($strategy, 'pillars', []),
                            'rubrics' => data_get($strategy, 'rubrics', []),
                            'cta_rules' => data_get($strategy, 'cta_rules', []),
                            'constraints' => data_get($strategy, 'constraints', []),
                            'analysis_framework' => data_get($strategy, 'analysis_framework', []),
                            'visual_system' => data_get($strategy, 'visual_system', []),
                            'publishing_system' => data_get($strategy, 'publishing_system', []),
                            'creative_direction' => data_get($strategy, 'creative_direction', []),
                            'trend_intelligence' => data_get($strategy, 'trend_intelligence', []),
                            'strategy_notes' => data_get($strategy, 'strategy_notes', ''),
                            'strategy_locked' => (bool) data_get($strategy, 'strategy_locked', false),
                            'strategy_id' => data_get($strategy, 'strategy_id'),
                            'strategy_updated_at' => data_get($strategy, 'strategy_updated_at'),
                            'brand_references' => data_get($strategy, 'brand_references', []),
                        ],
                        'overlay_settings' => [
                            'mode' => 'auto',
                            'preset' => (string) data_get($profileData, 'overlay_preferences.preset', 'modern_split_caption'),
                        ],
                        'item_brain' => [
                            'rubric' => (string) ($strategicPayload['rubric'] ?? ''),
                            'pillar' => (string) ($strategicPayload['pillar'] ?? ''),
                            'angle' => (string) ($strategicPayload['content_angle'] ?? ''),
                            'objective' => (string) ($strategicPayload['objective'] ?? 'Awareness'),
                            'key_points' => (array) ($strategicPayload['key_points'] ?? []),
                            'cta' => (string) ($strategicPayload['primary_cta'] ?? ''),
                            'image_direction' => (string) ($strategicPayload['image_direction'] ?? ''),
                            'feedback_guidance' => (array) ($strategicPayload['feedback_guidance'] ?? []),
                            'professional_brief' => (string) ($strategicPayload['professional_brief'] ?? ''),
                            'editorial_mode' => (string) ($strategicPayload['editorial_mode'] ?? 'evergreen'),
                            'viral_hook_style' => (string) ($strategicPayload['viral_hook_style'] ?? ''),
                            'hook_style' => (string) ($strategicPayload['hook_style'] ?? ''),
                            'shareability_driver' => (string) ($strategicPayload['shareability_driver'] ?? ''),
                            'viral_angle' => (string) ($strategicPayload['viral_angle'] ?? ''),
                            'viral_angle_meta' => (array) ($strategicPayload['viral_angle_meta'] ?? []),
                            'trend_bridge' => (string) ($strategicPayload['trend_bridge'] ?? ''),
                            'trend_usage_mode' => (string) ($strategicPayload['trend_usage_mode'] ?? 'none'),
                            'trend_confidence' => $strategicPayload['trend_confidence'] ?? null,
                            'trend_opportunity_id' => $strategicPayload['trend_opportunity_id'] ?? null,
                            'platform_native_format_suggestion' => (string) ($strategicPayload['platform_native_format_suggestion'] ?? ''),
                            'professionality_guardrails' => (array) ($strategicPayload['professionality_guardrails'] ?? []),
                            'trend_basis' => (array) ($strategicPayload['trend_basis'] ?? []),
                            'reason_why_now' => (string) ($strategicPayload['reason_why_now'] ?? ''),
                            'reason_why_brand_fit' => (string) ($strategicPayload['reason_why_brand_fit'] ?? ''),
                            'expected_engagement_goal' => (string) ($strategicPayload['expected_engagement_goal'] ?? ''),
                            'trend_guardrails' => (array) ($strategicPayload['trend_guardrails'] ?? []),
                            'trend_hook_patterns' => (array) ($strategicPayload['trend_hook_patterns'] ?? []),
                            'trend_platform_hint' => (string) ($strategicPayload['trend_platform_hint'] ?? ''),
                            'trend_scores' => (array) ($strategicPayload['trend_scores'] ?? []),
                            'trend_opportunity' => (array) ($strategicPayload['trend_opportunity'] ?? []),
                            'overlay_brief' => (string) ($overlayPack['overlay_brief'] ?? ($strategicPayload['overlay_brief'] ?? '')),
                            'overlay_meta' => $overlayPack,
                            'continuity_brief' => (string) ($strategicPayload['continuity_brief'] ?? ''),
                            'content_strategy_type' => (string) ($strategicPayload['content_strategy_type'] ?? ''),
                            'narrative_angle' => (string) ($strategicPayload['narrative_angle'] ?? ''),
                            'hook_meta' => (array) ($strategicPayload['hook_meta'] ?? []),
                            'authority_signals' => (array) ($strategicPayload['authority_signals'] ?? []),
                            'trust_signals' => (array) ($strategicPayload['trust_signals'] ?? []),
                            'content_structure_meta' => (array) ($strategicPayload['content_structure_meta'] ?? []),
                            'series_name' => (string) ($strategicPayload['series_key'] ?? ''),
                            'series_step' => $strategicPayload['episode_number'] ?? null,
                            'standalone_rule' => 'Il contenuto deve essere completo anche se letto singolarmente.',
                            'connection_hint' => $resolved['connection_hint'],
                            'uniqueness_key' => $fingerprint,
                        ],
                    ]),
                ]);

                $created[] = $record;
            }
        });

        return $created;
    }

    private function resolveUniqueness(
        int $tenantId,
        array $payload,
        array $localFingerprints,
        int $hardWindow,
        float $softThreshold,
        int $maxAttempts
    ): array {
        $working = $payload;
        $attempts = max(1, $maxAttempts + 1);
        $similarityGroup = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $working['content_angle'] = $this->mutateAngle((string) ($working['content_angle'] ?? ''), $attempt);
            }

            $fingerprint = $this->duplicateGuard->fingerprint($tenantId, [
                'platform' => $working['platform'] ?? '',
                'format' => $working['format'] ?? '',
                'rubric' => $working['rubric'] ?? '',
                'pillar' => $working['pillar'] ?? '',
                'content_angle' => $working['content_angle'] ?? '',
                'keywords' => $working['keywords'] ?? $working['content_angle'] ?? '',
            ]);

            $isHardDuplicate = isset($localFingerprints[$fingerprint])
                || $this->duplicateGuard->hasHardDuplicate($tenantId, $fingerprint, $hardWindow);

            $candidateText = trim(implode(' ', [
                (string) ($working['title_hint'] ?? ''),
                (string) ($working['content_angle'] ?? ''),
            ]));

            $soft = $this->duplicateGuard->findSoftDuplicate($tenantId, $candidateText, $hardWindow, $softThreshold);

            if (!$isHardDuplicate && $soft === null) {
                return [
                    'payload' => $working,
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'connection_hint' => $attempt === 0
                        ? 'Nuovo contenuto coerente con il piano.'
                        : 'Contenuto rigenerato per evitare sovrapposizioni.',
                ];
            }

            if ($soft !== null) {
                $similarityGroup = 'sim-' . (int) $soft['id'];
            }

            if ($attempt >= ($attempts - 1)) {
                $working = $this->fallbackRubric($working);
                $fingerprint = $this->duplicateGuard->fingerprint($tenantId, [
                    'platform' => $working['platform'] ?? '',
                    'format' => $working['format'] ?? '',
                    'rubric' => $working['rubric'] ?? '',
                    'pillar' => $working['pillar'] ?? '',
                    'content_angle' => $working['content_angle'] ?? '',
                    'keywords' => $working['keywords'] ?? $working['content_angle'] ?? '',
                ]);

                return [
                    'payload' => $working,
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'connection_hint' => 'Fallback rubric applicato per evitare duplicati.',
                ];
            }
        }

        $fingerprint = $this->duplicateGuard->fingerprint($tenantId, $working);

        return [
            'payload' => $working,
            'fingerprint' => $fingerprint,
            'similarity_group' => $similarityGroup,
            'connection_hint' => 'Contenuto generato.',
        ];
    }

    private function mutateAngle(string $angle, int $attempt): string
    {
        $angle = trim($angle);
        if ($angle === '') {
            $angle = 'Approccio pratico orientato al risultato';
        }

        $suffixes = [
            'con focus su errore frequente',
            'in formato checklist operativa',
            'con mini-case concreto e takeaway',
        ];

        $suffix = $suffixes[($attempt - 1) % count($suffixes)];
        return Str::limit($angle . ' - ' . $suffix, 170, '');
    }

    private function fallbackRubric(array $payload): array
    {
        $current = Str::lower((string) ($payload['rubric'] ?? ''));
        $fallbacks = ['Educativo', 'Community', 'Storia Brand', 'Prova Sociale'];
        foreach ($fallbacks as $candidate) {
            if (Str::lower($candidate) !== $current) {
                $payload['rubric'] = $candidate;
                break;
            }
        }

        $payload['content_angle'] = $this->mutateAngle((string) ($payload['content_angle'] ?? ''), 2);
        $payload['title_hint'] = Str::limit(((string) $payload['rubric']) . ': ' . ((string) ($payload['pillar'] ?? '')), 110, '');
        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function buildSourceRefsFromAssetVariables(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $name = trim((string) ($ref['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $out[] = [
                'type' => 'asset_variable',
                'variable_id' => isset($ref['id']) ? (int) $ref['id'] : null,
                'name' => $name,
                'slug' => (string) ($ref['slug'] ?? ''),
                'kind' => (string) ($ref['kind'] ?? 'custom'),
                'asset_paths' => array_values(array_filter(array_map(
                    'strval',
                    (array) ($ref['asset_paths'] ?? [])
                ))),
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    private function buildAssetVariableMeta(array $refs): array
    {
        if (empty($refs)) {
            return [
                'selection_mode' => 'none',
                'requested_ids' => [],
                'detected_ids' => [],
                'resolved_ids' => [],
                'resolved_asset_ids' => [],
                'resolved_asset_paths' => [],
                'resolved' => [],
                'recognized_terms' => [],
            ];
        }

        $resolved = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $name = trim((string) ($ref['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $resolved[] = [
                'id' => isset($ref['id']) ? (int) $ref['id'] : null,
                'name' => $name,
                'slug' => (string) ($ref['slug'] ?? ''),
                'kind' => (string) ($ref['kind'] ?? 'custom'),
                'description' => (string) ($ref['description'] ?? ''),
                'profile' => is_array($ref['profile'] ?? null) ? $ref['profile'] : [],
                'identity_pack' => is_array($ref['identity_pack'] ?? null) ? $ref['identity_pack'] : [],
                'asset_ids' => array_values(array_filter(array_map(
                    fn ($id) => (int) $id,
                    (array) ($ref['asset_ids'] ?? [])
                ), fn ($id) => $id > 0)),
                'asset_paths' => array_values(array_filter(array_map(
                    'strval',
                    (array) ($ref['asset_paths'] ?? [])
                ))),
                'assets' => is_array($ref['assets'] ?? null) ? $ref['assets'] : [],
            ];
        }

        $resolvedIds = collect($resolved)->map(fn ($row) => (int) ($row['id'] ?? 0))
            ->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $assetIds = collect($resolved)->flatMap(fn ($row) => (array) ($row['asset_ids'] ?? []))
            ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $assetPaths = collect($resolved)->flatMap(fn ($row) => (array) ($row['asset_paths'] ?? []))
            ->map(fn ($path) => (string) $path)->filter(fn ($path) => $path !== '')->unique()->values()->all();
        $terms = collect($resolved)->map(fn ($row) => (string) ($row['name'] ?? ''))
            ->filter(fn ($name) => $name !== '')->unique()->values()->all();

        return [
            'selection_mode' => 'plan_blueprint',
            'requested_ids' => $resolvedIds,
            'detected_ids' => [],
            'resolved_ids' => $resolvedIds,
            'resolved_asset_ids' => $assetIds,
            'resolved_asset_paths' => $assetPaths,
            'resolved' => $resolved,
            'recognized_terms' => $terms,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $profileData
     * @return array<string, mixed>
     */
    private function enrichPayloadWithContentStrategy(
        ContentPlan $plan,
        array $payload,
        array $strategy,
        array $profileData
    ): array {
        $pack = $this->contentAngleEngine->build([
            'platform' => (string) ($payload['platform'] ?? 'instagram'),
            'format' => (string) ($payload['format'] ?? 'post'),
            'goal' => (string) ($payload['objective'] ?? data_get($plan->settings, 'goal', data_get($strategy, 'analysis_framework.primary_goal', 'Awareness'))),
            'audience' => (string) data_get($profileData, 'target', data_get($strategy, 'brand_voice.target', '')),
            'industry' => (string) data_get($profileData, 'industry', data_get($strategy, 'brand_voice.industry', '')),
            'topic' => (string) ($payload['content_angle'] ?? $payload['pillar'] ?? ''),
            'rubric' => (string) ($payload['rubric'] ?? ''),
            'editorial_mode' => (string) ($payload['editorial_mode'] ?? 'evergreen'),
            'trend_confidence' => $payload['trend_confidence'] ?? null,
            'trend_opportunity' => (array) ($payload['trend_opportunity'] ?? []),
            'tenant_profile' => $profileData,
            'strategy' => $strategy,
            'item_brain' => $payload,
            'plan' => [
                'goal' => data_get($plan->settings, 'goal'),
                'tone' => data_get($plan->settings, 'tone'),
            ],
        ]);

        return array_replace_recursive($payload, $this->contentAngleEngine->toPlanPayloadFragment($pack));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $profileData
     * @return array<string, mixed>
     */
    private function buildContentStrategyMeta(
        ContentPlan $plan,
        array $payload,
        array $strategy,
        array $profileData
    ): array {
        $pack = $this->contentAngleEngine->build([
            'platform' => (string) ($payload['platform'] ?? 'instagram'),
            'format' => (string) ($payload['format'] ?? 'post'),
            'goal' => (string) ($payload['objective'] ?? data_get($plan->settings, 'goal', data_get($strategy, 'analysis_framework.primary_goal', 'Awareness'))),
            'audience' => (string) data_get($profileData, 'target', data_get($strategy, 'brand_voice.target', '')),
            'industry' => (string) data_get($profileData, 'industry', data_get($strategy, 'brand_voice.industry', '')),
            'topic' => (string) ($payload['narrative_angle'] ?? $payload['content_angle'] ?? $payload['pillar'] ?? ''),
            'rubric' => (string) ($payload['rubric'] ?? ''),
            'editorial_mode' => (string) ($payload['editorial_mode'] ?? 'evergreen'),
            'trend_confidence' => $payload['trend_confidence'] ?? null,
            'trend_opportunity' => (array) ($payload['trend_opportunity'] ?? []),
            'tenant_profile' => $profileData,
            'strategy' => $strategy,
            'item_brain' => $payload,
            'plan' => [
                'goal' => data_get($plan->settings, 'goal'),
                'tone' => data_get($plan->settings, 'tone'),
            ],
        ]);

        return $this->contentAngleEngine->toMetaFragment($pack);
    }
}

