<?php

namespace App\Services;

use App\Models\AlterEgo;
use App\Models\ContentItem;

/**
 * Aggrega i dati di performance degli alter ego generati nel tempo.
 * I punteggi sono estratti da ai_meta.alter_ego_consistency, salvati post-generazione.
 */
class AlterEgoAnalyticsService
{
    /**
     * Statistiche complete per un singolo alter ego.
     *
     * @return array{
     *   post_count: int,
     *   scored_count: int,
     *   avg_score: int|null,
     *   avg_tone: int|null,
     *   avg_vocab: int|null,
     *   avg_style: int|null,
     *   scores_timeline: list<int>,
     *   recent_items: \Illuminate\Support\Collection
     * }
     */
    public function statsForAlterEgo(AlterEgo $alterEgo, int $limit = 30): array
    {
        $items = ContentItem::query()
            ->where('alter_ego_id', $alterEgo->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'ai_meta', 'ai_caption', 'platform', 'format', 'created_at']);

        $scores      = [];
        $toneScores  = [];
        $vocabScores = [];
        $styleScores = [];
        $timeline    = [];

        foreach ($items->sortBy('created_at') as $item) {
            $meta        = is_array($item->ai_meta) ? $item->ai_meta : [];
            $consistency = data_get($meta, 'alter_ego_consistency');
            if (!is_array($consistency)) {
                continue;
            }
            $score = (int) ($consistency['score'] ?? 0);
            if ($score <= 0) {
                continue;
            }
            $scores[]       = $score;
            $timeline[]     = [
                'score'       => $score,
                'created_at'  => $item->created_at?->format('d/m'),
                'platform'    => (string) ($item->platform ?? ''),
            ];
            if (isset($consistency['tone_score']))       $toneScores[]  = (int) $consistency['tone_score'];
            if (isset($consistency['vocabulary_score'])) $vocabScores[] = (int) $consistency['vocabulary_score'];
            if (isset($consistency['style_score']))      $styleScores[] = (int) $consistency['style_score'];
        }

        return [
            'post_count'      => $items->count(),
            'scored_count'    => count($scores),
            'avg_score'       => $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : null,
            'avg_tone'        => $toneScores !== [] ? (int) round(array_sum($toneScores) / count($toneScores)) : null,
            'avg_vocab'       => $vocabScores !== [] ? (int) round(array_sum($vocabScores) / count($vocabScores)) : null,
            'avg_style'       => $styleScores !== [] ? (int) round(array_sum($styleScores) / count($styleScores)) : null,
            'scores_timeline' => $timeline,
            'recent_items'    => $items->sortByDesc('created_at'),
        ];
    }

    /**
     * Riepilogo rapido per tutti gli alter ego di un tenant — usato nelle card dell'index.
     *
     * @param  list<int>  $alterEgoIds
     * @return array<int, array{post_count: int, avg_score: int|null}>
     */
    public function summaryForIds(array $alterEgoIds): array
    {
        if ($alterEgoIds === []) {
            return [];
        }

        $result = [];
        foreach ($alterEgoIds as $id) {
            $result[$id] = ['post_count' => 0, 'avg_score' => null, 'raw_scores' => []];
        }

        $items = ContentItem::query()
            ->whereIn('alter_ego_id', $alterEgoIds)
            ->get(['alter_ego_id', 'ai_meta']);

        foreach ($items as $item) {
            $id = (int) $item->alter_ego_id;
            if (!isset($result[$id])) {
                continue;
            }
            $result[$id]['post_count']++;
            $meta  = is_array($item->ai_meta) ? $item->ai_meta : [];
            $score = (int) data_get($meta, 'alter_ego_consistency.score', 0);
            if ($score > 0) {
                $result[$id]['raw_scores'][] = $score;
            }
        }

        foreach ($result as $id => &$data) {
            if ($data['raw_scores'] !== []) {
                $data['avg_score'] = (int) round(array_sum($data['raw_scores']) / count($data['raw_scores']));
            }
            unset($data['raw_scores']);
        }

        return $result;
    }
}
