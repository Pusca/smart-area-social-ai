<?php

namespace App\Services\Trends\Adapters;

use App\Services\Trends\TrendSourceAdapter;

class ConfigTrendSourceAdapter implements TrendSourceAdapter
{
    public function collect(array $context = []): array
    {
        $signals = (array) config('trends.seed_signals', []);

        return array_values(array_filter(array_map(function ($signal) {
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
                'format_type' => strtolower(trim((string) ($signal['format_type'] ?? 'post'))),
                'hook_patterns' => array_values(array_filter(array_map('strval', (array) ($signal['hook_patterns'] ?? [])))),
                'style_notes' => array_values(array_filter(array_map('strval', (array) ($signal['style_notes'] ?? [])))),
                'freshness_score' => isset($signal['freshness_score']) ? (float) $signal['freshness_score'] : 0.5,
                'saturation_score' => isset($signal['saturation_score']) ? (float) $signal['saturation_score'] : 0.5,
                'risk_flags' => array_values(array_filter(array_map('strval', (array) ($signal['risk_flags'] ?? [])))),
                'source_type' => trim((string) ($signal['source_type'] ?? 'config_seed')),
                'observed_at' => now()->toDateTimeString(),
                'meta' => [
                    'industries' => array_values(array_filter(array_map('strval', (array) ($signal['industries'] ?? [])))),
                    'audiences' => array_values(array_filter(array_map('strval', (array) ($signal['audiences'] ?? [])))),
                    'goals' => array_values(array_filter(array_map('strval', (array) ($signal['goals'] ?? [])))),
                    'tags' => array_values(array_filter(array_map('strval', (array) ($signal['tags'] ?? [])))),
                    'requires' => array_values(array_filter(array_map('strval', (array) ($signal['requires'] ?? [])))),
                ],
            ];
        }, $signals)));
    }
}