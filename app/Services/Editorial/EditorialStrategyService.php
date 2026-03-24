<?php

namespace App\Services\Editorial;

use App\Models\BrandAsset;
use App\Models\EditorialStrategy;
use App\Models\TenantProfile;
use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialStrategyService
{
    public function __construct(
        private readonly CreativeDirectionComposer $creativeDirectionComposer,
        private readonly TrendOpportunitySynthesisService $trendOpportunitySynthesis
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

        $voice = [
            'tone' => (string) ($profile?->default_tone ?? 'professionale'),
            'values' => $this->explode((string) ($profile?->values ?? '')),
            'target' => (string) ($profile?->target ?? ''),
            'industry' => (string) ($profile?->industry ?? ''),
        ];

        $pillars = $this->buildPillars($profile);
        $rubrics = $this->buildRubrics($pillars);
        $ctaRules = $this->buildCtaRules($profile);
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
        $trendIntelligence = $this->trendOpportunitySynthesis->buildForTenant($tenantId, $profile, [
            'strategy' => [
                'analysis_framework' => $analysis,
                'publishing_system' => $publishing,
            ],
            'platforms' => (array) ($profile?->default_platforms ?? ['instagram']),
            'formats' => (array) ($profile?->default_formats ?? ['post']),
            'asset_readiness' => $assetReadiness,
        ]);

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

        $strategy->analysis_framework = $analysis;
        $strategy->visual_system = $visual;
        $strategy->publishing_system = $publishing;
        $strategy->creative_direction = $creativeDirection;
        $strategy->trend_intelligence = $trendIntelligence;
        $strategy->strategy_notes = (string) ($studio['strategy_notes'] ?? $strategy->strategy_notes ?? '');
        $strategy->is_locked = (bool) ($studio['is_locked'] ?? $strategy->is_locked ?? false);
        $strategy->manual_updated_at = Carbon::now();
        $strategy->save();

        return $strategy->fresh() ?? $strategy;
    }

    public function toRuntimeContext(EditorialStrategy $strategy, array $brandReferences = []): array
    {
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
            'strategy_notes' => (string) ($strategy->strategy_notes ?? ''),
            'strategy_locked' => (bool) ($strategy->is_locked ?? false),
            'strategy_id' => (int) $strategy->id,
            'strategy_updated_at' => optional($strategy->updated_at)->toDateTimeString(),
            'brand_references' => $brandReferences,
        ];
    }

    private function buildPillars(?TenantProfile $profile): array
    {
        $services = $this->explode((string) ($profile?->services ?? ''));
        $industry = Str::title((string) ($profile?->industry ?? 'Attivita'));

        $fallback = [
            "Guide {$industry}",
            "Case Study {$industry}",
            "Dietro le Quinte {$industry}",
            "Offerta {$industry}",
        ];

        $base = array_values(array_unique(array_filter(array_merge(
            array_slice($services, 0, 4),
            $fallback
        ))));

        return array_map(fn ($p) => Str::limit(Str::title($p), 80, ''), array_slice($base, 0, 8));
    }

    private function buildRubrics(array $pillars): array
    {
        $defaults = config('editorial.rubrics', []);
        $mainPillar = $pillars[0] ?? 'Educativo';
        $secondary = $pillars[1] ?? $mainPillar;

        $byName = [
            'Educativo' => [$mainPillar, $secondary],
            'Educational' => [$mainPillar, $secondary],
            'Prova Sociale' => [$secondary],
            'Social Proof' => [$secondary],
            'Storia Brand' => [$mainPillar],
            'Brand Story' => [$mainPillar],
            'Offerta' => [$secondary, $mainPillar],
            'Offer' => [$secondary, $mainPillar],
            'Community' => [$mainPillar],
            'Trend' => [$secondary],
        ];

        $out = [];
        foreach ($defaults as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'weight' => (float) ($row['weight'] ?? 0),
                'pillars' => $byName[$name] ?? [$mainPillar],
            ];
        }

        if (empty($out)) {
            $out = [
                ['name' => 'Educativo', 'weight' => 0.4, 'pillars' => [$mainPillar]],
                ['name' => 'Prova Sociale', 'weight' => 0.2, 'pillars' => [$secondary]],
                ['name' => 'Storia Brand', 'weight' => 0.2, 'pillars' => [$mainPillar]],
                ['name' => 'Offerta', 'weight' => 0.2, 'pillars' => [$secondary]],
            ];
        }

        return $out;
    }

    private function buildCtaRules(?TenantProfile $profile): array
    {
        $profileCta = trim((string) ($profile?->cta ?? ''));

        $pool = [
            'Commenta la tua esperienza.',
            'Salva il post per usarlo come checklist.',
            'Scrivici in DM per una consulenza.',
            'Prenota una call di approfondimento.',
            'Visita il sito per maggiori dettagli.',
        ];

        if ($profileCta !== '') {
            array_unshift($pool, $profileCta);
        }

        return [
            'primary_pool' => array_values(array_unique($pool)),
            'avoid_consecutive' => true,
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
        return [
            'posts_per_week' => (int) ($profile?->default_posts_per_week ?: 5),
            'best_days' => 'Lun-Mar-Gio',
            'best_times' => $this->sanitizeTimeSlots('11:00,15:00,19:00'),
            'channel_priority' => implode(', ', (array) ($profile?->default_platforms ?? ['instagram'])),
            'format_priority' => implode(', ', (array) ($profile?->default_formats ?? ['post'])),
            'cadence_rule' => 'Alterna informativo/prova sociale/offerta evitando ripetizioni consecutive.',
        ];
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
