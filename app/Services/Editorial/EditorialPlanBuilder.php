<?php

namespace App\Services\Editorial;

use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialPlanBuilder
{
    public function __construct(
        private readonly TrendOpportunitySynthesisService $trendOpportunitySynthesis
    ) {
    }

    public function buildPlan(
        int $tenantId,
        array $strategy,
        array $history,
        array $period,
        array $options = []
    ): array {
        $start = Carbon::parse((string) ($period['start'] ?? now()->toDateString()))->startOfDay();
        $end = Carbon::parse((string) ($period['end'] ?? $start->copy()->addDays(13)->toDateString()))->endOfDay();
        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }
        $totalPosts = max(1, (int) ($period['total_posts'] ?? 5));

        $feedbackSummary = (array) data_get($options, 'memory.feedback_summary', data_get($options, 'feedback_summary', []));
        $platforms = array_values(array_filter((array) ($options['platforms'] ?? ['instagram'])));
        $formats = array_values(array_filter((array) ($options['formats'] ?? ['post'])));
        $platforms = $this->applyPreferenceBias($platforms, (array) ($feedbackSummary['preferred_platforms'] ?? []), 'instagram');
        $formats = $this->applyPreferenceBias($formats, (array) ($feedbackSummary['preferred_formats'] ?? []), 'post');
        $feedbackGuidance = $this->buildFeedbackGuidance($feedbackSummary);
        $creativeDirection = (array) ($strategy['creative_direction'] ?? []);
        $trendIntelligence = (array) ($strategy['trend_intelligence'] ?? []);

        $rubrics = $this->normalizeRubrics((array) ($strategy['rubrics'] ?? []), (array) ($strategy['pillars'] ?? []));
        $rubrics = $this->rebalanceRubrics($rubrics, $history);
        $mixCounts = $this->allocateMix($totalPosts, $rubrics);

        $pillars = array_values(array_filter(array_map('strval', (array) ($strategy['pillars'] ?? []))));
        if (empty($pillars)) {
            $pillars = ['Educativo'];
        }

        $ctaPool = array_values(array_filter(array_map('strval', (array) data_get($strategy, 'cta_rules.primary_pool', []))));
        if (empty($ctaPool)) {
            $ctaPool = [
                'Commenta la tua esperienza.',
                'Salva il post per consultarlo dopo.',
                'Scrivici in DM per approfondire.',
            ];
        }
        $assetVariables = $this->normalizeAssetVariables((array) data_get($strategy, 'brand_references.asset_variables', []));

        $trendBrief = $this->trendOpportunitySynthesis->buildPlanningBrief($tenantId, [
            'trend_intelligence' => $trendIntelligence,
        ], [
            'platforms' => $platforms,
            'formats' => $formats,
            'strategy' => $strategy,
        ]);
        $trendItems = $this->filterTrendItemsForPlanning(array_values((array) ($trendBrief['items'] ?? [])));
        $maxTrend = min((int) config('trends.max_posts_per_plan', config('editorial.trend.max_posts_per_plan', 2)), count($trendItems));
        $trendSlots = $this->buildTrendSlots($totalPosts, $maxTrend);

        $dates = $this->spreadDates($start, $end, $totalPosts);
        $seriesPlan = $this->buildSeriesPlan($totalPosts, $rubrics, $pillars, $start);

        $results = [];
        $recentPillars = array_values((array) ($history['last_pillars'] ?? []));
        $recentCta = [];
        $recentRubric = [];
        $formatIdx = 0;
        $platformIdx = 0;
        $trendIdx = 0;

        for ($i = 0; $i < $totalPosts; $i++) {
            $inSeries = isset($seriesPlan[$i]);
            $series = $seriesPlan[$i] ?? null;
            $isTrend = in_array($i, $trendSlots, true) && isset($trendItems[$trendIdx]);

            $rubric = $isTrend
                ? 'Trend'
                : ($series['rubric'] ?? $this->pickRubric($mixCounts, $recentRubric));

            if (($mixCounts[$rubric] ?? 0) > 0 && !$isTrend) {
                $mixCounts[$rubric]--;
            }

            $pillarCandidates = $this->pillarsForRubric($rubric, $rubrics, $pillars);
            if ($series && !empty($series['pillar'])) {
                $pillarCandidates = array_values(array_unique(array_merge([$series['pillar']], $pillarCandidates)));
            }
            $pillar = $this->pickPillar($pillarCandidates, $recentPillars);

            $cta = $this->pickCta($ctaPool, $recentCta);
            $format = $formats[$formatIdx % count($formats)];
            $platform = $platforms[$platformIdx % count($platforms)];
            $formatIdx++;
            if (($i % 2) === 1) {
                $platformIdx++;
            }

            $episode = $series['episode'] ?? null;
            $seriesKey = $series['series_key'] ?? null;
            $selectedTrend = $isTrend ? $trendItems[$trendIdx] : null;
            $contentAngle = $this->buildAngle($rubric, $pillar, $i, $episode, $selectedTrend);
            $variableRefs = $this->pickAssetVariablesForItem($assetVariables, $i, $rubric, $pillar);
            $creativeBlueprint = $this->buildCreativeBlueprint(
                rubric: $rubric,
                pillar: $pillar,
                format: $format,
                creativeDirection: $creativeDirection,
                variableRefs: $variableRefs,
                trend: $selectedTrend
            );
            $editorialMode = $this->resolveEditorialMode(
                rubric: $rubric,
                isTrend: $isTrend,
                trend: $selectedTrend
            );
            $trendMetadata = $this->buildTrendMetadata(
                editorialMode: $editorialMode,
                rubric: $rubric,
                pillar: $pillar,
                platform: $platform,
                format: $format,
                trend: $selectedTrend,
                creativeBlueprint: $creativeBlueprint
            );
            if (!empty($variableRefs)) {
                $contentAngle = $this->augmentAngleWithAssetVariables($contentAngle, $variableRefs);
            }

            $titleHint = $this->buildTitleHint($rubric, $pillar, $episode, $selectedTrend);
            if (!empty($variableRefs)) {
                $titleHint = $this->augmentTitleWithAssetVariables($titleHint, $variableRefs);
            }
            $sourceRefs = [];
            if ($isTrend && is_array($selectedTrend)) {
                $sourceRefs[] = [
                    'type' => 'trend',
                    'title' => (string) ($selectedTrend['title'] ?? ''),
                    'topic' => (string) ($selectedTrend['topic'] ?? ''),
                    'platform' => (string) ($selectedTrend['platform'] ?? ''),
                    'format_type' => (string) ($selectedTrend['format_type'] ?? ''),
                    'link' => (string) ($selectedTrend['link'] ?? ''),
                    'source' => (string) ($selectedTrend['source'] ?? ''),
                    'risk_flags' => (array) ($selectedTrend['risk_flags'] ?? []),
                    'trend_opportunity_id' => $trendMetadata['trend_opportunity_id'],
                    'trend_usage_mode' => (string) $trendMetadata['trend_usage_mode'],
                    'trend_confidence' => $trendMetadata['trend_confidence'],
                    'trend_opportunity' => (array) ($selectedTrend['trend_opportunity'] ?? []),
                ];
                $trendIdx++;
            }
            foreach ($variableRefs as $varRef) {
                $sourceRefs[] = [
                    'type' => 'asset_variable',
                    'variable_id' => (int) ($varRef['id'] ?? 0),
                    'name' => (string) ($varRef['name'] ?? ''),
                    'slug' => (string) ($varRef['slug'] ?? ''),
                    'kind' => (string) ($varRef['kind'] ?? 'custom'),
                    'asset_paths' => (array) ($varRef['asset_paths'] ?? []),
                ];
            }

            $results[] = [
                'platform' => $platform,
                'format' => $format,
                'scheduled_at' => $dates[$i]->toDateTimeString(),
                'rubric' => $rubric,
                'editorial_mode' => $editorialMode,
                'series_key' => $seriesKey,
                'episode_number' => $episode,
                'pillar' => $pillar,
                'content_angle' => $contentAngle,
                'primary_cta' => $cta,
                'title_hint' => $titleHint,
                'source_refs' => $sourceRefs,
                'asset_variable_refs' => $variableRefs,
                'objective' => $this->objectiveForRubric($rubric),
                'key_points' => [
                    "Apri con un dettaglio reale o un momento concreto legato a {$pillar}.",
                    "Fai percepire valore, esperienza o beneficio per chi guarda senza tono da consulenza.",
                    'Chiudi con un invito naturale e credibile, coerente con il social e con il brand.',
                ],
                'image_direction' => $this->buildImageDirection($rubric, $pillar, $variableRefs, $creativeBlueprint),
                'feedback_guidance' => $feedbackGuidance,
                'keywords' => $this->keywordsForFingerprint($rubric, $pillar, $contentAngle),
                'professional_brief' => (string) ($creativeBlueprint['professional_brief'] ?? ''),
                'viral_hook_style' => (string) ($creativeBlueprint['viral_hook_style'] ?? ''),
                'shareability_driver' => (string) ($creativeBlueprint['shareability_driver'] ?? ''),
                'hook_style' => (string) $trendMetadata['hook_style'],
                'viral_angle' => (string) $trendMetadata['viral_angle'],
                'trend_bridge' => (string) ($creativeBlueprint['trend_bridge'] ?? ''),
                'trend_usage_mode' => (string) $trendMetadata['trend_usage_mode'],
                'trend_confidence' => $trendMetadata['trend_confidence'],
                'trend_opportunity_id' => $trendMetadata['trend_opportunity_id'],
                'platform_native_format_suggestion' => (string) $trendMetadata['platform_native_format_suggestion'],
                'professionality_guardrails' => (array) $trendMetadata['professionality_guardrails'],
                'trend_basis' => (array) $trendMetadata['trend_basis'],
                'reason_why_now' => (string) $trendMetadata['reason_why_now'],
                'reason_why_brand_fit' => (string) $trendMetadata['reason_why_brand_fit'],
                'expected_engagement_goal' => (string) $trendMetadata['expected_engagement_goal'],
                'trend_guardrails' => (array) ($creativeBlueprint['trend_guardrails'] ?? []),
                'trend_hook_patterns' => (array) ($creativeBlueprint['trend_hook_patterns'] ?? []),
                'trend_platform_hint' => (string) ($creativeBlueprint['trend_platform_hint'] ?? ''),
                'trend_scores' => (array) ($creativeBlueprint['trend_scores'] ?? []),
                'trend_opportunity' => (array) ($selectedTrend['trend_opportunity'] ?? []),
                'overlay_brief' => (string) ($creativeBlueprint['overlay_brief'] ?? ''),
                'continuity_brief' => (string) ($creativeBlueprint['continuity_brief'] ?? ''),
            ];

            $recentPillars = $this->pushAndTrim($recentPillars, $pillar, 3);
            $recentCta = $this->pushAndTrim($recentCta, $cta, 2);
            $recentRubric = $this->pushAndTrim($recentRubric, $rubric, 2);
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $pool
     * @param  array<int, string>  $preferred
     * @return array<int, string>
     */
    private function applyPreferenceBias(array $pool, array $preferred, string $fallback): array
    {
        $base = array_values(array_filter(array_map(
            fn ($item) => trim(Str::lower((string) $item)),
            $pool
        )));

        if (empty($base)) {
            $base = [$fallback];
        }

        $preferredMap = [];
        foreach ($preferred as $item) {
            $normalized = trim(Str::lower((string) $item));
            if ($normalized !== '') {
                $preferredMap[$normalized] = true;
            }
        }

        if (empty($preferredMap)) {
            return $base;
        }

        $preferredItems = [];
        $otherItems = [];
        foreach ($base as $item) {
            if (isset($preferredMap[$item])) {
                $preferredItems[] = $item;
                continue;
            }

            $otherItems[] = $item;
        }

        $base = array_merge($preferredItems, $otherItems);

        $extra = [];
        foreach (array_values(array_unique($base)) as $item) {
            if (isset($preferredMap[$item])) {
                $extra[] = $item;
            }

            if (count($extra) >= 1) {
                break;
            }
        }

        return array_values(array_merge($base, $extra));
    }

    /**
     * @param  array<string, mixed>  $feedbackSummary
     * @return array<string, array<int, string>>
     */
    private function buildFeedbackGuidance(array $feedbackSummary): array
    {
        return [
            'positive_signals' => array_values(array_slice(array_filter(array_map(
                fn ($item) => trim((string) $item),
                (array) ($feedbackSummary['positive_signals'] ?? [])
            )), 0, 4)),
            'hard_avoid_rules' => array_values(array_slice(array_filter(array_map(
                fn ($item) => trim((string) $item),
                (array) ($feedbackSummary['hard_avoid_rules'] ?? [])
            )), 0, 4)),
        ];
    }

    private function normalizeRubrics(array $rubrics, array $pillars): array
    {
        $out = [];
        foreach ($rubrics as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'weight' => max(0.01, (float) ($row['weight'] ?? 0.1)),
                'pillars' => array_values(array_filter(array_map('strval', (array) ($row['pillars'] ?? $pillars)))),
            ];
        }

        if (empty($out)) {
            $base = array_values(array_filter(array_map('strval', $pillars)));
            $out = [
                ['name' => 'Educativo', 'weight' => 0.4, 'pillars' => $base],
                ['name' => 'Prova Sociale', 'weight' => 0.2, 'pillars' => $base],
                ['name' => 'Storia Brand', 'weight' => 0.2, 'pillars' => $base],
                ['name' => 'Offerta', 'weight' => 0.2, 'pillars' => $base],
            ];
        }

        return $out;
    }

    private function rebalanceRubrics(array $rubrics, array $history): array
    {
        $promoRatio = (float) ($history['promo_recent_ratio'] ?? 0.0);
        if ($promoRatio <= 0.45) {
            return $rubrics;
        }

        foreach ($rubrics as &$rubric) {
            $name = Str::lower((string) $rubric['name']);
            if (in_array($name, ['offerta', 'offer', 'promo', 'promotional'], true)) {
                $rubric['weight'] = max(0.08, (float) $rubric['weight'] - 0.10);
            }
            if (in_array($name, ['educativo', 'educational', 'community', 'comunita'], true)) {
                $rubric['weight'] = (float) $rubric['weight'] + 0.05;
            }
        }
        unset($rubric);

        return $rubrics;
    }

    private function allocateMix(int $totalPosts, array $rubrics): array
    {
        $sum = array_sum(array_map(fn ($r) => (float) $r['weight'], $rubrics));
        $sum = $sum > 0 ? $sum : 1.0;

        $counts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($rubrics as $rubric) {
            $name = (string) $rubric['name'];
            $raw = ($rubric['weight'] / $sum) * $totalPosts;
            $count = (int) floor($raw);
            $counts[$name] = $count;
            $remainders[$name] = $raw - $count;
            $allocated += $count;
        }

        while ($allocated < $totalPosts) {
            arsort($remainders);
            $name = (string) array_key_first($remainders);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $remainders[$name] = 0.0;
            $allocated++;
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trendItems
     * @return array<int, array<string, mixed>>
     */
    private function filterTrendItemsForPlanning(array $trendItems): array
    {
        $blockedFlags = array_map(
            fn ($value) => Str::lower(trim((string) $value)),
            (array) config('trends.risk_block_flags', [])
        );
        $minBrandFit = (float) config('trends.min_brand_fit_score', 0.55);
        $minExecution = 0.52;

        $safeItems = array_values(array_filter($trendItems, function ($item) use ($blockedFlags): bool {
            if (!is_array($item)) {
                return false;
            }

            $topic = trim((string) ($item['topic'] ?? $item['title'] ?? ''));
            if ($topic === '') {
                return false;
            }

            $riskFlags = array_map(
                fn ($value) => Str::lower(trim((string) $value)),
                (array) ($item['risk_flags'] ?? [])
            );

            return empty(array_intersect($riskFlags, $blockedFlags));
        }));

        $preferredItems = array_values(array_filter($safeItems, function (array $item) use ($minBrandFit, $minExecution): bool {
            $brandFit = data_get($item, 'brand_fit_score');
            $execution = data_get($item, 'execution_feasibility_score');

            return (!is_numeric($brandFit) || (float) $brandFit >= $minBrandFit)
                && (!is_numeric($execution) || (float) $execution >= $minExecution);
        }));

        return !empty($preferredItems) ? $preferredItems : $safeItems;
    }

    private function buildTrendSlots(int $totalPosts, int $maxTrend): array
    {
        if ($maxTrend <= 0 || $totalPosts < 6) {
            return [];
        }

        $slots = [];
        for ($i = 0; $i < $maxTrend; $i++) {
            $slots[] = min($totalPosts - 1, (int) floor((($i + 1) * $totalPosts) / ($maxTrend + 1)));
        }

        return array_values(array_unique($slots));
    }

    private function buildSeriesPlan(int $totalPosts, array $rubrics, array $pillars, Carbon $start): array
    {
        if ($totalPosts < 6) {
            return [];
        }

        $seriesRubric = 'Educativo';
        $rubricNames = array_map(fn ($r) => (string) $r['name'], $rubrics);
        if (!in_array($seriesRubric, $rubricNames, true)) {
            $seriesRubric = $rubricNames[0] ?? 'Educativo';
        }

        $seriesPillar = $pillars[0] ?? 'Educativo';
        $seriesKey = 'series-' . Str::slug($seriesRubric . '-' . $seriesPillar . '-' . $start->toDateString());
        $gap = max(2, (int) floor($totalPosts / 3));

        $slots = [
            0 => 1,
            min($totalPosts - 1, $gap) => 2,
            min($totalPosts - 1, $gap * 2) => 3,
        ];

        $out = [];
        foreach ($slots as $idx => $episode) {
            $out[$idx] = [
                'rubric' => $seriesRubric,
                'pillar' => $seriesPillar,
                'series_key' => $seriesKey,
                'episode' => $episode,
            ];
        }

        return $out;
    }

    private function resolveEditorialMode(string $rubric, bool $isTrend, ?array $trend): string
    {
        if ($isTrend && is_array($trend)) {
            $freshness = (float) ($trend['freshness_score'] ?? 0.0);
            $viral = (float) ($trend['viral_potential_score'] ?? 0.0);

            return ($freshness >= 0.82 && $viral >= 0.72) ? 'reactive' : 'trend-aware';
        }

        $rubric = Str::lower($rubric);

        if (str_contains($rubric, 'offerta') || str_contains($rubric, 'offer')) {
            return 'conversion-oriented';
        }

        if (
            str_contains($rubric, 'educativo')
            || str_contains($rubric, 'educational')
            || str_contains($rubric, 'prova sociale')
            || str_contains($rubric, 'social proof')
        ) {
            return 'authority-building';
        }

        return 'evergreen';
    }

    private function spreadDates(Carbon $start, Carbon $end, int $totalPosts): array
    {
        $days = max(1, $start->diffInDays($end->copy()->startOfDay()) + 1);
        $step = max(1, (int) floor($days / $totalPosts));
        $dates = [];
        for ($i = 0; $i < $totalPosts; $i++) {
            $d = $start->copy()->addDays(min($days - 1, $i * $step));
            $hour = ($i % 3 === 0) ? 11 : (($i % 3 === 1) ? 15 : 19);
            $dates[] = $d->setTime($hour, 0);
        }
        return $dates;
    }

    private function pickRubric(array $mixCounts, array $recentRubric): string
    {
        $last = $recentRubric[0] ?? null;
        arsort($mixCounts);

        foreach ($mixCounts as $name => $count) {
            if ($count <= 0) {
                continue;
            }
            if ($last !== null && Str::lower($last) === Str::lower((string) $name)) {
                continue;
            }
            return (string) $name;
        }

        foreach ($mixCounts as $name => $count) {
            if ($count > 0) {
                return (string) $name;
            }
        }

        return 'Educativo';
    }

    private function pillarsForRubric(string $rubric, array $rubrics, array $fallback): array
    {
        foreach ($rubrics as $row) {
            if (Str::lower((string) $row['name']) !== Str::lower($rubric)) {
                continue;
            }
            $items = array_values(array_filter(array_map('strval', (array) ($row['pillars'] ?? []))));
            return empty($items) ? $fallback : $items;
        }
        return $fallback;
    }

    private function pickPillar(array $candidates, array $recentPillars): string
    {
        $recentA = $recentPillars[0] ?? null;
        $recentB = $recentPillars[1] ?? null;
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($recentA && $recentB && Str::lower($candidate) === Str::lower($recentA) && Str::lower($candidate) === Str::lower($recentB)) {
                continue;
            }
            return $candidate;
        }

        return $candidates[0] ?? 'Educativo';
    }

    private function pickCta(array $pool, array $recent): string
    {
        $last = $recent[0] ?? null;
        foreach ($pool as $cta) {
            if ($last !== null && Str::lower($cta) === Str::lower((string) $last)) {
                continue;
            }
            return $cta;
        }
        return $pool[0] ?? 'Scrivici in DM per approfondire.';
    }

    private function buildAngle(string $rubric, string $pillar, int $index, ?int $episode, ?array $trend): string
    {
        if ($trend !== null) {
            $recommended = trim((string) ($trend['recommended_angle'] ?? ''));
            if ($recommended !== '') {
                return $recommended;
            }
            $title = trim((string) ($trend['title'] ?? $trend['topic'] ?? 'Trend di settore'));
            return "Insight trend: {$title} applicato a {$pillar}";
        }

        if ($episode !== null) {
            return "Serie {$rubric} Ep. {$episode}: applicazione pratica su {$pillar}";
        }

        $variants = [
            "Dettaglio che rende speciale {$pillar}",
            "Cosa nota davvero chi vive {$pillar}",
            "Scena reale o esperienza che racconta {$pillar}",
            "Angolo concreto per valorizzare {$pillar} sui social",
        ];

        $lowerRubric = Str::lower($rubric);
        if (str_contains($lowerRubric, 'storia')) {
            $variants = [
                "Atmosfera e identita di {$pillar}",
                "Dettaglio reale che racconta {$pillar}",
                "Momento autentico legato a {$pillar}",
                "Dietro le quinte di {$pillar}",
            ];
        } elseif (str_contains($lowerRubric, 'prova sociale') || str_contains($lowerRubric, 'social proof')) {
            $variants = [
                "Momento vissuto che fa percepire {$pillar}",
                "Scena reale che fa capire il valore di {$pillar}",
                "Esperienza concreta legata a {$pillar}",
                "Perche {$pillar} viene ricordato da chi passa di qui",
            ];
        } elseif (str_contains($lowerRubric, 'offerta') || str_contains($lowerRubric, 'offer')) {
            $variants = [
                "Invito concreto a scoprire {$pillar}",
                "Perche questo e il momento giusto per vivere {$pillar}",
                "Proposta chiara legata a {$pillar}",
                "Angolo social per presentare {$pillar} senza tono promozionale aggressivo",
            ];
        }

        return $variants[$index % count($variants)];
    }

    private function buildTitleHint(string $rubric, string $pillar, ?int $episode, ?array $trend): string
    {
        if ($trend !== null) {
            return Str::limit('Trend: ' . (string) ($trend['title'] ?? $trend['topic'] ?? $pillar), 110, '');
        }
        if ($episode !== null) {
            return Str::limit("{$rubric} - Ep. {$episode} - {$pillar}", 110, '');
        }
        return Str::limit("{$rubric}: {$pillar}", 110, '');
    }

    private function objectiveForRubric(string $rubric): string
    {
        return match (Str::lower($rubric)) {
            'offerta', 'offer' => 'Lead',
            'prova sociale', 'social proof' => 'Fiducia',
            'trend' => 'Coinvolgimento',
            default => 'Awareness',
        };
    }

    private function keywordsForFingerprint(string $rubric, string $pillar, string $angle): string
    {
        return implode(' ', [$rubric, $pillar, $angle]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $vars
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetVariables(array $vars): array
    {
        $out = [];
        foreach ($vars as $var) {
            if (!is_array($var)) {
                continue;
            }

            $name = trim((string) ($var['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $out[] = [
                'id' => isset($var['id']) ? (int) $var['id'] : null,
                'name' => $name,
                'slug' => (string) ($var['slug'] ?? Str::slug($name)),
                'kind' => (string) ($var['kind'] ?? 'custom'),
                'description' => (string) ($var['description'] ?? ''),
                'asset_paths' => array_values(array_filter(array_map(
                    'strval',
                    (array) ($var['asset_paths'] ?? [])
                ))),
                'asset_ids' => array_values(array_filter(array_map(
                    fn ($id) => (int) $id,
                    (array) ($var['asset_ids'] ?? [])
                ), fn ($id) => $id > 0)),
            ];
        }

        return array_values($out);
    }

    /**
     * @param  array<int, array<string, mixed>>  $assetVariables
     * @return array<int, array<string, mixed>>
     */
    private function pickAssetVariablesForItem(array $assetVariables, int $index, string $rubric, string $pillar): array
    {
        if (empty($assetVariables)) {
            return [];
        }

        $pool = collect($assetVariables)->values();
        if ($pool->isEmpty()) {
            return [];
        }

        $lowerRubric = Str::lower($rubric);
        $lowerPillar = Str::lower($pillar);

        $matched = $pool->filter(function (array $var) use ($lowerRubric, $lowerPillar) {
            $name = Str::lower((string) ($var['name'] ?? ''));
            $kind = Str::lower((string) ($var['kind'] ?? ''));
            $desc = Str::lower((string) ($var['description'] ?? ''));

            if ($name !== '' && (str_contains($lowerRubric, $name) || str_contains($lowerPillar, $name))) {
                return true;
            }
            if ($desc !== '' && (str_contains($lowerRubric, $desc) || str_contains($lowerPillar, $desc))) {
                return true;
            }
            if ($kind === 'person' && (str_contains($lowerRubric, 'story') || str_contains($lowerRubric, 'community'))) {
                return true;
            }
            if ($kind === 'product' && str_contains($lowerRubric, 'offerta')) {
                return true;
            }
            if ($kind === 'location' && str_contains($lowerRubric, 'brand')) {
                return true;
            }

            return false;
        })->values();

        $list = $matched->isNotEmpty() ? $matched : $pool;
        $selected = $list->get($index % max(1, $list->count()));
        if (!is_array($selected)) {
            return [];
        }

        return [$selected];
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     */
    private function augmentAngleWithAssetVariables(string $angle, array $refs): string
    {
        $name = trim((string) data_get($refs, '0.name', ''));
        $kind = Str::lower(trim((string) data_get($refs, '0.kind', 'custom')));
        if ($name === '') {
            return $angle;
        }

        $suffix = match ($kind) {
            'person' => "con {$name} come soggetto principale",
            'location' => "ambientato in {$name}",
            'product' => "con focus prodotto {$name}",
            default => "con riferimento visuale {$name}",
        };

        return Str::limit($angle . ' - ' . $suffix, 180, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     */
    private function augmentTitleWithAssetVariables(string $title, array $refs): string
    {
        $name = trim((string) data_get($refs, '0.name', ''));
        if ($name === '') {
            return $title;
        }
        return Str::limit($title . ' - ' . $name, 110, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @param  array<string, mixed>  $creativeBlueprint
     */
    private function buildImageDirection(string $rubric, string $pillar, array $refs, array $creativeBlueprint = []): string
    {
        $base = "Visual {$rubric} coerente con {$pillar}, composizione pulita e realistica.";
        $parts = [$base];

        if (!empty($refs)) {
            $labels = [];
            foreach ($refs as $ref) {
                if (!is_array($ref)) {
                    continue;
                }
                $name = trim((string) ($ref['name'] ?? ''));
                $kind = trim((string) ($ref['kind'] ?? 'custom'));
                if ($name === '') {
                    continue;
                }
                $labels[] = $name . ' [' . $kind . ']';
            }

            if (!empty($labels)) {
                $parts[] = 'Usa in modo esplicito le variabili: ' . implode(', ', array_slice($labels, 0, 4)) . '.';
            }
        }

        $overlayBrief = trim((string) ($creativeBlueprint['overlay_brief'] ?? ''));
        if ($overlayBrief !== '') {
            $parts[] = 'Predisponi una composizione overlay-ready con safe area tipografica, senza far renderizzare testo lungo: ' . $overlayBrief . '.';
        }

        $continuityBrief = trim((string) ($creativeBlueprint['continuity_brief'] ?? ''));
        if ($continuityBrief !== '') {
            $parts[] = 'Continuita visuale: ' . $continuityBrief . '.';
        }

        $trendBridge = trim((string) ($creativeBlueprint['trend_bridge'] ?? ''));
        if ($trendBridge !== '') {
            $parts[] = 'Usa il trend solo come struttura adattata al brand: ' . $trendBridge . '.';
        }

        $trendStyleNotes = array_values(array_filter(array_map('strval', (array) ($creativeBlueprint['trend_style_notes'] ?? []))));
        if (!empty($trendStyleNotes)) {
            $parts[] = 'Segnali stilistici trend-safe: ' . implode('; ', array_slice($trendStyleNotes, 0, 2)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $creativeDirection
     * @param  array<int, array<string, mixed>>  $variableRefs
     * @param  array<string, mixed>|null  $trend
     * @return array<string, mixed>
     */
    private function buildCreativeBlueprint(
        string $rubric,
        string $pillar,
        string $format,
        array $creativeDirection,
        array $variableRefs = [],
        ?array $trend = null
    ): array {
        $format = Str::lower(trim($format));
        $rubricLower = Str::lower($rubric);
        $professionalBrief = trim((string) data_get($creativeDirection, 'professional_direction.quality_bar', ''));
        if ($professionalBrief === '') {
            $professionalBrief = 'Il contenuto deve sembrare credibile, concreto e costruito con occhio editoriale senior.';
        }

        $hookStyle = $format === 'reel'
            ? 'Hook visivo immediato con promessa concreta entro il primo secondo.'
            : 'Hook editoriale breve, leggibile da miniatura e subito riconoscibile.';
        if ($trend !== null) {
            $hookStyle = trim((string) ($trend['brand_safe_hook'] ?? 'Hook reattivo e nativo ai social, ma tradotto sul brand senza sembrare meme copiato.'));
        }

        $shareabilityDriver = str_contains($rubricLower, 'educativo')
            ? 'Costruisci un takeaway salvabile o condivisibile.'
            : 'Crea un dettaglio memorabile che invogli commenti o condivisioni credibili.';
        if (str_contains($rubricLower, 'prova sociale')) {
            $shareabilityDriver = 'Rendi il valore sociale riconoscibile e facile da raccontare o inoltrare.';
        }

        $trendBridge = '';
        $trendHookPatterns = [];
        $trendStyleNotes = [];
        $trendScores = [];
        if ($trend !== null) {
            $trendTitle = trim((string) ($trend['title'] ?? $trend['topic'] ?? 'trend di settore'));
            $trendBridge = trim((string) ($trend['recommended_angle'] ?? "Parti dal trend '{$trendTitle}' ma riformulalo come insight o scena coerente con {$pillar}"));
            $trendHookPatterns = array_values(array_filter(array_map('strval', (array) ($trend['hook_patterns'] ?? []))));
            $trendStyleNotes = array_values(array_filter(array_map('strval', (array) ($trend['style_notes'] ?? []))));
            $trendScores = array_filter([
                'freshness_score' => $trend['freshness_score'] ?? null,
                'estimated_relevance_score' => $trend['estimated_relevance_score'] ?? null,
                'brand_fit_score' => $trend['brand_fit_score'] ?? null,
                'novelty_score' => $trend['novelty_score'] ?? null,
                'execution_feasibility_score' => $trend['execution_feasibility_score'] ?? null,
                'viral_potential_score' => $trend['viral_potential_score'] ?? null,
            ], fn ($value) => $value !== null);
        }

        $trendGuardrails = array_values(array_slice(array_filter(array_merge(
            array_map(
                'strval',
                (array) data_get($creativeDirection, 'trend_policy.disallowed_mechanics', [])
            ),
            $this->mapTrendRiskFlagsToGuardrails((array) ($trend['risk_flags'] ?? []))
        )), 0, 6));

        $maxWords = max(3, (int) data_get($creativeDirection, 'typography_system.max_words', 6));
        $safeArea = trim((string) data_get($creativeDirection, 'typography_system.safe_area', 'upper_third'));
        $overlayBrief = "safe area {$safeArea}, headline breve max {$maxWords} parole, forte contrasto, look premium mobile-first";
        if ($format === 'reel' || $format === 'story') {
            $overlayBrief .= ', compatibile con hook tipografico iniziale ma leggibile anche senza testo';
        }

        $variableNames = collect($variableRefs)
            ->map(fn ($ref) => trim((string) ($ref['name'] ?? '')))
            ->filter(fn (string $name) => $name !== '')
            ->take(2)
            ->values()
            ->all();
        $continuityBrief = trim((string) data_get($creativeDirection, 'continuity_rules.reference_policy', ''));
        if (!empty($variableNames)) {
            $continuityBrief .= ($continuityBrief !== '' ? ' ' : '')
                . 'Ancore principali: ' . implode(', ', $variableNames) . '.';
        }

        return [
            'professional_brief' => $professionalBrief,
            'viral_hook_style' => $hookStyle,
            'shareability_driver' => $shareabilityDriver,
            'trend_bridge' => $trendBridge,
            'trend_guardrails' => $trendGuardrails,
            'trend_hook_patterns' => $trendHookPatterns,
            'trend_style_notes' => $trendStyleNotes,
            'trend_platform_hint' => $trend !== null ? (string) ($trend['platform'] ?? '') : '',
            'trend_scores' => $trendScores,
            'overlay_brief' => $overlayBrief,
            'continuity_brief' => trim($continuityBrief),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $trend
     * @param  array<string, mixed>  $creativeBlueprint
     * @return array<string, mixed>
     */
    private function buildTrendMetadata(
        string $editorialMode,
        string $rubric,
        string $pillar,
        string $platform,
        string $format,
        ?array $trend,
        array $creativeBlueprint
    ): array {
        $trendOpportunity = is_array($trend['trend_opportunity'] ?? null) ? (array) $trend['trend_opportunity'] : [];
        $trendScores = array_filter([
            'freshness_score' => $trendOpportunity['freshness_score'] ?? ($trend['freshness_score'] ?? null),
            'estimated_relevance_score' => $trendOpportunity['estimated_relevance_score'] ?? ($trend['estimated_relevance_score'] ?? null),
            'brand_fit_score' => $trendOpportunity['brand_fit_score'] ?? ($trend['brand_fit_score'] ?? null),
            'novelty_score' => $trendOpportunity['novelty_score'] ?? ($trend['novelty_score'] ?? null),
            'execution_feasibility_score' => $trendOpportunity['execution_feasibility_score'] ?? ($trend['execution_feasibility_score'] ?? null),
            'viral_potential_score' => $trendOpportunity['viral_potential_score'] ?? ($trend['viral_potential_score'] ?? null),
        ], fn ($value) => $value !== null);

        $trendConfidence = empty($trendOpportunity) && !in_array($editorialMode, ['trend-aware', 'reactive'], true)
            ? null
            : $this->resolveTrendConfidence($trendScores);
        $trendUsageMode = $this->resolveTrendUsageMode($editorialMode, $trendOpportunity);
        $expectedEngagementGoal = $this->resolveExpectedEngagementGoal($editorialMode);
        $reasonWhyNow = $this->buildReasonWhyNow($editorialMode, $rubric, $pillar, $trend, $trendScores);
        $reasonWhyBrandFit = $this->buildReasonWhyBrandFit($editorialMode, $pillar, $trend);
        $hookStyle = trim((string) ($creativeBlueprint['viral_hook_style'] ?? ''));
        $shareabilityDriver = trim((string) ($creativeBlueprint['shareability_driver'] ?? ''));
        $hookPatterns = array_values(array_filter(array_map('strval', (array) ($trend['hook_patterns'] ?? []))));
        $viralAngle = $hookPatterns[0] ?? $shareabilityDriver;
        if ($viralAngle === '') {
            $viralAngle = $hookStyle;
        }

        $professionalityGuardrails = array_values(array_unique(array_filter(array_merge(
            (array) ($creativeBlueprint['trend_guardrails'] ?? []),
            [
                'Non degradare il livello strategico: brand fit e chiarezza vengono prima del trend.',
                'La viralita e una probabilita aumentata, non una promessa di performance.',
                'Niente clickbait stupido, bait aggressivo o meccaniche che facciano sembrare il brand improvvisato.',
            ]
        ))));

        $platformNativeFormatSuggestion = is_array($trend)
            ? trim(implode(' ', array_filter([
                (string) ($trend['platform'] ?? $platform),
                (string) ($trend['format_type'] ?? $format),
            ])))
            : trim($platform . ' ' . $format);

        $trendBasis = [
            'source' => !empty($trendOpportunity) ? 'trend_signal' : 'editorial_strategy',
            'editorial_mode' => $editorialMode,
            'trend_opportunity_id' => isset($trendOpportunity['signal_id']) ? (int) $trendOpportunity['signal_id'] : null,
            'snapshot_id' => isset($trendOpportunity['snapshot_id']) ? (int) $trendOpportunity['snapshot_id'] : null,
            'usage_mode' => $trendUsageMode,
            'confidence' => $trendConfidence,
            'topic' => (string) ($trendOpportunity['topic'] ?? data_get($trend, 'topic', '')),
            'platform' => (string) ($trendOpportunity['platform'] ?? data_get($trend, 'platform', $platform)),
            'format_type' => (string) ($trendOpportunity['format_type'] ?? data_get($trend, 'format_type', $format)),
            'reason_why_now' => $reasonWhyNow,
            'reason_why_brand_fit' => $reasonWhyBrandFit,
            'expected_engagement_goal' => $expectedEngagementGoal,
            'scores' => $trendScores,
        ];

        return [
            'trend_opportunity_id' => isset($trendOpportunity['signal_id']) ? (int) $trendOpportunity['signal_id'] : null,
            'trend_usage_mode' => $trendUsageMode,
            'trend_confidence' => $trendConfidence,
            'professionality_guardrails' => $professionalityGuardrails,
            'viral_angle' => $viralAngle,
            'platform_native_format_suggestion' => $platformNativeFormatSuggestion,
            'trend_basis' => $trendBasis,
            'hook_style' => $hookStyle,
            'reason_why_now' => $reasonWhyNow,
            'reason_why_brand_fit' => $reasonWhyBrandFit,
            'expected_engagement_goal' => $expectedEngagementGoal,
        ];
    }

    /**
     * @param  array<string, mixed>  $trendOpportunity
     */
    private function resolveTrendUsageMode(string $editorialMode, array $trendOpportunity): string
    {
        if (empty($trendOpportunity)) {
            return 'none';
        }

        if ($editorialMode === 'reactive') {
            return 'reactive_commentary';
        }

        $brandFit = (float) ($trendOpportunity['brand_fit_score'] ?? 0.0);
        $execution = (float) ($trendOpportunity['execution_feasibility_score'] ?? 0.0);

        if ($brandFit >= 0.72 && $execution >= 0.64) {
            return 'trend_safe_adaptation';
        }

        return 'format_acceleration';
    }

    /**
     * @param  array<string, mixed>  $trendScores
     */
    private function resolveTrendConfidence(array $trendScores): float
    {
        if (empty($trendScores)) {
            return 0.58;
        }

        $brandFit = (float) ($trendScores['brand_fit_score'] ?? 0.0);
        $relevance = (float) ($trendScores['estimated_relevance_score'] ?? 0.0);
        $execution = (float) ($trendScores['execution_feasibility_score'] ?? 0.0);
        $viral = (float) ($trendScores['viral_potential_score'] ?? 0.0);
        $novelty = (float) ($trendScores['novelty_score'] ?? 0.0);

        return round(max(0.0, min(1.0, (
            ($brandFit * 0.34)
            + ($relevance * 0.24)
            + ($execution * 0.18)
            + ($viral * 0.14)
            + ($novelty * 0.10)
        ))), 4);
    }

    /**
     * @param  array<string, mixed>|null  $trend
     * @param  array<string, mixed>  $trendScores
     */
    private function buildReasonWhyNow(
        string $editorialMode,
        string $rubric,
        string $pillar,
        ?array $trend,
        array $trendScores
    ): string {
        if (is_array($trend)) {
            $topic = trim((string) ($trend['topic'] ?? $trend['title'] ?? 'trend di settore'));
            $freshness = data_get($trendScores, 'freshness_score');
            $novelty = data_get($trendScores, 'novelty_score');
            $scoreHints = [];
            if (is_numeric($freshness)) {
                $scoreHints[] = 'freshness ' . number_format((float) $freshness, 2, '.', '');
            }
            if (is_numeric($novelty)) {
                $scoreHints[] = 'novelty ' . number_format((float) $novelty, 2, '.', '');
            }

            $metrics = empty($scoreHints) ? '' : ' (' . implode(', ', $scoreHints) . ')';
            return "Ora ha senso attivare {$topic}{$metrics}: aumenta rilevanza e attenzione senza spostare il piano fuori asse.";
        }

        return match ($editorialMode) {
            'conversion-oriented' => 'Questo slot serve ora per spingere una CTA chiara senza trasformare tutto il piano in promo.',
            'authority-building' => 'Questo slot rafforza autorevolezza e utilita nel mezzo dei contenuti piu veloci o piu reach-oriented.',
            default => "Questo slot resta utile anche fuori dal ciclo dei trend: tiene in piedi continuita, qualita e memoria del brand attorno a {$pillar}.",
        };
    }

    /**
     * @param  array<string, mixed>|null  $trend
     */
    private function buildReasonWhyBrandFit(string $editorialMode, string $pillar, ?array $trend): string
    {
        if (is_array($trend)) {
            $whyItFits = trim((string) ($trend['why_it_fits'] ?? ''));
            if ($whyItFits !== '') {
                return $whyItFits;
            }

            return "Il trend viene tradotto su {$pillar} in modo coerente col brand, evitando imitazione gratuita o tono sbagliato.";
        }

        return match ($editorialMode) {
            'conversion-oriented' => 'Parte da un bisogno reale del tenant e tiene il focus su chiarezza di offerta e next step.',
            'authority-building' => 'Parte dai pillar del brand e costruisce fiducia con prove, insight o valore utile invece che rincorrere il rumore.',
            default => 'Resta dentro identita, tono e obiettivi del tenant anche senza appoggiarsi a un trend esterno.',
        };
    }

    private function resolveExpectedEngagementGoal(string $editorialMode): string
    {
        return match ($editorialMode) {
            'reactive' => 'comments_and_shares',
            'trend-aware' => 'reach_and_saves',
            'authority-building' => 'saves_and_qualified_comments',
            'conversion-oriented' => 'dm_clicks_or_leads',
            default => 'saves_and_brand_recall',
        };
    }

    /**
     * @param  array<int, string>  $riskFlags
     * @return array<int, string>
     */
    private function mapTrendRiskFlagsToGuardrails(array $riskFlags): array
    {
        $mapped = [];
        foreach ($riskFlags as $flag) {
            $normalized = Str::lower(trim((string) $flag));
            if ($normalized === '') {
                continue;
            }

            $mapped[] = match ($normalized) {
                'meme_overload' => 'Evita meme gratuiti o imitazioni troppo evidenti.',
                'trend_fatigue' => 'Evita una copia stanca del trend: cerca un angolo proprietario.',
                'low_brand_fit', 'brand_conflict' => 'Non usare il trend se riduce professionalita o riconoscibilita del brand.',
                'execution_risk' => 'Semplifica il format per restare eseguibile con gli asset reali del tenant.',
                default => 'Mantieni un uso prudente del trend e verifica la coerenza col brand.',
            };
        }

        return array_values(array_unique(array_filter($mapped)));
    }

    private function pushAndTrim(array $arr, string $value, int $max): array
    {
        array_unshift($arr, $value);
        return array_slice($arr, 0, $max);
    }
}