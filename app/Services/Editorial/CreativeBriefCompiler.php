<?php

namespace App\Services\Editorial;

use App\DTO\CreativeBrief;
use App\Models\ContentItem;
use App\Services\Learning\TenantLearningLoopService;
use Illuminate\Support\Str;

class CreativeBriefCompiler
{
    public function __construct(
        private readonly TrendBriefService $trendBriefService,
        private readonly TenantLearningLoopService $tenantLearningLoopService
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function compileForContentItem(ContentItem $item, array $context = []): CreativeBrief
    {
        $tenantId = (int) $item->tenant_id;
        $strategy = (array) ($context['strategy'] ?? []);
        $tenantProfile = (array) ($context['tenant_profile'] ?? []);
        $itemBrain = (array) ($context['item_brain'] ?? []);
        $assetIdentity = (array) ($context['asset_identity'] ?? []);
        $memorySummary = (array) ($context['memory_summary'] ?? []);
        $trendBrief       = (array) ($context['trend_brief'] ?? []);
        $trendDrivenIdeas = (array) ($context['trend_driven_ideas'] ?? []);
        $learning = (array) ($context['tenant_learning'] ?? []);
        $manualBrief = trim((string) ($context['brief_seed'] ?? $item->caption ?? $item->title ?? ''));

        if ($trendBrief === [] && (bool) config('social_manager.features.trend_brief_v1', true)) {
            $trendBrief = $this->trendBriefService->getBriefForTenant($tenantId, $tenantProfile, [
                'strategy' => $strategy,
                'learning_preferences' => $learning,
                'platforms' => $item->platforms(),
                'formats' => [(string) $item->format],
            ]);
        }

        if ($learning === [] && (bool) config('social_manager.features.tenant_learning_v1', true)) {
            $learning = $this->tenantLearningLoopService->buildForTenant($tenantId);
        }

        $objective = trim((string) data_get($itemBrain, 'objective', data_get($strategy, 'analysis_framework.primary_goal', data_get($tenantProfile, 'default_goal', 'Awareness'))));
        $audience = trim((string) data_get($tenantProfile, 'target', data_get($strategy, 'brand_voice.target', '')));
        $contentPillar = trim((string) data_get($itemBrain, 'pillar', data_get($itemBrain, 'rubric', '')));
        $contentAngle = trim((string) data_get($itemBrain, 'narrative_angle', data_get($itemBrain, 'angle', $manualBrief)));
        $hookStrategy = [
            'preferred_family' => (string) data_get($learning, 'preferred_hook_families.0', data_get($itemBrain, 'content_strategy_type', data_get($itemBrain, 'hook_style', 'authoritative'))),
            'intensity' => (string) data_get($strategy, 'analysis_framework.preferred_hook_intensity', data_get($tenantProfile, 'overlay_preferences.preferred_hook_intensity', 'medium')),
            'primary_pattern' => (string) data_get($trendBrief, 'recommended_hook_patterns.0.pattern', ''),
            'opening_preference' => (string) data_get($itemBrain, 'hook_meta.platform_specific_opening_structure', ''),
        ];

        $proofPoints = collect(array_merge(
            array_values(array_filter(array_map('strval', (array) data_get($memorySummary, 'feedback_summary.positive_signals', [])))),
            array_values(array_filter(array_map('strval', (array) data_get($itemBrain, 'professionality_guardrails', [])))),
            array_values(array_filter(array_map('strval', (array) data_get($itemBrain, 'trend_hook_patterns', []))))
        ))
            ->map(fn (string $value): string => trim(Str::limit($value, 180, '')))
            ->filter()
            ->unique()
            ->take((int) config('social_manager.creative_brief.max_proof_points', 6))
            ->values()
            ->all();

        $ctaStyle = [
            'preferred_mode' => (string) data_get($learning, 'preferred_cta_styles.0', data_get($itemBrain, 'hook_meta.cta_mode', 'consultative_soft')),
            'tenant_default' => (string) data_get($tenantProfile, 'cta', ''),
            'trend_safe_style' => (string) data_get($trendBrief, 'recommended_cta_styles.0.style', ''),
        ];

        $forbiddenElements = collect(array_merge(
            array_values(array_filter(array_map('strval', (array) data_get($memorySummary, 'feedback_summary.hard_avoid_rules', [])))),
            array_values(array_filter(array_map('strval', (array) data_get($trendBrief, 'trends_to_avoid', [])))),
            array_values(array_filter(array_map('strval', (array) data_get($strategy, 'creative_direction.professional_direction.blocked_variations', []))))
        ))
            ->map(fn (string $value): string => trim(Str::limit($value, 180, '')))
            ->filter()
            ->unique()
            ->take((int) config('social_manager.creative_brief.max_forbidden_elements', 10))
            ->values()
            ->all();

        $identityConstraints = collect((array) data_get($assetIdentity, 'slots', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row, string $slot): array {
                return [
                    'slot' => $slot,
                    'name' => (string) ($row['name'] ?? ''),
                    'maintain' => array_values(array_filter(array_map('strval', (array) ($row['maintain_elements'] ?? $row['locked_elements'] ?? [])))),
                    'allow_change' => array_values(array_filter(array_map('strval', (array) ($row['changeable_elements'] ?? $row['allowed_changes'] ?? $row['allowed_transforms'] ?? [])))),
                    'strictness' => (string) ($row['strictness_level'] ?? data_get($row, 'identity_pack.strictness_level', 'balanced')),
                ];
            })
            ->take((int) config('social_manager.creative_brief.max_identity_constraints', 6))
            ->values()
            ->all();

        $visualLanguage = [
            'style' => (string) data_get($strategy, 'visual_system.style', ''),
            'mood' => (string) data_get($strategy, 'visual_system.mood', ''),
            'palette' => (array) data_get($strategy, 'visual_system.palette', []),
            'typography' => (array) data_get($strategy, 'visual_system.typography', []),
        ];

        $trendOverlays = [
            'current_relevant_themes' => (array) data_get($trendBrief, 'current_relevant_themes', []),
            'recommended_post_angles' => (array) data_get($trendBrief, 'recommended_post_angles', []),
            'recommended_reel_structures' => (array) data_get($trendBrief, 'recommended_reel_structures', []),
            'recommended_hook_patterns' => (array) data_get($trendBrief, 'recommended_hook_patterns', []),
            'recommended_cta_styles' => (array) data_get($trendBrief, 'recommended_cta_styles', []),
        ];

        $publishabilityConstraints = [
            'professionalism_guardrail_level' => (string) data_get($strategy, 'analysis_framework.professionalism_guardrail_level', 'high'),
            'publish_gate_required' => (bool) config('social_manager.features.publish_gate_v1', true),
            'identity_guard_required' => (bool) config('social_manager.features.identity_guard_v1', true),
            'quality_status_required' => 'pass|pass_with_warnings',
        ];

        return CreativeBrief::fromArray([
            'version' => (string) config('social_manager.creative_brief.version', 'creative_brief_v1'),
            'compiled_at' => now()->toDateTimeString(),
            'tenant_id' => $tenantId,
            'content_item_id' => (int) $item->id,
            'objective' => $objective,
            'audience' => $audience,
            'content_pillar' => $contentPillar,
            'content_angle' => $contentAngle,
            'hook_strategy' => $hookStrategy,
            'proof_points' => $proofPoints,
            'cta_style' => $ctaStyle,
            'forbidden_elements' => $forbiddenElements,
            'identity_constraints' => $identityConstraints,
            'visual_language' => $visualLanguage,
            'trend_overlays' => $trendOverlays,
            'trend_driven_ideas' => $trendDrivenIdeas,
            'publishability_constraints' => $publishabilityConstraints,
            'learning_bias' => [
                'preferred_hook_families' => (array) data_get($learning, 'preferred_hook_families', []),
                'preferred_cta_styles' => (array) data_get($learning, 'preferred_cta_styles', []),
                'formats_that_underperform' => (array) data_get($learning, 'formats_that_underperform', []),
                'provider_paths_to_avoid_for_identity_heavy' => (array) data_get($learning, 'provider_paths_to_avoid_for_identity_heavy', []),
            ],
            'source_summary' => [
                'trend_brief_freshness_score' => data_get($trendBrief, 'freshness_score'),
                'trend_brief_confidence_score' => data_get($trendBrief, 'confidence_score'),
                'learning_generated_at' => (string) data_get($learning, 'generated_at', ''),
                'strategy_id' => data_get($strategy, 'strategy_id'),
            ],
        ]);
    }
}
