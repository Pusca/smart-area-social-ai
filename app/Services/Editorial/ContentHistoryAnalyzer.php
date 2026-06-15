<?php

namespace App\Services\Editorial;

use App\Models\ContentItem;
use Illuminate\Support\Str;

class ContentHistoryAnalyzer
{
    public function snapshot(int $tenantId, int $limit = 120): array
    {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'rubric',
                'pillar',
                'content_angle',
                'platform',
                'format',
                'scheduled_at',
                'fingerprint',
                'caption',
                'ai_caption',
                'ai_cta',
                'status',
            ]);

        $rubricFreq = [];
        $pillarFreq = [];
        $ctaFreq = [];
        $formatFreq = [];

        $items = [];
        foreach ($rows as $row) {
            $cta = trim((string) ($row->ai_cta ?? ''));
            $rubric = trim((string) ($row->rubric ?? ''));
            $pillar = trim((string) ($row->pillar ?? ''));

            if ($rubric !== '') {
                $rubricFreq[$rubric] = ($rubricFreq[$rubric] ?? 0) + 1;
            }
            if ($pillar !== '') {
                $pillarFreq[$pillar] = ($pillarFreq[$pillar] ?? 0) + 1;
            }
            if ($cta !== '') {
                $ctaNorm = Str::lower(Str::limit($cta, 120, ''));
                $ctaFreq[$ctaNorm] = ($ctaFreq[$ctaNorm] ?? 0) + 1;
            }
            $formatKey = trim((string) ($row->platform . ':' . $row->format));
            $formatFreq[$formatKey] = ($formatFreq[$formatKey] ?? 0) + 1;

            $items[] = [
                'id' => (int) $row->id,
                'rubric' => $rubric,
                'pillar' => $pillar,
                'content_angle' => (string) ($row->content_angle ?? ''),
                'platform' => (string) $row->platform,
                'format' => (string) $row->format,
                'scheduled_at' => optional($row->scheduled_at)?->toDateTimeString(),
                'fingerprint' => (string) ($row->fingerprint ?? ''),
                'cta' => $cta,
                'text' => trim((string) ($row->ai_caption ?: $row->caption ?: '')),
            ];
        }

        arsort($rubricFreq);
        arsort($pillarFreq);
        arsort($ctaFreq);
        arsort($formatFreq);

        $lastPillars = array_values(array_filter(array_map(
            fn ($i) => $i['pillar'],
            array_slice($items, 0, 5)
        )));

        $promoRecent = 0;
        foreach (array_slice($items, 0, 8) as $i) {
            if (in_array(Str::lower($i['rubric']), ['offerta', 'offer', 'promo', 'promotional'], true)) {
                $promoRecent++;
            }
        }

        return [
            'items' => $items,
            'rubric_frequency' => $rubricFreq,
            'pillar_frequency' => $pillarFreq,
            'cta_frequency' => $ctaFreq,
            'format_frequency' => $formatFreq,
            'recent_fingerprints' => array_values(array_filter(array_map(fn ($i) => $i['fingerprint'], $items))),
            'last_pillars' => $lastPillars,
            'promo_recent_ratio' => $promoRecent / max(1, min(8, count($items))),
        ];
    }
}
