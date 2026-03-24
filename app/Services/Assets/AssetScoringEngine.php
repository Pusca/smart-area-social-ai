<?php

namespace App\Services\Assets;

use App\Models\ContentItem;
use App\Services\AI\ProviderCapabilityRegistry;
use App\Services\AssetIdentityService;
use Illuminate\Support\Str;

class AssetScoringEngine
{
    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilityRegistry,
        private readonly AssetIdentityService $assetIdentityService
    ) {
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $assetIdentity
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function score(array $assetVariables, array $assetIdentity = [], array $context = []): array
    {
        $providerMatrix = (array) ($context['provider_matrix'] ?? []);
        $area = $this->resolveArea($context);
        $provider = $this->resolveProvider($providerMatrix, $context, $area);
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        $contentItemId = (int) ($context['content_item_id'] ?? 0);
        $format = Str::lower(trim((string) ($context['format'] ?? 'post')));
        $platform = Str::lower(trim((string) ($context['platform'] ?? 'instagram')));
        $strictAssetMode = (bool) ($context['strict_asset_mode'] ?? false);

        $rows = $this->mergeVariableRowsForScoring($assetVariables, $assetIdentity);
        $slotPriority = $this->slotPriorityMap($rows, $assetIdentity);
        $candidates = $this->buildCandidates($rows, $slotPriority);

        if ($candidates === []) {
            return $this->emptySelection($area, $provider, $strictAssetMode);
        }

        $historySignals = $this->buildHistorySignals(
            tenantId: $tenantId,
            excludeContentItemId: $contentItemId,
            candidatePaths: array_values(array_unique(array_filter(array_map(
                fn (array $candidate) => trim((string) ($candidate['path'] ?? '')),
                $candidates
            ))))
        );

        foreach ($candidates as $index => $candidate) {
            $candidates[$index] = $this->scoreCandidate(
                candidate: $candidate,
                historySignals: $historySignals,
                area: $area,
                provider: $provider,
                format: $format,
                platform: $platform,
                strictAssetMode: $strictAssetMode
            );
        }

        $rankedBySlot = [];
        foreach ($candidates as $candidate) {
            $slot = (string) ($candidate['slot'] ?? 'generic');
            $rankedBySlot[$slot][] = $candidate;
        }
        foreach ($rankedBySlot as $slot => $items) {
            usort($items, fn (array $a, array $b) => $this->compareCandidates($a, $b));
            $rankedBySlot[$slot] = $items;
        }

        $selection = $this->selectCandidates(
            rankedBySlot: $rankedBySlot,
            slotPriority: $slotPriority,
            area: $area,
            provider: $provider,
            strictAssetMode: $strictAssetMode
        );

        $selectedKeys = array_fill_keys(array_map(fn (array $row) => (string) ($row['key'] ?? ''), $selection['selected']), true);
        $fallbackKeys = array_fill_keys(array_map(fn (array $row) => (string) ($row['key'] ?? ''), $selection['fallback']), true);

        $ranking = [];
        foreach ($rankedBySlot as $slot => $items) {
            foreach ($items as $position => $candidate) {
                $status = isset($selectedKeys[(string) ($candidate['key'] ?? '')])
                    ? ($position === 0 ? 'selected_primary' : 'selected_supporting')
                    : (isset($fallbackKeys[(string) ($candidate['key'] ?? '')]) ? 'fallback' : 'excluded');

                $whySelected = $status === 'selected_primary' || $status === 'selected_supporting'
                    ? $this->selectedReasons($candidate, $area, $provider)
                    : [];
                $whyExcluded = match ($status) {
                    'fallback' => $this->fallbackReasons($candidate, $area, $provider, $strictAssetMode),
                    'excluded' => $this->excludedReasons($candidate, $area, $provider, $strictAssetMode),
                    default => [],
                };

                $ranking[] = $this->serializeCandidate(
                    candidate: $candidate,
                    status: $status,
                    whySelected: $whySelected,
                    whyExcluded: $whyExcluded
                );
            }
        }

        usort($ranking, function (array $a, array $b): int {
            $scoreOrder = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            return ((int) ($a['slot_priority'] ?? 99)) <=> ((int) ($b['slot_priority'] ?? 99));
        });

        $selectedSerialized = [];
        foreach ($selection['selected'] as $index => $candidate) {
            $selectedSerialized[] = $this->serializeCandidate(
                candidate: $candidate,
                status: $index === 0 ? 'selected_primary' : 'selected_supporting',
                whySelected: $this->selectedReasons($candidate, $area, $provider),
                whyExcluded: []
            );
        }

        $fallbackSerialized = array_map(
            fn (array $candidate) => $this->serializeCandidate(
                candidate: $candidate,
                status: 'fallback',
                whySelected: [],
                whyExcluded: $this->fallbackReasons($candidate, $area, $provider, $strictAssetMode)
            ),
            $selection['fallback']
        );

        $excludedSerialized = array_map(
            fn (array $candidate) => $this->serializeCandidate(
                candidate: $candidate,
                status: 'excluded',
                whySelected: [],
                whyExcluded: $this->excludedReasons($candidate, $area, $provider, $strictAssetMode)
            ),
            $selection['excluded']
        );

        $identityConfidence = $this->identityConfidence(
            selected: $selection['selected'],
            expectedSlots: $selection['expected_slots'],
            area: $area,
            provider: $provider,
            strictAssetMode: $strictAssetMode
        );

        return [
            'version' => 'asset_scoring_engine_v1',
            'selection_area' => $area,
            'provider' => $provider,
            'primary_asset' => $selectedSerialized[0] ?? null,
            'supporting_assets' => array_slice($selectedSerialized, 1, 4),
            'excluded_assets' => array_slice($excludedSerialized, 0, 12),
            'fallback_assets' => array_slice($fallbackSerialized, 0, 6),
            'reference_paths' => array_values(array_filter(array_map(
                fn (array $row) => (string) ($row['path'] ?? ''),
                $selectedSerialized
            ))),
            'fallback_paths' => array_values(array_filter(array_map(
                fn (array $row) => (string) ($row['path'] ?? ''),
                $fallbackSerialized
            ))),
            'identity_confidence' => round($identityConfidence, 4),
            'asset_ranking' => array_slice($ranking, 0, 18),
            'selection_summary' => [
                'provider' => $provider,
                'selection_area' => $area,
                'strict_asset_mode' => $strictAssetMode,
                'selected_count' => count($selectedSerialized),
                'fallback_count' => count($fallbackSerialized),
                'excluded_count' => count($excludedSerialized),
                'expected_slot_count' => count($selection['expected_slots']),
                'covered_slot_count' => count($selection['covered_slots']),
                'covered_slots' => array_values($selection['covered_slots']),
                'max_reference_count' => (int) $selection['max_reference_count'],
                'format' => $format,
                'platform' => $platform,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $assetVariables
     * @param  array<string, mixed>  $assetIdentity
     * @return array<int, array<string, mixed>>
     */
    private function mergeVariableRowsForScoring(array $assetVariables, array $assetIdentity): array
    {
        $rows = [];

        foreach ((array) data_get($assetVariables, 'resolved', []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = $this->variableKey($row);
            if ($key === '') {
                continue;
            }

            $row['__slot'] = $this->deriveSlotName($row, null);
            $rows[$key] = $row;
        }

        foreach ((array) data_get($assetIdentity, 'slots', []) as $slot => $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = $this->variableKey($row, (string) $slot);
            if ($key === '') {
                continue;
            }

            $base = $rows[$key] ?? [];
            $merged = is_array($base)
                ? array_replace_recursive($base, $row)
                : $row;
            $merged['__slot'] = $this->deriveSlotName($merged, (string) $slot);
            $rows[$key] = $merged;
        }

        foreach ($rows as $key => $row) {
            $identityPack = is_array($row['identity_pack'] ?? null)
                ? $row['identity_pack']
                : [];
            if ($identityPack === []) {
                $identityPack = $this->assetIdentityService->synthesizeIdentityPackFromRow($row);
            }
            $row['identity_pack'] = $identityPack;
            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $assetIdentity
     * @return array<string, int>
     */
    private function slotPriorityMap(array $rows, array $assetIdentity): array
    {
        $priority = [];
        $i = 1;

        foreach (array_keys((array) data_get($assetIdentity, 'slots', [])) as $slot) {
            $slot = trim((string) $slot);
            if ($slot === '' || isset($priority[$slot])) {
                continue;
            }

            $priority[$slot] = $i;
            $i++;
        }

        foreach ($rows as $row) {
            $slot = trim((string) ($row['__slot'] ?? $this->deriveSlotName($row, null)));
            if ($slot === '' || isset($priority[$slot])) {
                continue;
            }

            $priority[$slot] = max($i, $this->defaultSlotPriority($slot));
            $i = max($i, $priority[$slot] + 1);
        }

        if ($priority === []) {
            $priority['generic'] = 1;
        }

        return $priority;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $slotPriority
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidates(array $rows, array $slotPriority): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $identityPack = is_array($row['identity_pack'] ?? null)
                ? $row['identity_pack']
                : $this->assetIdentityService->synthesizeIdentityPackFromRow($row);
            $slot = trim((string) ($row['__slot'] ?? $this->deriveSlotName($row, null)));
            if ($slot === '') {
                $slot = 'generic';
            }

            $shotSummary = $this->shotSummaryLookup((array) data_get($row, 'profile.shot_summary', []));
            $assetPool = $this->assetPoolForRow($row, $identityPack);

            foreach ($assetPool as $asset) {
                $path = trim((string) ($asset['path'] ?? ''));
                $assetId = (int) ($asset['id'] ?? $asset['asset_id'] ?? 0);
                $key = $assetId > 0 ? 'id:' . $assetId : 'path:' . $path;
                if ($path === '' && $assetId < 1) {
                    continue;
                }

                $shot = $this->resolveShotSummary($shotSummary, $assetId, $path);
                $candidate = [
                    'key' => $key,
                    'asset_id' => $assetId > 0 ? $assetId : null,
                    'path' => $path,
                    'kind' => Str::lower(trim((string) ($asset['kind'] ?? 'image'))),
                    'original_name' => trim((string) ($asset['original_name'] ?? '')),
                    'created_at' => $this->normalizeDateValue($asset['created_at'] ?? null),
                    'updated_at' => $this->normalizeDateValue($asset['updated_at'] ?? null),
                    'variable_id' => isset($row['id']) ? (int) $row['id'] : null,
                    'variable_name' => trim((string) ($row['name'] ?? '')),
                    'identity_kind' => Str::lower(trim((string) data_get($identityPack, 'type', (string) ($row['kind'] ?? 'custom')))),
                    'identity_role' => trim((string) ($row['asset_role'] ?? '')),
                    'slot' => $slot,
                    'slot_priority' => (int) ($slotPriority[$slot] ?? $this->defaultSlotPriority($slot)),
                    'strictness_level' => Str::lower(trim((string) data_get($identityPack, 'strictness_level', (string) ($row['identity_mode'] ?? 'balanced')))),
                    'consistency_threshold' => isset($row['consistency_threshold']) ? (int) $row['consistency_threshold'] : null,
                    'is_canonical' => $this->isCanonicalAsset($row, $identityPack, $asset),
                    'is_primary_canonical' => $this->isPrimaryCanonicalAsset($row, $identityPack, $asset),
                    'shot_slot' => trim((string) ($shot['slot'] ?? $asset['slot'] ?? data_get($asset, 'meta.slot', ''))),
                    'shot_label' => trim((string) ($shot['label'] ?? $asset['label'] ?? data_get($asset, 'meta.slot_label', ''))),
                    'training_priority' => Str::lower(trim((string) ($asset['training_priority'] ?? data_get($asset, 'meta.training_priority', '')))),
                    'meta' => is_array($asset['meta'] ?? null) ? $asset['meta'] : [],
                ];

                if (isset($candidates[$key])) {
                    $candidates[$key] = $this->mergeCandidate($candidates[$key], $candidate);
                    continue;
                }

                $candidates[$key] = $candidate;
            }
        }

        return array_values($candidates);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $identityPack
     * @return array<int, array<string, mixed>>
     */
    private function assetPoolForRow(array $row, array $identityPack): array
    {
        $pool = [];

        foreach ((array) ($row['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $pool[] = $asset;
        }

        foreach ((array) data_get($identityPack, 'canonical_assets', []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $pool[] = $asset;
        }

        if ($pool === []) {
            foreach ((array) ($row['asset_paths'] ?? []) as $path) {
                $path = trim((string) $path);
                if ($path === '') {
                    continue;
                }

                $pool[] = [
                    'path' => $path,
                    'kind' => 'image',
                ];
            }
        }

        return $pool;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $historySignals
     * @return array<string, mixed>
     */
    private function scoreCandidate(
        array $candidate,
        array $historySignals,
        string $area,
        string $provider,
        string $format,
        string $platform,
        bool $strictAssetMode
    ): array {
        $path = trim((string) ($candidate['path'] ?? ''));
        $history = is_array($historySignals[$path] ?? null) ? $historySignals[$path] : [];
        $kind = trim((string) ($candidate['identity_kind'] ?? 'custom'));

        $canonicalWeight = $this->canonicalWeight($candidate);
        $recencyWeight = $this->recencyWeight($candidate);
        $qualityWeight = $this->qualityWeight($candidate);
        $historyWeight = $this->historyWeight($history);
        $providerCompatibility = $this->providerCompatibility($candidate, $area, $provider, $strictAssetMode);
        $visualConsistency = $this->visualConsistency($candidate);
        $formatSuitability = $this->formatSuitability($candidate, $format, $platform, $area);
        $weights = $this->weightProfile($kind);

        $score = (
            ($canonicalWeight * $weights['canonical_weight'])
            + ($recencyWeight * $weights['recency_weight'])
            + ($qualityWeight * $weights['quality_weight'])
            + ($historyWeight * $weights['reference_match_history'])
            + ($providerCompatibility * $weights['provider_compatibility'])
            + ($visualConsistency * $weights['visual_consistency'])
            + ($formatSuitability * $weights['format_suitability'])
        );

        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            $score = min($score, 0.18);
        }

        $candidate['scores'] = [
            'canonical_weight' => round($canonicalWeight, 4),
            'recency_weight' => round($recencyWeight, 4),
            'quality_weight' => round($qualityWeight, 4),
            'reference_match_history' => round($historyWeight, 4),
            'provider_compatibility' => round($providerCompatibility, 4),
            'visual_consistency' => round($visualConsistency, 4),
            'format_suitability' => round($formatSuitability, 4),
        ];
        $candidate['weight_profile'] = $weights;
        $candidate['score'] = round($this->clamp($score), 4);
        $candidate['history'] = $history;

        return $candidate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rankedBySlot
     * @param  array<string, int>  $slotPriority
     * @return array<string, mixed>
     */
    private function selectCandidates(
        array $rankedBySlot,
        array $slotPriority,
        string $area,
        string $provider,
        bool $strictAssetMode
    ): array {
        uksort($rankedBySlot, fn (string $a, string $b) => ($slotPriority[$a] ?? 99) <=> ($slotPriority[$b] ?? 99));

        $selected = [];
        $coveredSlots = [];
        $expectedSlots = array_values(array_keys($rankedBySlot));

        foreach ($rankedBySlot as $slot => $items) {
            foreach ($items as $candidate) {
                if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
                    continue;
                }

                $selected[] = $candidate;
                $coveredSlots[] = $slot;
                break;
            }
        }

        $selectedKeys = array_fill_keys(array_map(fn (array $row) => (string) ($row['key'] ?? ''), $selected), true);
        $maxReferenceCount = $this->maxReferenceCount($area, $provider, $selected, $strictAssetMode);
        $supportThreshold = $this->supportThreshold($strictAssetMode, $provider, $area);
        $fallbackThreshold = max(0.25, $supportThreshold - 0.16);

        $supporting = [];
        $fallback = [];
        $excluded = [];

        foreach ($rankedBySlot as $slot => $items) {
            foreach ($items as $position => $candidate) {
                $key = (string) ($candidate['key'] ?? '');
                if (isset($selectedKeys[$key])) {
                    continue;
                }

                if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
                    $excluded[] = $candidate;
                    continue;
                }

                $candidateScore = (float) ($candidate['score'] ?? 0.0);
                $isSameSlotAsSelected = in_array($slot, $coveredSlots, true);
                $isOpenAiPersonSecondary = $provider === 'openai'
                    && $area === 'video'
                    && (string) ($candidate['identity_kind'] ?? '') === 'person'
                    && !((bool) ($candidate['is_primary_canonical'] ?? false));

                if (
                    count($selected) + count($supporting) < $maxReferenceCount
                    && $candidateScore >= $supportThreshold
                    && !$isOpenAiPersonSecondary
                ) {
                    $supporting[] = $candidate;
                    continue;
                }

                if ($candidateScore >= $fallbackThreshold || (($candidate['is_canonical'] ?? false) === true)) {
                    $fallback[] = $candidate;
                    continue;
                }

                if ($isSameSlotAsSelected && $position > 0) {
                    $excluded[] = $candidate;
                    continue;
                }

                $excluded[] = $candidate;
            }
        }

        $selected = array_merge($selected, $supporting);
        usort($selected, function (array $a, array $b): int {
            $slotOrder = ((int) ($a['slot_priority'] ?? 99)) <=> ((int) ($b['slot_priority'] ?? 99));
            if ($slotOrder !== 0) {
                return $slotOrder;
            }

            return ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));
        });

        usort($fallback, fn (array $a, array $b) => $this->compareCandidates($a, $b));
        usort($excluded, fn (array $a, array $b) => $this->compareCandidates($a, $b));

        return [
            'selected' => array_slice($selected, 0, $maxReferenceCount),
            'fallback' => array_values(array_slice($this->dedupeCandidates($fallback), 0, 8)),
            'excluded' => array_values(array_slice($this->dedupeCandidates($excluded), 0, 12)),
            'covered_slots' => array_values(array_unique($coveredSlots)),
            'expected_slots' => $expectedSlots,
            'max_reference_count' => $maxReferenceCount,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $selected
     */
    private function maxReferenceCount(string $area, string $provider, array $selected, bool $strictAssetMode): int
    {
        if ($area === 'video') {
            if ($provider === 'openai') {
                $hasPerson = collect($selected)->contains(fn (array $row) => (string) ($row['identity_kind'] ?? '') === 'person');
                if ($strictAssetMode && $hasPerson) {
                    return 1;
                }

                return $hasPerson ? 2 : 2;
            }

            if ($provider === 'runway') {
                return 3;
            }

            if ($provider === 'kling') {
                return 4;
            }

            return 3;
        }

        return $strictAssetMode ? 3 : 4;
    }

    private function supportThreshold(bool $strictAssetMode, string $provider, string $area): float
    {
        $base = $strictAssetMode ? 0.66 : 0.56;

        if ($area === 'video' && $provider === 'openai') {
            $base += 0.08;
        }

        return $base;
    }

    /**
     * @param  array<int, array<string, mixed>>  $selected
     * @param  array<int, string>  $expectedSlots
     */
    private function identityConfidence(array $selected, array $expectedSlots, string $area, string $provider, bool $strictAssetMode): float
    {
        if ($selected === []) {
            return 0.0;
        }

        $avgScore = array_sum(array_map(fn (array $row) => (float) ($row['score'] ?? 0.0), $selected)) / max(1, count($selected));
        $avgProvider = array_sum(array_map(fn (array $row) => (float) data_get($row, 'scores.provider_compatibility', 0.0), $selected)) / max(1, count($selected));
        $canonicalCoverage = collect($selected)->contains(fn (array $row) => (bool) ($row['is_primary_canonical'] ?? false))
            ? 1.0
            : (collect($selected)->contains(fn (array $row) => (bool) ($row['is_canonical'] ?? false)) ? 0.82 : 0.56);
        $slotCoverage = empty($expectedSlots)
            ? 1.0
            : (count(array_unique(array_map(fn (array $row) => (string) ($row['slot'] ?? 'generic'), $selected))) / max(1, count($expectedSlots)));

        $confidence = ($avgScore * 0.55) + ($slotCoverage * 0.2) + ($avgProvider * 0.15) + ($canonicalCoverage * 0.1);

        if ($strictAssetMode && $provider === 'openai' && $area === 'video') {
            $confidence -= 0.04;
        }

        return $this->clamp($confidence);
    }

    private function compareCandidates(array $a, array $b): int
    {
        $scoreOrder = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }

        $slotOrder = ((int) ($a['slot_priority'] ?? 99)) <=> ((int) ($b['slot_priority'] ?? 99));
        if ($slotOrder !== 0) {
            return $slotOrder;
        }

        return strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function dedupeCandidates(array $items): array
    {
        $deduped = [];
        $seen = [];

        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $item;
        }

        return $deduped;
    }

    /**
     * @param  array<int, string>  $candidatePaths
     * @return array<string, array<string, mixed>>
     */
    private function buildHistorySignals(int $tenantId, int $excludeContentItemId, array $candidatePaths): array
    {
        if ($tenantId < 1 || $candidatePaths === []) {
            return [];
        }

        $pathLookup = array_fill_keys($candidatePaths, true);
        $signals = [];

        $rows = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $excludeContentItemId > 0,
                fn ($query) => $query->where('id', '!=', $excludeContentItemId)
            )
            ->whereNotNull('ai_meta')
            ->orderByDesc('ai_generated_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'ai_generated_at', 'ai_meta', 'assets']);

        foreach ($rows as $row) {
            $meta = is_array($row->ai_meta) ? $row->ai_meta : [];
            $usedPaths = array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge(
                    (array) data_get($meta, 'image_generation.brand_source_paths', []),
                    [(string) data_get($meta, 'image_generation.brand_source_path', '')],
                    (array) data_get($meta, 'video_generation.reference_paths', []),
                    [(string) data_get($meta, 'video_generation.reference_path', '')],
                    collect((array) ($row->assets ?? []))
                        ->filter(fn ($asset) => is_array($asset) && in_array(trim((string) ($asset['type'] ?? '')), ['brand_source', 'brand_video'], true))
                        ->map(fn ($asset) => (string) ($asset['path'] ?? ''))
                        ->values()
                        ->all()
                )
            ))));

            if ($usedPaths === []) {
                continue;
            }

            $referenceScore = $this->clamp((float) data_get(
                $meta,
                'quality_scorecard.reference_match_score',
                data_get(
                    $meta,
                    'image_generation.alignment_review.confidence',
                    data_get($meta, 'video_generation.reference_validation.confidence', 0.55)
                )
            ));

            foreach ($usedPaths as $path) {
                if (!isset($pathLookup[$path])) {
                    continue;
                }

                if (!isset($signals[$path])) {
                    $signals[$path] = [
                        'uses' => 0,
                        'scores' => [],
                        'last_used_at' => null,
                    ];
                }

                $signals[$path]['uses']++;
                $signals[$path]['scores'][] = $referenceScore;
                $usedAt = $row->ai_generated_at ? $row->ai_generated_at->toDateTimeString() : null;
                if ($usedAt !== null && ($signals[$path]['last_used_at'] === null || $usedAt > $signals[$path]['last_used_at'])) {
                    $signals[$path]['last_used_at'] = $usedAt;
                }
            }
        }

        $out = [];
        foreach ($signals as $path => $signal) {
            $scores = array_values(array_filter(array_map(
                fn ($value) => is_numeric($value) ? (float) $value : null,
                (array) ($signal['scores'] ?? [])
            ), fn ($value) => $value !== null));
            $lastUsedAt = is_string($signal['last_used_at'] ?? null) ? $signal['last_used_at'] : null;
            $lastUsedDays = null;
            if ($lastUsedAt !== null) {
                $timestamp = strtotime($lastUsedAt);
                if ($timestamp !== false) {
                    $lastUsedDays = max(0, (int) floor((time() - $timestamp) / 86400));
                }
            }

            $out[$path] = [
                'uses' => (int) ($signal['uses'] ?? 0),
                'average_score' => $scores === [] ? 0.55 : round(array_sum($scores) / count($scores), 4),
                'last_used_at' => $lastUsedAt,
                'last_used_days' => $lastUsedDays,
            ];
        }

        return $out;
    }

    private function canonicalWeight(array $candidate): float
    {
        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            return 0.0;
        }

        if ((bool) ($candidate['is_primary_canonical'] ?? false)) {
            return 1.0;
        }

        if ((bool) ($candidate['is_canonical'] ?? false)) {
            return 0.92;
        }

        if (trim((string) ($candidate['shot_slot'] ?? '')) !== '') {
            return 0.76;
        }

        if ((string) ($candidate['training_priority'] ?? '') === 'supporting') {
            return 0.62;
        }

        return 0.56;
    }

    private function recencyWeight(array $candidate): float
    {
        $timestamp = $this->candidateTimestamp($candidate);
        if ($timestamp === null) {
            $fallback = 0.64;
            if ((bool) ($candidate['is_canonical'] ?? false)) {
                $fallback += 0.04;
            }

            return $this->clamp($fallback);
        }

        $ageDays = max(0, (int) floor((time() - $timestamp) / 86400));

        return match (true) {
            $ageDays <= 14 => 1.0,
            $ageDays <= 45 => 0.86,
            $ageDays <= 120 => 0.72,
            $ageDays <= 240 => 0.62,
            default => 0.54,
        };
    }

    private function qualityWeight(array $candidate): float
    {
        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            return 0.05;
        }

        $meta = is_array($candidate['meta'] ?? null) ? $candidate['meta'] : [];
        $score = $this->normalizeQualityScore($meta['quality_score'] ?? $meta['quality'] ?? null) ?? 0.62;
        $sizeScore = $this->resolutionScore($meta);
        if ($sizeScore !== null) {
            $score = ($score * 0.7) + ($sizeScore * 0.3);
        }

        $source = Str::lower(trim((string) ($meta['source'] ?? '')));
        if (in_array($source, ['guided_persona_pack', 'brand_center_variable_extension', 'manual_upload'], true)) {
            $score += 0.05;
        }
        if ((string) ($candidate['training_priority'] ?? '') === 'primary') {
            $score += 0.05;
        }
        if ((bool) ($candidate['is_primary_canonical'] ?? false)) {
            $score += 0.03;
        }

        return $this->clamp($score);
    }

    private function historyWeight(array $history): float
    {
        $uses = (int) ($history['uses'] ?? 0);
        if ($uses < 1) {
            return 0.55;
        }

        $avg = $this->clamp((float) ($history['average_score'] ?? 0.55));
        $score = 0.42 + ($avg * 0.42) + min(0.16, $uses * 0.05);
        $lastUsedDays = isset($history['last_used_days']) && is_numeric($history['last_used_days'])
            ? (int) $history['last_used_days']
            : null;
        if ($lastUsedDays !== null && $lastUsedDays <= 30) {
            $score += 0.04;
        }

        return $this->clamp($score);
    }

    private function providerCompatibility(array $candidate, string $area, string $provider, bool $strictAssetMode): float
    {
        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            return 0.0;
        }

        $supportsReference = $this->capabilityRegistry->supportsReferenceAware($provider, $area);
        $supportsStrict = $this->capabilityRegistry->supportsStrictAssetMode($provider, $area);
        $score = $supportsReference ? 0.82 : 0.24;

        if ($strictAssetMode) {
            $score += $supportsStrict ? 0.08 : -0.18;
        }

        if ($area === 'video' && !$this->capabilityRegistry->supportsImageToVideo($provider)) {
            $score -= 0.25;
        }

        if ($provider === 'openai' && $area === 'video') {
            if ((string) ($candidate['identity_kind'] ?? '') === 'person') {
                $score += (bool) ($candidate['is_primary_canonical'] ?? false) ? 0.12 : -0.18;
            }
            if ((int) ($candidate['slot_priority'] ?? 99) > 2) {
                $score -= 0.04;
            }
        }

        if ($provider === 'runway' && $area === 'video') {
            $score += (bool) ($candidate['is_canonical'] ?? false) ? 0.08 : 0.02;
        }

        if ($provider === 'kling' && $area === 'video') {
            $score += trim((string) ($candidate['shot_slot'] ?? '')) !== '' ? 0.08 : 0.03;
        }

        return $this->clamp($score);
    }

    private function visualConsistency(array $candidate): float
    {
        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            return 0.05;
        }

        $meta = is_array($candidate['meta'] ?? null) ? $candidate['meta'] : [];
        $score = 0.44;

        if ((bool) ($candidate['is_primary_canonical'] ?? false)) {
            $score += 0.22;
        } elseif ((bool) ($candidate['is_canonical'] ?? false)) {
            $score += 0.16;
        }

        $variableId = (int) ($candidate['variable_id'] ?? 0);
        $linkedVariableId = (int) data_get($meta, 'linked_variable_id', 0);
        if ($variableId > 0 && $linkedVariableId === $variableId) {
            $score += 0.14;
        }

        $linkedVariableIds = collect((array) data_get($meta, 'linked_variable_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->values()
            ->all();
        if ($variableId > 0 && in_array($variableId, $linkedVariableIds, true)) {
            $score += 0.06;
        }

        $identityKind = (string) ($candidate['identity_kind'] ?? '');
        if ($identityKind !== '' && in_array($identityKind, [
            Str::lower(trim((string) data_get($meta, 'identity_kind', ''))),
            Str::lower(trim((string) data_get($meta, 'identity_pack_type', ''))),
        ], true)) {
            $score += 0.08;
        }

        if (trim((string) ($candidate['shot_slot'] ?? '')) !== '') {
            $score += 0.06;
        }

        if ((string) ($candidate['strictness_level'] ?? 'balanced') === 'strict' && !((bool) ($candidate['is_canonical'] ?? false))) {
            $score -= 0.12;
        }

        if ((string) ($candidate['training_priority'] ?? '') === 'supporting') {
            $score -= 0.04;
        }

        return $this->clamp($score);
    }

    private function formatSuitability(array $candidate, string $format, string $platform, string $area): float
    {
        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            return 0.05;
        }

        $text = $this->candidateText($candidate);
        $kind = (string) ($candidate['identity_kind'] ?? 'custom');
        $score = 0.56;

        if ($kind === 'person') {
            if ($this->containsAny($text, ['front', 'portrait', 'presenter', 'half', 'three quarter', 'three_quarter', 'bust'])) {
                $score += 0.24;
            }
            if ($area === 'video' && $this->containsAny($text, ['studio', 'podcast', 'walk', 'talk', 'action'])) {
                $score += 0.1;
            }
        }

        if ($kind === 'location') {
            if ($this->containsAny($text, ['wide', 'showroom', 'office', 'lobby', 'facade', 'exterior', 'interior', 'reception'])) {
                $score += 0.26;
            }
            if ($this->containsAny($text, ['detail', 'corner', 'close'])) {
                $score -= 0.08;
            }
        }

        if ($kind === 'product') {
            if ($this->containsAny($text, ['hero', 'pack', 'packshot', 'front', 'label', 'bottle', 'detail', 'packaging'])) {
                $score += 0.26;
            }
            if ($this->containsAny($text, ['lifestyle', 'ambient'])) {
                $score += 0.04;
            }
        }

        if (in_array($format, ['reel', 'story', 'video'], true) && $this->containsAny($text, ['wide', 'scene', 'full', 'studio'])) {
            $score += 0.08;
        }

        if ($platform === 'linkedin' && $kind === 'person' && $this->containsAny($text, ['portrait', 'presenter', 'front'])) {
            $score += 0.05;
        }

        return $this->clamp($score);
    }

    /**
     * @return array<string, float>
     */
    private function weightProfile(string $kind): array
    {
        return match ($kind) {
            'person' => [
                'canonical_weight' => 0.24,
                'recency_weight' => 0.08,
                'quality_weight' => 0.11,
                'reference_match_history' => 0.18,
                'provider_compatibility' => 0.12,
                'visual_consistency' => 0.19,
                'format_suitability' => 0.08,
            ],
            'location' => [
                'canonical_weight' => 0.22,
                'recency_weight' => 0.08,
                'quality_weight' => 0.12,
                'reference_match_history' => 0.1,
                'provider_compatibility' => 0.12,
                'visual_consistency' => 0.22,
                'format_suitability' => 0.14,
            ],
            'product' => [
                'canonical_weight' => 0.22,
                'recency_weight' => 0.08,
                'quality_weight' => 0.18,
                'reference_match_history' => 0.1,
                'provider_compatibility' => 0.1,
                'visual_consistency' => 0.18,
                'format_suitability' => 0.14,
            ],
            default => [
                'canonical_weight' => 0.18,
                'recency_weight' => 0.1,
                'quality_weight' => 0.15,
                'reference_match_history' => 0.1,
                'provider_compatibility' => 0.12,
                'visual_consistency' => 0.18,
                'format_suitability' => 0.17,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $whySelected
     * @param  array<int, string>  $whyExcluded
     * @return array<string, mixed>
     */
    private function serializeCandidate(array $candidate, string $status, array $whySelected, array $whyExcluded): array
    {
        return [
            'asset_id' => $candidate['asset_id'] ?? null,
            'path' => (string) ($candidate['path'] ?? ''),
            'kind' => (string) ($candidate['kind'] ?? ''),
            'variable_id' => $candidate['variable_id'] ?? null,
            'variable_name' => (string) ($candidate['variable_name'] ?? ''),
            'identity_kind' => (string) ($candidate['identity_kind'] ?? ''),
            'identity_role' => (string) ($candidate['identity_role'] ?? ''),
            'slot' => (string) ($candidate['slot'] ?? ''),
            'slot_priority' => (int) ($candidate['slot_priority'] ?? 99),
            'strictness_level' => (string) ($candidate['strictness_level'] ?? 'balanced'),
            'score' => round((float) ($candidate['score'] ?? 0.0), 4),
            'scores' => (array) ($candidate['scores'] ?? []),
            'weight_profile' => (array) ($candidate['weight_profile'] ?? []),
            'selection_status' => $status,
            'is_canonical' => (bool) ($candidate['is_canonical'] ?? false),
            'is_primary_canonical' => (bool) ($candidate['is_primary_canonical'] ?? false),
            'shot_slot' => (string) ($candidate['shot_slot'] ?? ''),
            'shot_label' => (string) ($candidate['shot_label'] ?? ''),
            'reference_match_history' => [
                'uses' => (int) data_get($candidate, 'history.uses', 0),
                'average_score' => (float) data_get($candidate, 'history.average_score', 0.0),
                'last_used_at' => data_get($candidate, 'history.last_used_at'),
            ],
            'why_selected' => array_values(array_unique(array_filter($whySelected))),
            'why_excluded' => array_values(array_unique(array_filter($whyExcluded))),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function selectedReasons(array $candidate, string $area, string $provider): array
    {
        $reasons = [];

        if ((bool) ($candidate['is_primary_canonical'] ?? false)) {
            $reasons[] = 'primary_canonical_anchor';
        } elseif ((bool) ($candidate['is_canonical'] ?? false)) {
            $reasons[] = 'canonical_identity_reference';
        }
        if ((float) data_get($candidate, 'scores.reference_match_history', 0.0) >= 0.74) {
            $reasons[] = 'strong_reference_match_history';
        }
        if ((float) data_get($candidate, 'scores.provider_compatibility', 0.0) >= 0.82) {
            $reasons[] = 'provider_compatible_reference';
        }
        if ((float) data_get($candidate, 'scores.format_suitability', 0.0) >= 0.76) {
            $reasons[] = match ((string) ($candidate['identity_kind'] ?? 'custom')) {
                'person' => 'person_presenter_fit',
                'location' => 'location_envelope_fit',
                'product' => 'product_packaging_fit',
                default => 'format_native_fit',
            };
        }
        if ((float) data_get($candidate, 'scores.quality_weight', 0.0) >= 0.78) {
            $reasons[] = 'high_quality_reference';
        }
        if ($provider === 'openai' && $area === 'video' && (bool) ($candidate['is_primary_canonical'] ?? false)) {
            $reasons[] = 'provider_prefers_primary_person_anchor';
        }

        return array_slice(array_values(array_unique($reasons)), 0, 4);
    }

    /**
     * @return array<int, string>
     */
    private function fallbackReasons(array $candidate, string $area, string $provider, bool $strictAssetMode): array
    {
        $reasons = [];

        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            $reasons[] = 'non_image_asset_not_eligible_for_visual_reference';
        }
        if ($strictAssetMode && !((bool) ($candidate['is_canonical'] ?? false))) {
            $reasons[] = 'strict_mode_demoted_secondary_reference';
        }
        if ($provider === 'openai' && $area === 'video' && (string) ($candidate['identity_kind'] ?? '') === 'person' && !((bool) ($candidate['is_primary_canonical'] ?? false))) {
            $reasons[] = 'provider_prefers_primary_person_anchor';
        }
        if ((float) ($candidate['score'] ?? 0.0) < $this->supportThreshold($strictAssetMode, $provider, $area)) {
            $reasons[] = 'score_below_support_threshold';
        }
        if ($reasons === []) {
            $reasons[] = 'kept_as_safe_fallback';
        }

        return array_slice(array_values(array_unique($reasons)), 0, 4);
    }

    /**
     * @return array<int, string>
     */
    private function excludedReasons(array $candidate, string $area, string $provider, bool $strictAssetMode): array
    {
        $reasons = [];

        if ((string) ($candidate['kind'] ?? 'image') !== 'image') {
            $reasons[] = 'non_image_asset_not_eligible_for_visual_reference';
        }
        if ($strictAssetMode && !((bool) ($candidate['is_canonical'] ?? false))) {
            $reasons[] = 'strict_mode_prefers_canonical_assets';
        }
        if ($provider === 'openai' && $area === 'video' && (string) ($candidate['identity_kind'] ?? '') === 'person' && !((bool) ($candidate['is_primary_canonical'] ?? false))) {
            $reasons[] = 'provider_prefers_primary_person_anchor';
        }
        if ((float) ($candidate['score'] ?? 0.0) < 0.45) {
            $reasons[] = 'low_composite_score';
        } else {
            $reasons[] = 'lower_than_primary_in_same_slot';
        }

        return array_slice(array_values(array_unique($reasons)), 0, 4);
    }

    private function variableKey(array $row, ?string $slot = null): string
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            return 'id:' . $id;
        }

        $slug = Str::lower(trim((string) ($row['slug'] ?? '')));
        if ($slug !== '') {
            return 'slug:' . $slug;
        }

        $slot = Str::lower(trim((string) $slot));

        return $slot !== '' ? 'slot:' . $slot : '';
    }

    private function deriveSlotName(array $row, ?string $slot): string
    {
        $slot = Str::lower(trim((string) $slot));
        if ($slot !== '') {
            return $slot;
        }

        $role = Str::lower(trim((string) ($row['asset_role'] ?? '')));
        $kind = Str::lower(trim((string) ($row['kind'] ?? data_get($row, 'identity_pack.type', 'custom'))));

        return match (true) {
            in_array($role, ['presenter', 'host', 'speaker'], true), $kind === 'person' => 'presenter',
            in_array($role, ['hero_product', 'product', 'sku'], true), $kind === 'product' => 'product',
            in_array($role, ['office', 'location', 'store', 'showroom', 'place'], true), $kind === 'location' => 'place',
            default => Str::lower(trim((string) ($row['slug'] ?? $row['name'] ?? 'generic'))) ?: 'generic',
        };
    }

    private function defaultSlotPriority(string $slot): int
    {
        return match (Str::lower(trim($slot))) {
            'presenter' => 1,
            'product' => 2,
            'place' => 3,
            default => 4,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $identityPack
     * @param  array<string, mixed>  $asset
     */
    private function isCanonicalAsset(array $row, array $identityPack, array $asset): bool
    {
        $assetId = (int) ($asset['id'] ?? $asset['asset_id'] ?? 0);
        $assetPath = trim((string) ($asset['path'] ?? ''));

        if ($assetId > 0 && $assetId === (int) ($row['canonical_asset_id'] ?? 0)) {
            return true;
        }
        if ($assetPath !== '' && $assetPath === trim((string) ($row['canonical_asset_path'] ?? ''))) {
            return true;
        }

        foreach ((array) data_get($identityPack, 'canonical_assets', []) as $canonical) {
            if (!is_array($canonical)) {
                continue;
            }

            if ($assetId > 0 && $assetId === (int) ($canonical['asset_id'] ?? $canonical['id'] ?? 0)) {
                return true;
            }
            if ($assetPath !== '' && $assetPath === trim((string) ($canonical['path'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $identityPack
     * @param  array<string, mixed>  $asset
     */
    private function isPrimaryCanonicalAsset(array $row, array $identityPack, array $asset): bool
    {
        $assetId = (int) ($asset['id'] ?? $asset['asset_id'] ?? 0);
        $assetPath = trim((string) ($asset['path'] ?? ''));

        if ($assetId > 0 && $assetId === (int) ($row['canonical_asset_id'] ?? 0)) {
            return true;
        }
        if ($assetPath !== '' && $assetPath === trim((string) ($row['canonical_asset_path'] ?? ''))) {
            return true;
        }

        foreach ((array) data_get($identityPack, 'canonical_assets', []) as $canonical) {
            if (!is_array($canonical)) {
                continue;
            }

            $isPrimary = (bool) ($canonical['is_primary'] ?? false);
            if (!$isPrimary) {
                continue;
            }

            if ($assetId > 0 && $assetId === (int) ($canonical['asset_id'] ?? $canonical['id'] ?? 0)) {
                return true;
            }
            if ($assetPath !== '' && $assetPath === trim((string) ($canonical['path'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $shotSummary
     * @return array<string, array<string, mixed>>
     */
    private function shotSummaryLookup(array $shotSummary): array
    {
        $lookup = [];
        foreach ($shotSummary as $shot) {
            if (!is_array($shot)) {
                continue;
            }

            $assetId = (int) ($shot['asset_id'] ?? 0);
            $path = trim((string) ($shot['path'] ?? ''));
            if ($assetId > 0) {
                $lookup['id:' . $assetId] = $shot;
            }
            if ($path !== '') {
                $lookup['path:' . $path] = $shot;
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, array<string, mixed>>  $lookup
     * @return array<string, mixed>
     */
    private function resolveShotSummary(array $lookup, int $assetId, string $path): array
    {
        if ($assetId > 0 && isset($lookup['id:' . $assetId])) {
            return $lookup['id:' . $assetId];
        }
        if ($path !== '' && isset($lookup['path:' . $path])) {
            return $lookup['path:' . $path];
        }

        return [];
    }

    private function mergeCandidate(array $current, array $incoming): array
    {
        $current['is_canonical'] = (bool) ($current['is_canonical'] ?? false) || (bool) ($incoming['is_canonical'] ?? false);
        $current['is_primary_canonical'] = (bool) ($current['is_primary_canonical'] ?? false) || (bool) ($incoming['is_primary_canonical'] ?? false);
        $current['slot_priority'] = min((int) ($current['slot_priority'] ?? 99), (int) ($incoming['slot_priority'] ?? 99));
        if (trim((string) ($current['shot_slot'] ?? '')) === '' && trim((string) ($incoming['shot_slot'] ?? '')) !== '') {
            $current['shot_slot'] = $incoming['shot_slot'];
        }
        if (trim((string) ($current['shot_label'] ?? '')) === '' && trim((string) ($incoming['shot_label'] ?? '')) !== '') {
            $current['shot_label'] = $incoming['shot_label'];
        }
        if (trim((string) ($current['created_at'] ?? '')) === '' && trim((string) ($incoming['created_at'] ?? '')) !== '') {
            $current['created_at'] = $incoming['created_at'];
        }
        if (trim((string) ($current['updated_at'] ?? '')) === '' && trim((string) ($incoming['updated_at'] ?? '')) !== '') {
            $current['updated_at'] = $incoming['updated_at'];
        }

        return $current;
    }

    private function normalizeQualityScore(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if ($numeric > 5.0) {
                return $this->clamp($numeric / 100.0);
            }
            if ($numeric > 1.0) {
                return $this->clamp($numeric / 5.0);
            }

            return $this->clamp($numeric);
        }

        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'high', 'premium', 'excellent' => 0.92,
            'medium', 'good' => 0.74,
            'low', 'weak' => 0.42,
            default => null,
        };
    }

    private function resolutionScore(array $meta): ?float
    {
        $width = (int) ($meta['width'] ?? $meta['image_width'] ?? 0);
        $height = (int) ($meta['height'] ?? $meta['image_height'] ?? 0);
        if ($width < 1 || $height < 1) {
            return null;
        }

        $pixels = $width * $height;

        return match (true) {
            $pixels >= 1920000 => 0.92,
            $pixels >= 1080000 => 0.82,
            $pixels >= 640000 => 0.72,
            default => 0.58,
        };
    }

    private function candidateText(array $candidate): string
    {
        $meta = is_array($candidate['meta'] ?? null) ? $candidate['meta'] : [];

        return Str::lower(implode(' ', array_filter([
            (string) ($candidate['shot_slot'] ?? ''),
            (string) ($candidate['shot_label'] ?? ''),
            (string) ($candidate['original_name'] ?? ''),
            basename((string) ($candidate['path'] ?? '')),
            (string) data_get($meta, 'slot', ''),
            (string) data_get($meta, 'slot_label', ''),
        ])));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveArea(array $context): string
    {
        $format = Str::lower(trim((string) ($context['format'] ?? 'post')));
        if (in_array($format, ['reel', 'story', 'video'], true)) {
            return 'video';
        }

        return 'image';
    }

    /**
     * @param  array<string, mixed>  $providerMatrix
     * @param  array<string, mixed>  $context
     */
    private function resolveProvider(array $providerMatrix, array $context, string $area): string
    {
        $preferred = trim((string) data_get($providerMatrix, $area . '.provider', ''));
        if ($preferred !== '') {
            return Str::lower($preferred);
        }

        $fallback = trim((string) ($context[$area . '_provider'] ?? ''));
        if ($fallback !== '') {
            return Str::lower($fallback);
        }

        return $this->capabilityRegistry->defaultProvider($area);
    }

    private function candidateTimestamp(array $candidate): ?int
    {
        foreach (['updated_at', 'created_at'] as $field) {
            $value = trim((string) ($candidate[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $text;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function emptySelection(string $area, string $provider, bool $strictAssetMode): array
    {
        return [
            'version' => 'asset_scoring_engine_v1',
            'selection_area' => $area,
            'provider' => $provider,
            'primary_asset' => null,
            'supporting_assets' => [],
            'excluded_assets' => [],
            'fallback_assets' => [],
            'reference_paths' => [],
            'fallback_paths' => [],
            'identity_confidence' => 0.0,
            'asset_ranking' => [],
            'selection_summary' => [
                'provider' => $provider,
                'selection_area' => $area,
                'strict_asset_mode' => $strictAssetMode,
                'selected_count' => 0,
                'fallback_count' => 0,
                'excluded_count' => 0,
                'expected_slot_count' => 0,
                'covered_slot_count' => 0,
                'covered_slots' => [],
                'max_reference_count' => 0,
            ],
        ];
    }
}
