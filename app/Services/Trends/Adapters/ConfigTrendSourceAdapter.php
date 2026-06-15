<?php

namespace App\Services\Trends\Adapters;

use App\Services\Trends\TrendSourceAdapter;

class ConfigTrendSourceAdapter implements TrendSourceAdapter
{
    public function collect(array $context = []): array
    {
        return $this->normalizeSignals(
            (array) config('trends.seed_signals', []),
            'config_seed',
            'config'
        );
    }

    /**
     * @param  array<int, mixed>  $signals
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSignals(array $signals, string $fallbackSourceType = 'config_seed', string $sourcePrefix = 'config'): array
    {
        return array_values(array_filter(array_map(function ($signal) use ($fallbackSourceType, $sourcePrefix) {
            if (!is_array($signal)) {
                return null;
            }

            $topic = trim((string) ($signal['topic'] ?? ''));
            $platform = strtolower(trim((string) ($signal['platform'] ?? 'instagram')));
            if ($topic === '' || $platform === '') {
                return null;
            }

            return [
                'platform' => $platform,
                'topic' => $topic,
                'title' => trim((string) ($signal['title'] ?? $topic)),
                'summary' => trim((string) ($signal['summary'] ?? implode(' ', (array) ($signal['style_notes'] ?? [])))),
                'format_type' => strtolower(trim((string) ($signal['format_type'] ?? 'post'))),
                'hook_patterns' => array_values(array_filter(array_map('strval', (array) ($signal['hook_patterns'] ?? [])))),
                'style_notes' => array_values(array_filter(array_map('strval', (array) ($signal['style_notes'] ?? [])))),
                'freshness_score' => isset($signal['freshness_score']) ? (float) $signal['freshness_score'] : 0.5,
                'confidence_score' => isset($signal['confidence_score']) ? (float) $signal['confidence_score'] : 0.72,
                'saturation_score' => isset($signal['saturation_score']) ? (float) $signal['saturation_score'] : 0.5,
                'risk_flags' => array_values(array_filter(array_map('strval', (array) ($signal['risk_flags'] ?? [])))),
                'source_type' => trim((string) ($signal['source_type'] ?? $fallbackSourceType)),
                'source_ref' => trim((string) ($signal['source_ref'] ?? ($sourcePrefix . ':' . $platform . ':' . $topic))),
                'observed_at' => now()->toDateTimeString(),
                'expires_at' => (string) ($signal['expires_at'] ?? now()->addHours((int) config('social_manager.trend_brief.default_expiry_hours', 96))->toDateTimeString()),
                'niche_tags' => array_values(array_filter(array_map('strval', (array) ($signal['industries'] ?? [])))),
                'format_tags' => array_values(array_filter(array_map('strval', array_merge(
                    (array) ($signal['format_tags'] ?? []),
                    [(string) ($signal['format_type'] ?? 'post')]
                )))),
                'platform_tags' => array_values(array_filter(array_map('strval', array_merge(
                    (array) ($signal['platform_tags'] ?? []),
                    [$platform]
                )))),
                'meta' => [
                    'industries' => array_values(array_filter(array_map('strval', (array) ($signal['industries'] ?? [])))),
                    'audiences' => array_values(array_filter(array_map('strval', (array) ($signal['audiences'] ?? [])))),
                    'goals' => array_values(array_filter(array_map('strval', (array) ($signal['goals'] ?? [])))),
                    'tags' => array_values(array_filter(array_map('strval', (array) ($signal['tags'] ?? [])))),
                    'requires' => array_values(array_filter(array_map('strval', (array) ($signal['requires'] ?? [])))),
                ],
                'evidence_payload' => [
                    'adapter' => $fallbackSourceType,
                    'seed_topic' => $topic,
                    'seed_platform' => $platform,
                    'seed_tags' => array_values(array_filter(array_map('strval', (array) ($signal['tags'] ?? [])))),
                ],
            ];
        }, $signals)));
    }
}
