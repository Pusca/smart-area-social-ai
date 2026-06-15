<?php

namespace App\Services\Editorial;

use App\Models\BrandAsset;
use App\Models\EditorialStrategy;
use App\Models\TenantProfile;
use App\Services\Learning\TenantLearningLoopService;
use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialStrategyService
{
    public function __construct(
        private readonly CreativeDirectionComposer $creativeDirectionComposer,
        private readonly TrendOpportunitySynthesisService $trendOpportunitySynthesis,
        private readonly TrendBriefService $trendBriefService,
        private readonly TenantLearningLoopService $tenantLearningLoopService,
        private readonly IndustryPresetService $industryPresets,
        private readonly ViralContentFrameworkService $viralFramework
    ) {
    }

    public function refreshForTenant(
        int $tenantId,
        ?TenantProfile $profile = null,
        array $overrides = [],
        bool $force = false
    ): EditorialStrategy
    {
        $profile ??= TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $existing = $this->forTenant($tenantId);

        if ($existing && (bool) $existing->is_locked === true && !$force) {
            return $existing;
        }

        $industry = (string) ($profile?->industry ?? '');

        /**
         * Applica il preset di industry come punto di partenza strategico.
         * Il preset fornisce pillars, rubrics, CTA rules e brand voice ottimizzati
         * per il settore. Se il tenant ha già overrides manuali, questi prevalgono.
         * Se il tenant non ha industry o non c'è preset, si usa la logica generica.
         */
        $industryPreset = $this->industryPresets->getPresetForIndustry($industry);

        $voice = [
            'tone'     => (string) ($profile?->default_tone
                ?? data_get($industryPreset, 'brand_voice.tone')
                ?? 'professionale'),
            'values'   => $this->explode((string) ($profile?->values ?? ''))
                ?: (array) data_get($industryPreset, 'brand_voice.values', []),
            'target'   => (string) ($profile?->target ?? ''),
            'industry' => $industry,
        ];

        // I pillars dal preset vengono usati come fallback se il profilo non ha servizi configurati.
        $pillars  = $this->buildPillars($profile, $industryPreset);
        $rubrics  = $this->buildRubrics($pillars, $industryPreset);
        $ctaRules = $this->buildCtaRules($profile, $industryPreset);
        $constraints = [
            'no_repeat_days' => config('editorial.duplicate_window_days', 180),
            'soft_similarity_threshold' => config('editorial.soft_similarity_threshold', 0.78),
            'max_regeneration_attempts' => config('editorial.max_regeneration_attempts', 2),
            'pillar_repeat_limit' => 2,
            'cta_repeat_limit' => 1,
            'strict_asset_mode' => (bool) config('generation.strict_asset_mode', true),
        ];

        $assetReadiness = $this->loadAssetReadiness($tenantId);
        $analysis = $this->buildAnalysisFramework($profile, $assetReadiness);
        $visual = $this->buildVisualSystem($profile, $assetReadiness);
        $publishing = $this->buildPublishingSystem($profile);
        $creativeDirection = $this->creativeDirectionComposer->compose($profile, $assetReadiness);
        $learningPreferences = (bool) config('social_manager.features.tenant_learning_v1', true)
            ? $this->tenantLearningLoopService->refreshForTenant($tenantId)
            : (array) ($profile?->learning_preferences ?? []);
        $trendIntelligence = $this->trendOpportunitySynthesis->buildForTenant($tenantId, $profile, [
            'strategy' => [
                'analysis_framework' => $analysis,
                'publishing_system' => $publishing,
            ],
            'platforms' => (array) ($profile?->default_platforms ?? ['instagram']),
            'formats' => (array) ($profile?->default_formats ?? ['post']),
            'asset_readiness' => $assetReadiness,
            'learning_preferences' => $learningPreferences,
        ]);

        $viralFrameworkData = $this->viralFramework->build(
            platforms: (array) ($profile?->default_platforms ?? ['instagram']),
            formats: (array) ($profile?->default_formats ?? ['post']),
            industryPreset: (array) $industryPreset,
            industry: $industry,
        );

        $payload = [
            'brand_voice' => array_merge($voice, Arr::get($overrides, 'brand_voice', [])),
            'pillars' => Arr::get($overrides, 'pillars', $pillars),
            'rubrics' => Arr::get($overrides, 'rubrics', $rubrics),
            'cta_rules' => Arr::get($overrides, 'cta_rules', $ctaRules),
            'constraints' => array_merge($constraints, Arr::get($overrides, 'constraints', [])),
            'analysis_framework' => array_merge($analysis, Arr::get($overrides, 'analysis_framework', [])),
            'visual_system' => array_merge($visual, Arr::get($overrides, 'visual_system', [])),
            'publishing_system' => array_merge($publishing, Arr::get($overrides, 'publishing_system', [])),
            'creative_direction' => array_replace_recursive($creativeDirection, Arr::get($overrides, 'creative_direction', [])),
            'trend_intelligence' => array_replace_recursive($trendIntelligence, Arr::get($overrides, 'trend_intelligence', [])),
            'viral_framework' => array_replace_recursive($viralFrameworkData, Arr::get($overrides, 'viral_framework', [])),
            'last_refreshed_at' => Carbon::now(),
        ];

        if ($existing) {
            $payload['is_locked'] = (bool) $existing->is_locked;
            $payload['strategy_notes'] = $existing->strategy_notes;
            $payload['manual_updated_at'] = $existing->manual_updated_at;
        }

        return EditorialStrategy::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            $payload
        );
    }

    public function forTenant(int $tenantId): ?EditorialStrategy
    {
        return EditorialStrategy::query()->where('tenant_id', $tenantId)->first();
    }

    public function applyStudioInputs(EditorialStrategy $strategy, array $studio): EditorialStrategy
    {
        $analysis = array_merge((array) $strategy->analysis_framework, (array) ($studio['analysis_framework'] ?? []));
        $visual = array_merge((array) $strategy->visual_system, (array) ($studio['visual_system'] ?? []));
        $publishing = array_merge((array) $strategy->publishing_system, (array) ($studio['publishing_system'] ?? []));
        $creativeDirection = array_replace_recursive((array) $strategy->creative_direction, (array) ($studio['creative_direction'] ?? []));
        $trendIntelligence = array_replace_recursive((array) $strategy->trend_intelligence, (array) ($studio['trend_intelligence'] ?? []));
        $viralFrameworkData = array_replace_recursive((array) $strategy->viral_framework, (array) ($studio['viral_framework'] ?? []));

        $strategy->analysis_framework = $analysis;
        $strategy->visual_system = $visual;
        $strategy->publishing_system = $publishing;
        $strategy->creative_direction = $creativeDirection;
        $strategy->trend_intelligence = $trendIntelligence;
        $strategy->viral_framework = $viralFrameworkData;
        $strategy->strategy_notes = (string) ($studio['strategy_notes'] ?? $strategy->strategy_notes ?? '');
        $strategy->is_locked = (bool) ($studio['is_locked'] ?? $strategy->is_locked ?? false);
        $strategy->manual_updated_at = Carbon::now();
        $strategy->save();

        return $strategy->fresh() ?? $strategy;
    }

    public function toRuntimeContext(EditorialStrategy $strategy, array $brandReferences = []): array
    {
        $tenantId = (int) $strategy->tenant_id;
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $tenantLearning = (bool) config('social_manager.features.tenant_learning_v1', true)
            ? ((is_array($profile?->learning_preferences) && !empty($profile->learning_preferences))
                ? (array) $profile->learning_preferences
                : $this->tenantLearningLoopService->refreshForTenant($tenantId))
            : [];
        $trendBrief = (bool) config('social_manager.features.trend_brief_v1', true)
            ? $this->trendBriefService->getBriefForTenant($tenantId, $profile, [
                'trend_intelligence' => $strategy->trend_intelligence ?? [],
            ], [
                'platforms' => (array) ($profile?->default_platforms ?? ['instagram']),
                'formats' => (array) ($profile?->default_formats ?? ['post']),
                'learning_preferences' => $tenantLearning,
            ])
            : [];

        return [
            'brand_voice' => $strategy->brand_voice ?? [],
            'pillars' => $strategy->pillars ?? [],
            'rubrics' => $strategy->rubrics ?? [],
            'cta_rules' => $strategy->cta_rules ?? [],
            'constraints' => $strategy->constraints ?? [],
            'analysis_framework' => $strategy->analysis_framework ?? [],
            'visual_system' => $strategy->visual_system ?? [],
            'publishing_system' => $strategy->publishing_system ?? [],
            'creative_direction' => $strategy->creative_direction ?? [],
            'trend_intelligence' => $strategy->trend_intelligence ?? [],
            'viral_framework' => $strategy->viral_framework ?? [],
            'strategy_notes' => (string) ($strategy->strategy_notes ?? ''),
            'strategy_locked' => (bool) ($strategy->is_locked ?? false),
            'strategy_id' => (int) $strategy->id,
            'strategy_updated_at' => optional($strategy->updated_at)->toDateTimeString(),
            'brand_references' => $brandReferences,
            'trend_brief' => $trendBrief,
            'tenant_learning' => $tenantLearning,
        ];
    }

    /**
     * Costruisce i pillars editoriali.
     * Priorità: servizi del profilo → pillars dal preset industry → fallback generico.
     *
     * @param  array<string, mixed>|null  $industryPreset
     * @return array<int, string>
     */
    private function buildPillars(?TenantProfile $profile, ?array $industryPreset = null): array
    {
        $services = $this->explode((string) ($profile?->services ?? ''));
        $industry = Str::title((string) ($profile?->industry ?? 'Attivita'));

        // Pillars dal preset come sostituto del generico "Guide {industry}" ecc.
        $presetPillars = array_map(
            fn (string $p) => Str::limit(Str::title($p), 80, ''),
            (array) data_get($industryPreset, 'pillars', [])
        );

        $genericFallback = [
            "Guide {$industry}",
            "Case Study {$industry}",
            "Dietro le Quinte {$industry}",
            "Offerta {$industry}",
        ];

        // I servizi del profilo hanno la priorità massima: sono specifici del brand.
        // Se mancano, usiamo i pillars del preset; se manca anche il preset, il generico.
        $fallback = !empty($presetPillars) ? $presetPillars : $genericFallback;

        $base = array_values(array_unique(array_filter(array_merge(
            array_slice($services, 0, 4),
            $fallback
        ))));

        return array_map(fn ($p) => Str::limit(Str::title($p), 80, ''), array_slice($base, 0, 8));
    }

    /**
     * Costruisce le rubrics.
     * Se il preset industry ha rubrics proprie, le usa al posto del config generico.
     *
     * @param  array<int, string>  $pillars
     * @param  array<string, mixed>|null  $industryPreset
     * @return array<int, array<string, mixed>>
     */
    private function buildRubrics(array $pillars, ?array $industryPreset = null): array
    {
        $mainPillar = $pillars[0] ?? 'Educativo';
        $secondary  = $pillars[1] ?? $mainPillar;

        // Se il preset industry ha rubrics specifiche, usale direttamente
        $presetRubrics = (array) data_get($industryPreset, 'rubrics', []);
        if (!empty($presetRubrics)) {
            return array_map(function (array $row) use ($mainPillar): array {
                return [
                    'name'    => (string) ($row['name'] ?? ''),
                    'weight'  => (float) ($row['weight'] ?? 0.2),
                    'focus'   => (string) ($row['focus'] ?? ''),
                    'pillars' => [$mainPillar],
                ];
            }, $presetRubrics);
        }

        // Fallback: rubrics generiche da config
        $defaults = config('editorial.rubrics', []);
        $byName = [
            'Educativo'    => [$mainPillar, $secondary],
            'Educational'  => [$mainPillar, $secondary],
            'Prova Sociale' => [$secondary],
            'Social Proof' => [$secondary],
            'Storia Brand' => [$mainPillar],
            'Brand Story'  => [$mainPillar],
            'Offerta'      => [$secondary, $mainPillar],
            'Offer'        => [$secondary, $mainPillar],
            'Community'    => [$mainPillar],
            'Trend'        => [$secondary],
        ];

        $out = [];
        foreach ($defaults as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'    => $name,
                'weight'  => (float) ($row['weight'] ?? 0),
                'pillars' => $byName[$name] ?? [$mainPillar],
            ];
        }

        if (empty($out)) {
            $out = [
                ['name' => 'Educativo',    'weight' => 0.4, 'pillars' => [$mainPillar]],
                ['name' => 'Prova Sociale', 'weight' => 0.2, 'pillars' => [$secondary]],
                ['name' => 'Storia Brand', 'weight' => 0.2, 'pillars' => [$mainPillar]],
                ['name' => 'Offerta',      'weight' => 0.2, 'pillars' => [$secondary]],
            ];
        }

        return $out;
    }

    /**
     * Costruisce le CTA rules.
     * Il preset industry fornisce primary_cta e avoid list settoriali.
     *
     * @param  array<string, mixed>|null  $industryPreset
     * @return array<string, mixed>
     */
    private function buildCtaRules(?TenantProfile $profile, ?array $industryPreset = null): array
    {
        $profileCta   = trim((string) ($profile?->cta ?? ''));
        $presetPrimary = trim((string) data_get($industryPreset, 'cta_rules.primary_cta', ''));
        $presetSecondary = trim((string) data_get($industryPreset, 'cta_rules.secondary_cta', ''));
        $presetAvoid  = (array) data_get($industryPreset, 'cta_rules.avoid', []);

        $genericPool = [
            'Commenta la tua esperienza.',
            'Salva il post per usarlo come checklist.',
            'Scrivici in DM per una consulenza.',
            'Prenota una call di approfondimento.',
            'Visita il sito per maggiori dettagli.',
        ];

        // Costruisce il pool: profilo > preset > generico
        $pool = array_values(array_unique(array_filter([
            $profileCta,
            $presetPrimary,
            $presetSecondary,
            ...$genericPool,
        ])));

        return [
            'primary_pool'      => array_values(array_unique($pool)),
            'avoid_consecutive' => true,
            'avoid'             => $presetAvoid,
            'compliance_note'   => trim((string) data_get($industryPreset, 'cta_rules.compliance_note', '')),
        ];
    }

    private function explode(string $value): array
    {
        $parts = preg_split('/[,;\n]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $trim = trim($part);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }
        return $out;
    }

    /**
     * @param  array<string, int>  $assetReadiness
     * @return array<string, mixed>
     */
    private function buildAnalysisFramework(?TenantProfile $profile, array $assetReadiness): array
    {
        $overlayPreferences = is_array($profile?->overlay_preferences) ? $profile->overlay_preferences : [];

        return [
            'primary_goal' => (string) ($profile?->default_goal ?: 'Awareness + Lead'),
            'secondary_goal' => 'Engagement + Fiducia',
            'kpi_primary' => 'Copertura qualificata',
            'kpi_secondary' => 'Interazioni utili e conversione contatti',
            'audience_focus' => (string) ($profile?->target ?: ''),
            'offer_focus' => (string) ($profile?->seasonal_offers ?: ''),
            'trend_appetite' => (string) ($overlayPreferences['trend_appetite'] ?? 'medium'),
            'preferred_hook_intensity' => (string) ($overlayPreferences['preferred_hook_intensity'] ?? 'medium'),
            'professionalism_guardrail_level' => (string) ($overlayPreferences['professionalism_guardrail_level'] ?? 'high'),
            'asset_readiness' => [
                'total' => (int) ($assetReadiness['total'] ?? 0),
                'images' => (int) ($assetReadiness['images'] ?? 0),
                'logos' => (int) ($assetReadiness['logos'] ?? 0),
                'videos' => (int) ($assetReadiness['videos'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, int>  $assetReadiness
     * @return array<string, mixed>
     */
    private function buildVisualSystem(?TenantProfile $profile, array $assetReadiness): array
    {
        $palette = $this->parsePalette((string) ($profile?->brand_palette ?? ''));
        $hasLogo = ((int) ($assetReadiness['logos'] ?? 0)) > 0;
        $overlayPreferences = is_array($profile?->overlay_preferences) ? $profile->overlay_preferences : [];

        return [
            'style' => 'Pulito, moderno, realistico, orientato conversione',
            'mood' => 'Professionale con energia positiva',
            'palette' => $palette,
            'palette_mode' => !empty($palette) ? 'brand_palette' : 'auto_brand_safe',
            'typography' => [
                'tone_preset' => (string) ($overlayPreferences['tone_preset'] ?? $overlayPreferences['font_preset'] ?? 'modern'),
                'font_preset' => (string) ($overlayPreferences['font_preset'] ?? $overlayPreferences['tone_preset'] ?? 'modern'),
                'font_family' => (string) ($overlayPreferences['font_family'] ?? 'arial'),
                'fallback_font_family' => (string) ($overlayPreferences['fallback_font_family'] ?? 'segoe_ui'),
                'preset' => (string) ($overlayPreferences['preset'] ?? 'modern_split_caption'),
                'safe_area' => (string) ($overlayPreferences['safe_area'] ?? 'upper_third'),
                'auto_enabled' => (bool) ($overlayPreferences['auto_enabled'] ?? true),
                'preferred_hook_intensity' => (string) ($overlayPreferences['preferred_hook_intensity'] ?? 'medium'),
                'professionalism_guardrail_level' => (string) ($overlayPreferences['professionalism_guardrail_level'] ?? 'high'),
            ],
            'logo_rule' => $hasLogo
                ? 'Usa solo loghi reali caricati in assets (mai testo logo generato).'
                : 'Nessun logo disponibile: non inventare logo o brand text.',
            'visual_do' => 'Composizioni leggibili, soggetti reali, contrasto chiaro, stile coerente.',
            'visual_dont' => 'No watermark, no testo casuale, no logo inventato, no scene incoerenti.',
        ];
    }

    private function buildPublishingSystem(?TenantProfile $profile): array
    {
        $platforms = (array) ($profile?->default_platforms ?? ['instagram']);
        $primaryPlatform = strtolower(trim((string) ($platforms[0] ?? 'instagram')));

        $platformSchedule = [
            'instagram' => ['days' => 'Mar-Mer-Gio', 'times' => '09:00,12:00,19:00', 'frequency' => 5],
            'tiktok'    => ['days' => 'Mar-Mer-Gio-Ven', 'times' => '07:00,16:00,21:00', 'frequency' => 7],
            'linkedin'  => ['days' => 'Mar-Mer-Gio', 'times' => '08:00,10:00,17:00', 'frequency' => 4],
            'facebook'  => ['days' => 'Mer-Gio-Ven', 'times' => '13:00,15:00,19:00', 'frequency' => 4],
        ];

        $schedule = $platformSchedule[$primaryPlatform] ?? $platformSchedule['instagram'];

        return [
            'posts_per_week'  => (int) ($profile?->default_posts_per_week ?: $schedule['frequency']),
            'best_days'       => $schedule['days'],
            'best_times'      => $this->sanitizeTimeSlots($schedule['times']),
            'channel_priority'=> implode(', ', $platforms),
            'format_priority' => implode(', ', (array) ($profile?->default_formats ?? ['post'])),
            'cadence_rule'    => 'Alterna contenuto di valore (educativo/storytelling) con contenuto di conversione (offerta/prova sociale). Non più di 2 post promozionali consecutivi.',
            'platform_note'   => $this->platformPublishingNote($primaryPlatform),
        ];
    }

    private function platformPublishingNote(string $platform): string
    {
        return match ($platform) {
            'tiktok'   => 'Su TikTok la consistenza è più importante della perfezione. Meglio 4 video discreti che 1 perfetto a settimana.',
            'linkedin' => 'Su LinkedIn la frequenza ideale è 3-5 post/settimana. Il martedì e giovedì mattina hanno il massimo engagement professionale.',
            'facebook' => 'Su Facebook i video nativi ottengono 10x più reach dei link. Max 2 post/giorno.',
            default    => 'Su Instagram i Reels distribuiti il mercoledì 9-11am hanno storicamente il miglior reach iniziale.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function parsePalette(string $value): array
    {
        $parts = preg_split('/[,;\s]+/', trim($value)) ?: [];
        $hex = [];
        foreach ($parts as $part) {
            $p = strtoupper(trim($part));
            if ($p === '') {
                continue;
            }
            if (!str_starts_with($p, '#')) {
                $p = '#' . $p;
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $p) === 1) {
                $hex[] = $p;
            }
        }
        return array_values(array_unique(array_slice($hex, 0, 8)));
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeTimeSlots(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $p = trim($part);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^\d{1,2}:\d{2}$/', $p) !== 1) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $p, 2));
            if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
                continue;
            }
            $out[] = sprintf('%02d:%02d', $h, $m);
        }

        if (empty($out)) {
            return ['11:00', '15:00', '19:00'];
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, int>
     */
    private function loadAssetReadiness(int $tenantId): array
    {
        $counts = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->get(['kind'])
            ->countBy(fn (BrandAsset $asset) => strtolower(trim((string) $asset->kind)));

        return [
            'total' => (int) $counts->sum(),
            'images' => (int) ($counts['image'] ?? 0),
            'logos' => (int) ($counts['logo'] ?? 0),
            'videos' => (int) ($counts['video'] ?? 0),
        ];
    }
}
