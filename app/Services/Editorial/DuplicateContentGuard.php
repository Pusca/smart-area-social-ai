<?php

namespace App\Services\Editorial;

use App\Models\ContentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DuplicateContentGuard
{
    public function fingerprint(int $tenantId, array $payload): string
    {
        $base = implode('|', [
            $tenantId,
            Str::lower((string) ($payload['platform'] ?? '')),
            Str::lower((string) ($payload['format'] ?? '')),
            Str::lower((string) ($payload['rubric'] ?? '')),
            Str::lower((string) ($payload['pillar'] ?? '')),
            Str::lower((string) ($payload['content_angle'] ?? '')),
            Str::lower(implode(',', $this->extractKeywords((string) ($payload['keywords'] ?? '')))),
        ]);

        return hash('sha256', $base);
    }

    public function hasHardDuplicate(int $tenantId, string $fingerprint, int $days = 180): bool
    {
        return ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('fingerprint', $fingerprint)
            ->where(function ($q) use ($days) {
                $q->where('scheduled_at', '>=', Carbon::now()->subDays($days))
                    ->orWhere('created_at', '>=', Carbon::now()->subDays($days));
            })
            ->exists();
    }

    public function softSimilarityScore(string $a, string $b): float
    {
        $aNorm = $this->normalize($a);
        $bNorm = $this->normalize($b);
        if ($aNorm === '' || $bNorm === '') {
            return 0.0;
        }

        $jaccard = $this->jaccardWordScore($aNorm, $bNorm);
        $trigramCosine = $this->trigramCosineScore($aNorm, $bNorm);

        return min(1.0, ($jaccard * 0.55) + ($trigramCosine * 0.45));
    }

    public function findSoftDuplicate(
        int $tenantId,
        string $candidateText,
        int $days = 180,
        float $threshold = 0.78
    ): ?array {
        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($days) {
                $q->where('scheduled_at', '>=', Carbon::now()->subDays($days))
                    ->orWhere('created_at', '>=', Carbon::now()->subDays($days));
            })
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'title', 'caption', 'ai_caption', 'rubric', 'pillar', 'content_angle']);

        $best = null;
        $bestScore = 0.0;

        foreach ($rows as $row) {
            $text = trim((string) (($row->title ?? '') . ' ' . ($row->ai_caption ?: $row->caption ?: '')));
            if ($text === '') {
                continue;
            }
            $score = $this->softSimilarityScore($candidateText, $text);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'id' => (int) $row->id,
                    'score' => $score,
                    'rubric' => (string) ($row->rubric ?? ''),
                    'pillar' => (string) ($row->pillar ?? ''),
                    'content_angle' => (string) ($row->content_angle ?? ''),
                ];
            }
        }

        if ($best && $best['score'] >= $threshold) {
            return $best;
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = Str::lower(trim($text));
        $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    private function jaccardWordScore(string $a, string $b): float
    {
        $wa = array_values(array_unique(array_filter(explode(' ', $a))));
        $wb = array_values(array_unique(array_filter(explode(' ', $b))));

        if (empty($wa) || empty($wb)) {
            return 0.0;
        }

        $inter = count(array_intersect($wa, $wb));
        $union = count(array_unique(array_merge($wa, $wb)));

        return $inter / max(1, $union);
    }

    private function trigramCosineScore(string $a, string $b): float
    {
        $va = $this->trigramVector($a);
        $vb = $this->trigramVector($b);

        if (empty($va) || empty($vb)) {
            return 0.0;
        }

        $dot = 0.0;
        foreach ($va as $k => $v) {
            $dot += $v * ($vb[$k] ?? 0.0);
        }

        $na = sqrt(array_sum(array_map(fn ($x) => $x * $x, $va)));
        $nb = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vb)));
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / ($na * $nb)));
    }

    private function trigramVector(string $text): array
    {
        $text = '  ' . $text . '  ';
        $len = mb_strlen($text);
        $vec = [];
        for ($i = 0; $i <= $len - 3; $i++) {
            $tri = mb_substr($text, $i, 3);
            $vec[$tri] = ($vec[$tri] ?? 0) + 1;
        }
        return $vec;
    }

    private function extractKeywords(string $text): array
    {
        $norm = $this->normalize($text);
        $words = array_filter(explode(' ', $norm), fn ($w) => mb_strlen($w) >= 4);
        return array_slice(array_values(array_unique($words)), 0, 10);
    }
}

