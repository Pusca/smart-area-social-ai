<?php

namespace App\Services\Editorial;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentGenerator
{
    public function __construct(
        private readonly DuplicateContentGuard $duplicateGuard
    ) {
    }

    public function generateForPlan(
        ContentPlan $plan,
        array $planItems,
        array $context = []
    ): array {
        $tenantId = (int) $plan->tenant_id;
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : null;
        $profileData = (array) ($context['profile_data'] ?? []);
        $strategy = (array) ($context['strategy'] ?? []);
        $memory = (array) ($context['memory'] ?? []);
        $assets = (array) ($context['assets'] ?? []);
        $constraints = (array) data_get($strategy, 'constraints', []);

        $hardWindow = (int) ($constraints['no_repeat_days'] ?? config('editorial.duplicate_window_days', 180));
        $softThreshold = (float) ($constraints['soft_similarity_threshold'] ?? config('editorial.soft_similarity_threshold', 0.78));
        $maxAttempts = (int) ($constraints['max_regeneration_attempts'] ?? config('editorial.max_regeneration_attempts', 2));

        $created = [];
        $inMemoryFingerprints = [];

        DB::transaction(function () use (
            $planItems,
            $tenantId,
            $plan,
            $userId,
            $profileData,
            $strategy,
            $memory,
            $assets,
            $hardWindow,
            $softThreshold,
            $maxAttempts,
            &$created,
            &$inMemoryFingerprints
        ) {
            foreach ($planItems as $index => $item) {
                $resolved = $this->resolveUniqueness(
                    tenantId: $tenantId,
                    payload: $item,
                    localFingerprints: $inMemoryFingerprints,
                    hardWindow: $hardWindow,
                    softThreshold: $softThreshold,
                    maxAttempts: $maxAttempts
                );

                $fingerprint = $resolved['fingerprint'];
                $similarityGroup = $resolved['similarity_group'];
                $inMemoryFingerprints[$fingerprint] = true;

                $scheduledAt = !empty($item['scheduled_at'])
                    ? Carbon::parse((string) $item['scheduled_at'])
                    : null;

                $record = ContentItem::create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => $plan->id,
                    'created_by' => $userId,
                    'platform' => (string) ($item['platform'] ?? 'instagram'),
                    'format' => (string) ($item['format'] ?? 'post'),
                    'scheduled_at' => $scheduledAt,
                    'status' => 'draft',
                    'title' => (string) ($item['title_hint'] ?? ('Post ' . ($index + 1))),
                    'caption' => null,
                    'hashtags' => [],
                    'assets' => [],
                    'rubric' => (string) ($resolved['payload']['rubric'] ?? ''),
                    'series_key' => data_get($resolved['payload'], 'series_key'),
                    'episode_number' => data_get($resolved['payload'], 'episode_number'),
                    'pillar' => (string) ($resolved['payload']['pillar'] ?? ''),
                    'content_angle' => (string) ($resolved['payload']['content_angle'] ?? ''),
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'source_refs' => $resolved['payload']['source_refs'] ?? [],
                    'ai_status' => 'queued',
                    'ai_meta' => [
                        'tenant_profile' => $profileData,
                        'brand_assets' => $assets,
                        'plan' => [
                            'goal' => data_get($plan->settings, 'goal'),
                            'tone' => data_get($plan->settings, 'tone'),
                            'posts_total' => data_get($plan->settings, 'posts_total'),
                            'platforms' => data_get($plan->settings, 'platforms'),
                            'formats' => data_get($plan->settings, 'formats'),
                            'date_range' => [$plan->start_date?->toDateString(), $plan->end_date?->toDateString()],
                        ],
                        'memory_summary' => $memory,
                        'strategy' => [
                            'pillars' => data_get($strategy, 'pillars', []),
                            'rubrics' => data_get($strategy, 'rubrics', []),
                            'cta_rules' => data_get($strategy, 'cta_rules', []),
                            'constraints' => data_get($strategy, 'constraints', []),
                            'brand_references' => data_get($strategy, 'brand_references', []),
                        ],
                        'item_brain' => [
                            'rubric' => (string) ($resolved['payload']['rubric'] ?? ''),
                            'pillar' => (string) ($resolved['payload']['pillar'] ?? ''),
                            'angle' => (string) ($resolved['payload']['content_angle'] ?? ''),
                            'objective' => (string) ($resolved['payload']['objective'] ?? 'Awareness'),
                            'key_points' => (array) ($resolved['payload']['key_points'] ?? []),
                            'cta' => (string) ($resolved['payload']['primary_cta'] ?? ''),
                            'image_direction' => (string) ($resolved['payload']['image_direction'] ?? ''),
                            'series_name' => (string) ($resolved['payload']['series_key'] ?? ''),
                            'series_step' => $resolved['payload']['episode_number'] ?? null,
                            'standalone_rule' => 'Il contenuto deve essere completo anche se letto singolarmente.',
                            'connection_hint' => $resolved['connection_hint'],
                            'uniqueness_key' => $fingerprint,
                        ],
                    ],
                ]);

                $created[] = $record;
            }
        });

        return $created;
    }

    private function resolveUniqueness(
        int $tenantId,
        array $payload,
        array $localFingerprints,
        int $hardWindow,
        float $softThreshold,
        int $maxAttempts
    ): array {
        $working = $payload;
        $attempts = max(1, $maxAttempts + 1);
        $similarityGroup = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $working['content_angle'] = $this->mutateAngle((string) ($working['content_angle'] ?? ''), $attempt);
            }

            $fingerprint = $this->duplicateGuard->fingerprint($tenantId, [
                'platform' => $working['platform'] ?? '',
                'format' => $working['format'] ?? '',
                'rubric' => $working['rubric'] ?? '',
                'pillar' => $working['pillar'] ?? '',
                'content_angle' => $working['content_angle'] ?? '',
                'keywords' => $working['keywords'] ?? $working['content_angle'] ?? '',
            ]);

            $isHardDuplicate = isset($localFingerprints[$fingerprint])
                || $this->duplicateGuard->hasHardDuplicate($tenantId, $fingerprint, $hardWindow);

            $candidateText = trim(implode(' ', [
                (string) ($working['title_hint'] ?? ''),
                (string) ($working['content_angle'] ?? ''),
            ]));

            $soft = $this->duplicateGuard->findSoftDuplicate($tenantId, $candidateText, $hardWindow, $softThreshold);

            if (!$isHardDuplicate && $soft === null) {
                return [
                    'payload' => $working,
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'connection_hint' => $attempt === 0
                        ? 'Nuovo contenuto coerente con il piano.'
                        : 'Contenuto rigenerato per evitare sovrapposizioni.',
                ];
            }

            if ($soft !== null) {
                $similarityGroup = 'sim-' . (int) $soft['id'];
            }

            if ($attempt >= ($attempts - 1)) {
                $working = $this->fallbackRubric($working);
                $fingerprint = $this->duplicateGuard->fingerprint($tenantId, [
                    'platform' => $working['platform'] ?? '',
                    'format' => $working['format'] ?? '',
                    'rubric' => $working['rubric'] ?? '',
                    'pillar' => $working['pillar'] ?? '',
                    'content_angle' => $working['content_angle'] ?? '',
                    'keywords' => $working['keywords'] ?? $working['content_angle'] ?? '',
                ]);

                return [
                    'payload' => $working,
                    'fingerprint' => $fingerprint,
                    'similarity_group' => $similarityGroup,
                    'connection_hint' => 'Fallback rubric applicato per evitare duplicati.',
                ];
            }
        }

        $fingerprint = $this->duplicateGuard->fingerprint($tenantId, $working);

        return [
            'payload' => $working,
            'fingerprint' => $fingerprint,
            'similarity_group' => $similarityGroup,
            'connection_hint' => 'Contenuto generato.',
        ];
    }

    private function mutateAngle(string $angle, int $attempt): string
    {
        $angle = trim($angle);
        if ($angle === '') {
            $angle = 'Approccio pratico orientato al risultato';
        }

        $suffixes = [
            'con focus su errore frequente',
            'in formato checklist operativa',
            'con mini-case concreto e takeaway',
        ];

        $suffix = $suffixes[($attempt - 1) % count($suffixes)];
        return Str::limit($angle . ' - ' . $suffix, 170, '');
    }

    private function fallbackRubric(array $payload): array
    {
        $current = Str::lower((string) ($payload['rubric'] ?? ''));
        $fallbacks = ['Educativo', 'Community', 'Storia Brand', 'Prova Sociale'];
        foreach ($fallbacks as $candidate) {
            if (Str::lower($candidate) !== $current) {
                $payload['rubric'] = $candidate;
                break;
            }
        }

        $payload['content_angle'] = $this->mutateAngle((string) ($payload['content_angle'] ?? ''), 2);
        $payload['title_hint'] = Str::limit(((string) $payload['rubric']) . ': ' . ((string) ($payload['pillar'] ?? '')), 110, '');
        return $payload;
    }
}
