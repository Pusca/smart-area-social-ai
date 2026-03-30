<?php

namespace App\Services\AI;

use App\Models\ContentItem;
use App\Models\GenerationRun;
use Illuminate\Support\Str;

class IdentityGuard
{
    /**
     * @param  array<string, mixed>  $assetIdentity
     * @param  array<string, mixed>  $assetScoring
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>
     */
    public function preflight(array $assetIdentity, array $assetScoring, array $providerMatrix = [], array $context = []): array
    {
        $slots = collect((array) data_get($assetIdentity, 'slots', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row, string $slot): array {
                $identityPack = is_array($row['identity_pack'] ?? null) ? $row['identity_pack'] : [];

                return [
                    'slot' => $slot,
                    'name' => (string) ($row['name'] ?? ''),
                    'kind' => (string) ($row['kind'] ?? ''),
                    'strictness_level' => (string) ($row['strictness_level'] ?? data_get($identityPack, 'strictness_level', 'balanced')),
                    'canonical_references' => array_values(array_filter(array_map(
                        fn ($asset) => is_array($asset) ? (string) ($asset['path'] ?? '') : '',
                        (array) data_get($identityPack, 'canonical_assets', [])
                    ))),
                    'invariants' => array_values(array_filter(array_map('strval', (array) ($row['maintain_elements'] ?? $row['locked_elements'] ?? data_get($identityPack, 'invariants', []))))),
                    'transformables' => array_values(array_filter(array_map('strval', (array) ($row['changeable_elements'] ?? $row['allowed_changes'] ?? $row['allowed_transforms'] ?? data_get($identityPack, 'transformables', []))))),
                ];
            })
            ->values();

        $provider = trim((string) data_get($providerMatrix, 'video.provider', data_get($providerMatrix, 'image.provider', '')));
        $strictAssetMode = (bool) ($context['strict_asset_mode'] ?? false);
        $blockingReasons = [];

        foreach ($slots as $slot) {
            if ($strictAssetMode && empty($slot['canonical_references'])) {
                $blockingReasons[] = 'IdentityGuard: manca un riferimento canonico per lo slot ' . $slot['slot'] . '.';
            }
        }

        return [
            'version' => (string) config('social_manager.identity_guard.version', 'identity_guard_v1'),
            'checked_at' => now()->toDateTimeString(),
            'identity_heavy' => $slots->isNotEmpty(),
            'provider' => $provider,
            'status' => empty($blockingReasons) ? 'pass' : 'blocked',
            'slots' => $slots->all(),
            'provider_specific_constraints' => [
                'provider' => $provider,
                'constraint_text' => $this->providerConstraintText($slots->all(), $provider),
            ],
            'asset_scoring_snapshot' => [
                'selection_area' => (string) ($assetScoring['selection_area'] ?? ''),
                'provider' => (string) ($assetScoring['provider'] ?? ''),
                'identity_confidence' => $assetScoring['identity_confidence'] ?? null,
            ],
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
        ];
    }

    public function validateFinal(ContentItem $item, ?GenerationRun $run = null): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $slots = collect((array) data_get($meta, 'asset_identity.slots', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $baseScores = array_values(array_filter([
            $this->numeric(data_get($meta, 'video_generation.reference_validation.confidence')),
            $this->numeric(data_get($meta, 'image_generation.alignment_review.confidence')),
            $this->numeric(data_get($meta, 'asset_scoring.identity_confidence')),
        ], fn ($value) => $value !== null));
        $baseScore = empty($baseScores) ? null : min($baseScores);

        $blockingReasons = [];
        $validatedSlots = [];

        foreach ($slots as $slot => $row) {
            $kind = Str::lower(trim((string) ($row['kind'] ?? $slot)));
            $slotName = (string) ($row['name'] ?? $slot);
            $threshold = $this->slotThreshold($kind);
            $score = $baseScore;
            $status = $score === null ? 'unknown' : ($score >= $threshold ? 'pass' : 'fail');

            if ($status === 'fail') {
                $blockingReasons[] = match ($kind) {
                    'person' => 'IdentityGuard: person face drift above threshold for ' . $slotName . '.',
                    'product' => 'IdentityGuard: product geometry deformation above threshold for ' . $slotName . '.',
                    'location' => 'IdentityGuard: location inconsistency above threshold for ' . $slotName . '.',
                    default => 'IdentityGuard: identity fidelity below threshold for ' . $slotName . '.',
                };
            }

            $validatedSlots[] = [
                'slot' => is_string($slot) ? $slot : (string) $kind,
                'name' => $slotName,
                'kind' => $kind,
                'score' => $score,
                'threshold' => $threshold,
                'status' => $status,
                'source' => $this->validationSource($meta),
            ];
        }

        return [
            'version' => (string) config('social_manager.identity_guard.version', 'identity_guard_v1'),
            'checked_at' => now()->toDateTimeString(),
            'status' => empty($blockingReasons) ? 'pass' : 'blocked',
            'identity_heavy' => !empty($validatedSlots),
            'slots' => $validatedSlots,
            'recommended_retry_layer' => empty($blockingReasons) ? null : 'visual',
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
            'run_id' => $run?->id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function providerConstraintText(array $slots, string $provider): string
    {
        if ($slots === []) {
            return 'Nessun vincolo identitario specifico richiesto.';
        }

        $parts = [];
        foreach ($slots as $slot) {
            $parts[] = trim(implode(' ', array_filter([
                (string) ($slot['slot'] ?? ''),
                $slot['canonical_references'] !== [] ? 'usa i riferimenti canonici reali' : '',
                !empty($slot['invariants']) ? 'mantieni ' . implode(', ', array_slice((array) $slot['invariants'], 0, 3)) : '',
                !empty($slot['transformables']) ? 'puoi variare ' . implode(', ', array_slice((array) $slot['transformables'], 0, 2)) : '',
            ])));
        }

        return trim('Provider ' . ($provider !== '' ? $provider : 'default') . ': ' . implode('; ', array_filter($parts)));
    }

    private function slotThreshold(string $kind): float
    {
        return match ($kind) {
            'person' => (float) config('social_manager.identity_guard.thresholds.person_identity_min_score', 0.78),
            'product' => (float) config('social_manager.identity_guard.thresholds.product_integrity_min_score', 0.76),
            'location' => (float) config('social_manager.identity_guard.thresholds.location_consistency_min_score', 0.74),
            default => (float) config('social_manager.identity_guard.thresholds.generic_identity_min_score', 0.72),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationSource(array $meta): string
    {
        if (is_numeric(data_get($meta, 'video_generation.reference_validation.confidence'))) {
            return 'video_generation.reference_validation.confidence';
        }
        if (is_numeric(data_get($meta, 'image_generation.alignment_review.confidence'))) {
            return 'image_generation.alignment_review.confidence';
        }

        return 'asset_scoring.identity_confidence';
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : null;
    }
}
