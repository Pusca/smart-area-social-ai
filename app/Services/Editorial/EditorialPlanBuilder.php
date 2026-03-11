<?php

namespace App\Services\Editorial;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialPlanBuilder
{
    public function __construct(
        private readonly TrendBriefService $trendBriefService
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

        $trendBrief = $this->trendBriefService->getBriefForTenant($tenantId);
        $trendItems = array_values((array) ($trendBrief['items'] ?? []));
        $maxTrend = min((int) config('editorial.trend.max_posts_per_plan', 2), count($trendItems));
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
            $contentAngle = $this->buildAngle($rubric, $pillar, $i, $episode, $isTrend ? $trendItems[$trendIdx] : null);
            $variableRefs = $this->pickAssetVariablesForItem($assetVariables, $i, $rubric, $pillar);
            if (!empty($variableRefs)) {
                $contentAngle = $this->augmentAngleWithAssetVariables($contentAngle, $variableRefs);
            }

            $titleHint = $this->buildTitleHint($rubric, $pillar, $episode, $isTrend ? $trendItems[$trendIdx] : null);
            if (!empty($variableRefs)) {
                $titleHint = $this->augmentTitleWithAssetVariables($titleHint, $variableRefs);
            }
            $sourceRefs = [];
            if ($isTrend) {
                $sourceRefs[] = [
                    'type' => 'trend',
                    'title' => (string) ($trendItems[$trendIdx]['title'] ?? ''),
                    'link' => (string) ($trendItems[$trendIdx]['link'] ?? ''),
                    'source' => (string) ($trendItems[$trendIdx]['source'] ?? ''),
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
                'image_direction' => $this->buildImageDirection($rubric, $pillar, $variableRefs),
                'feedback_guidance' => $feedbackGuidance,
                'keywords' => $this->keywordsForFingerprint($rubric, $pillar, $contentAngle),
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
            $title = trim((string) ($trend['title'] ?? 'Trend di settore'));
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
            return Str::limit('Trend: ' . (string) ($trend['title'] ?? $pillar), 110, '');
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
        return Str::limit($title . ' · ' . $name, 110, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     */
    private function buildImageDirection(string $rubric, string $pillar, array $refs): string
    {
        $base = "Visual {$rubric} coerente con {$pillar}, composizione pulita e realistica.";
        if (empty($refs)) {
            return $base;
        }

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

        if (empty($labels)) {
            return $base;
        }

        return $base . ' Usa in modo esplicito le variabili: ' . implode(', ', array_slice($labels, 0, 4)) . '.';
    }

    private function pushAndTrim(array $arr, string $value, int $max): array
    {
        array_unshift($arr, $value);
        return array_slice($arr, 0, $max);
    }
}
