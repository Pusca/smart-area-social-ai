<?php

namespace App\Services;

use App\Models\ContentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MemoryBuilderService
{
    public function buildForTenant(int $tenantId, int $limit = 40): array
    {
        $query = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNotNull('published_at')
                    ->orWhere('status', 'published')
                    ->orWhere(function ($q2) {
                        $q2->where('ai_status', 'done')
                            ->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '<=', now());
                    });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->limit($limit);

        $rows = $query->get([
            'id',
            'title',
            'caption',
            'ai_caption',
            'ai_cta',
            'hashtags',
            'ai_hashtags',
            'scheduled_at',
            'published_at',
            'platform',
            'format',
        ]);

        $themeScores = [];
        $offerScores = [];
        $ctaScores = [];
        $hashtagScores = [];
        $recentTitles = [];
        $recentHooks = [];

        foreach ($rows as $row) {
            $title = trim((string) $row->title);
            $caption = trim((string) ($row->ai_caption ?: $row->caption ?: ''));
            $cta = trim((string) ($row->ai_cta ?: ''));

            if ($title !== '') {
                $recentTitles[] = Str::limit($title, 90, '');
            }

            if ($caption !== '') {
                $recentHooks[] = Str::limit($caption, 120, '');
            }

            foreach ($this->extractKeywords($title . ' ' . $caption) as $keyword) {
                $themeScores[$keyword] = ($themeScores[$keyword] ?? 0) + 1;
            }

            foreach ($this->extractOfferSignals($title . ' ' . $caption . ' ' . $cta) as $signal) {
                $offerScores[$signal] = ($offerScores[$signal] ?? 0) + 1;
            }

            if ($cta !== '') {
                $normalizedCta = $this->normalizePhrase($cta);
                if ($normalizedCta !== '') {
                    $ctaScores[$normalizedCta] = ($ctaScores[$normalizedCta] ?? 0) + 1;
                }
            }

            $hashtags = $this->normalizeHashtags($row->ai_hashtags ?: $row->hashtags);
            foreach ($hashtags as $tag) {
                $hashtagScores[$tag] = ($hashtagScores[$tag] ?? 0) + 1;
            }
        }

        arsort($themeScores);
        arsort($offerScores);
        arsort($ctaScores);
        arsort($hashtagScores);

        $themes = array_slice(array_keys($themeScores), 0, 12);
        $offers = array_slice(array_keys($offerScores), 0, 8);
        $ctas = array_slice(array_keys($ctaScores), 0, 8);
        $hashtags = array_slice(array_keys($hashtagScores), 0, 20);

        $lastDate = $rows->first()
            ? Carbon::parse($rows->first()->published_at ?: $rows->first()->scheduled_at)->toDateString()
            : null;

        return [
            'posts_count' => $rows->count(),
            'last_post_date' => $lastDate,
            'themes' => $themes,
            'offers' => $offers,
            'ctas' => $ctas,
            'hashtags' => $hashtags,
            'recent_titles' => array_values(array_unique(array_slice($recentTitles, 0, 12))),
            'recent_hooks' => array_values(array_unique(array_slice($recentHooks, 0, 12))),
            'avoid_repetition' => array_values(array_unique(array_merge(
                array_slice($themes, 0, 6),
                array_slice($offers, 0, 4),
                array_slice($ctas, 0, 4)
            ))),
        ];
    }

    private function extractKeywords(string $text): array
    {
        $text = Str::lower($text);
        $text = preg_replace('/[#@]/', ' ', $text) ?? '';
        $text = preg_replace('/[^a-z0-9àèéìòù\s]/u', ' ', $text) ?? '';

        $stopWords = [
            'the', 'for', 'and', 'con', 'per', 'una', 'uno', 'della', 'delle', 'dell', 'degli', 'anche',
            'this', 'that', 'from', 'sono', 'come', 'your', 'nostro', 'nostra', 'vostro', 'vostra', 'post',
            'oggi', 'domani', 'ieri', 'alla', 'allo', 'agli', 'alla', 'nelle', 'nella', 'sulla', 'sulle',
            'brand', 'social', 'media', 'cliente', 'clienti', 'service', 'servizi', 'piano', 'contenuto',
        ];
        $stopLookup = array_fill_keys($stopWords, true);

        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 4) {
                continue;
            }
            if (isset($stopLookup[$token])) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    private function extractOfferSignals(string $text): array
    {
        $text = Str::lower($text);
        $signals = [];
        foreach (['offerta', 'offerte', 'promo', 'promozione', 'sconto', 'coupon', 'pacchetto', 'bonus'] as $word) {
            if (Str::contains($text, $word)) {
                $signals[] = $word;
            }
        }
        return $signals;
    }

    private function normalizePhrase(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return Str::limit($value, 70, '');
    }

    private function normalizeHashtags(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value)) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            $parts = [];
        }

        $out = [];
        foreach ($parts as $part) {
            $tag = trim((string) $part);
            if ($tag === '') {
                continue;
            }
            if (!Str::startsWith($tag, '#')) {
                $tag = '#' . ltrim($tag, '#');
            }
            $out[] = Str::lower($tag);
        }

        return $out;
    }
}
