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
        $totalPosts = max(1, (int) ($period['total_posts'] ?? 5));

        $platforms = array_values(array_filter((array) ($options['platforms'] ?? ['instagram'])));
        $formats = array_values(array_filter((array) ($options['formats'] ?? ['post'])));

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

            $titleHint = $this->buildTitleHint($rubric, $pillar, $episode, $isTrend ? $trendItems[$trendIdx] : null);
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
                'objective' => $this->objectiveForRubric($rubric),
                'key_points' => [
                    "Contesto: perche {$pillar} impatta risultati reali.",
                    "Azione: step operativo subito applicabile.",
                    "Follow-up: collega questo post al prossimo contenuto utile.",
                ],
                'image_direction' => "Visual {$rubric} coerente con {$pillar}, composizione pulita e realistica.",
                'keywords' => $this->keywordsForFingerprint($rubric, $pillar, $contentAngle),
            ];

            $recentPillars = $this->pushAndTrim($recentPillars, $pillar, 3);
            $recentCta = $this->pushAndTrim($recentCta, $cta, 2);
            $recentRubric = $this->pushAndTrim($recentRubric, $rubric, 2);
        }

        return $results;
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
        $days = max(1, $start->diffInDays($end) + 1);
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
            "Errore comune su {$pillar} e come evitarlo",
            "Checklist operativa: {$pillar} in 3 passi",
            "Caso pratico {$pillar}: prima/dopo misurabile",
            "Framework rapido per {$pillar} con esempio reale",
        ];

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

    private function pushAndTrim(array $arr, string $value, int $max): array
    {
        array_unshift($arr, $value);
        return array_slice($arr, 0, $max);
    }
}
