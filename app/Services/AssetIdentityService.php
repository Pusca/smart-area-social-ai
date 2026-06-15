<?php

namespace App\Services;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssetIdentityService
{
    public function normalizeRole(string $kind, ?string $role): ?string
    {
        $normalized = trim(Str::lower((string) $role));
        if ($normalized !== '') {
            return Str::limit($normalized, 60, '');
        }

        return match (trim(Str::lower($kind))) {
            'person' => 'presenter',
            'product' => 'hero_product',
            'location' => 'office',
            default => null,
        };
    }

    public function normalizeIdentityMode(?string $value, string $fallback = 'balanced'): string
    {
        $mode = trim(Str::lower((string) $value));

        return in_array($mode, ['strict', 'balanced', 'creative'], true)
            ? $mode
            : $fallback;
    }

    public function normalizeIdentityPackType(?string $value, string $fallback = 'custom'): string
    {
        $type = trim(Str::lower((string) $value));
        if (in_array($type, ['person', 'location', 'product', 'custom'], true)) {
            return $type;
        }

        return in_array($fallback, ['person', 'location', 'product', 'custom'], true)
            ? $fallback
            : 'custom';
    }

    public function normalizeConsistencyThreshold(mixed $value, int $fallback = 85): int
    {
        $threshold = (int) $value;
        if ($threshold < 1) {
            $threshold = $fallback;
        }

        return max(50, min(99, $threshold));
    }

    /**
     * @return array<int, string>
     */
    public function parseAllowedTransforms(string|array|null $value): array
    {
        return $this->parseList($value, 10, 160);
    }

    /**
     * @return array<int, string>
     */
    public function parseList(string|array|null $value, int $limit = 8, int $itemLimit = 160): array
    {
        $items = is_array($value)
            ? $value
            : (preg_split('/[\r\n,;]+/u', trim((string) $value)) ?: []);

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $item) => $item !== '')
            ->map(fn (string $item) => Str::limit($item, $itemLimit, ''))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Costruisce un profilo strutturato per identita manuali: descrittore, blocchi fissi e cambi consentiti.
     *
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<int, BrandAsset>  $assets
     * @return array<string, mixed>
     */
    public function buildManualProfile(array $data, Collection $assets): array
    {
        $identitySummary = trim((string) ($data['description'] ?? ''));
        $descriptorSummary = trim((string) ($data['descriptor_summary'] ?? ''));
        $immutableElements = trim((string) ($data['immutable_elements'] ?? ''));
        $promptNotes = trim((string) ($data['prompt_notes'] ?? ''));
        $usageNotes = trim((string) ($data['usage_notes'] ?? ''));
        $allowedTransforms = $this->parseAllowedTransforms($data['allowed_transforms'] ?? null);
        $canonicalAssetId = (int) ($data['canonical_asset_id'] ?? 0);
        $canonicalAsset = $assets->firstWhere('id', $canonicalAssetId);

        return [
            'source_mode' => 'identity_variable',
            'identity_summary' => $identitySummary,
            'descriptor' => [
                'summary' => $descriptorSummary,
                'stable_traits' => $immutableElements,
            ],
            'prompt_lock' => [
                'immutable_elements' => $immutableElements,
                'lock_copy' => $this->buildPromptLockText($identitySummary, $immutableElements),
            ],
            'allowed_transforms' => $allowedTransforms,
            'prompt_notes' => $promptNotes,
            'usage_notes' => $usageNotes,
            'canonical_asset_id' => $canonicalAssetId > 0 ? $canonicalAssetId : null,
            'canonical_asset_path' => $canonicalAsset ? (string) $canonicalAsset->path : null,
            'canonical_asset_name' => $canonicalAsset ? (string) ($canonicalAsset->original_name ?? '') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<int, BrandAsset>  $assets
     * @return array<string, mixed>
     */
    public function buildManualIdentityPack(array $data, Collection $assets): array
    {
        $kind = $this->normalizeIdentityPackType((string) ($data['kind'] ?? 'custom'));
        $role = $this->normalizeRole($kind, (string) ($data['asset_role'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $descriptorSummary = trim((string) ($data['descriptor_summary'] ?? ''));
        $persistentLabel = trim((string) ($data['name'] ?? ''));
        $strictnessLevel = $this->normalizeIdentityMode((string) ($data['identity_mode'] ?? 'balanced'));
        $invariants = $this->parseList($data['immutable_elements'] ?? null, 10, 180);
        $transformables = $this->parseAllowedTransforms($data['allowed_transforms'] ?? null);
        $visualTags = $this->parseList($data['visual_tags'] ?? null, 10, 80);
        if ($role !== null && $role !== '') {
            $visualTags[] = $role;
        }

        return $this->buildIdentityPack(
            kind: $kind,
            strictnessLevel: $strictnessLevel,
            descriptorSummary: $descriptorSummary !== '' ? $descriptorSummary : $description,
            persistentLabel: $persistentLabel !== '' ? $persistentLabel : ($descriptorSummary !== '' ? $descriptorSummary : $description),
            invariants: $invariants,
            transformables: $transformables,
            visualTags: $visualTags,
            positiveExamples: $this->parseList($data['positive_examples'] ?? null, 8, 200),
            negativeExamples: $this->parseList($data['negative_examples'] ?? null, 8, 200),
            promptNotes: trim((string) ($data['prompt_notes'] ?? '')),
            usageNotes: trim((string) ($data['usage_notes'] ?? '')),
            canonicalAssets: $this->buildCanonicalAssets(
                row: [
                    'kind' => $kind,
                    'canonical_asset_id' => (int) ($data['canonical_asset_id'] ?? 0),
                    'asset_ids' => array_values(array_filter(array_map(
                        fn ($id) => (int) $id,
                        (array) ($data['asset_ids'] ?? [])
                    ), fn (int $id) => $id > 0)),
                ],
                assets: $assets,
                existingPack: []
            )
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BrandAsset>|null  $assets
     * @return array<string, mixed>
     */
    public function synthesizeIdentityPackForVariable(AssetVariable $variable, ?Collection $assets = null): array
    {
        $assetIds = collect((array) ($variable->asset_ids ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0);

        $canonicalAssetId = (int) ($variable->canonical_asset_id ?? 0);
        if ($canonicalAssetId > 0) {
            $assetIds->push($canonicalAssetId);
        }

        $assets = $assets ?: BrandAsset::query()
            ->where('tenant_id', (int) $variable->tenant_id)
            ->whereNull('content_plan_id')
            ->whereIn('id', $assetIds
                ->unique()
                ->values()
                ->all())
            ->get();

        return $this->synthesizeIdentityPackFromRow([
            'id' => (int) $variable->id,
            'name' => (string) $variable->name,
            'slug' => (string) $variable->slug,
            'kind' => (string) $variable->kind,
            'asset_role' => (string) ($variable->asset_role ?? ''),
            'description' => (string) ($variable->description ?? ''),
            'canonical_asset_id' => $variable->canonical_asset_id !== null ? (int) $variable->canonical_asset_id : null,
            'canonical_asset_path' => '',
            'identity_mode' => (string) ($variable->identity_mode ?? 'balanced'),
            'profile' => is_array($variable->profile) ? $variable->profile : [],
            'identity_pack' => is_array($variable->identity_pack) ? $variable->identity_pack : [],
            'assets' => $assets->map(fn (BrandAsset $asset) => [
                'id' => (int) $asset->id,
                'kind' => (string) ($asset->kind ?? ''),
                'path' => (string) ($asset->path ?? ''),
                'original_name' => (string) ($asset->original_name ?? ''),
                'mime' => (string) ($asset->mime ?? ''),
                'meta' => is_array($asset->meta) ? $asset->meta : [],
                'created_at' => $asset->created_at?->toDateTimeString(),
                'updated_at' => $asset->updated_at?->toDateTimeString(),
            ])->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function synthesizeIdentityPackFromRow(array $row): array
    {
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
        $existingPack = is_array($row['identity_pack'] ?? null) ? $row['identity_pack'] : [];
        $assets = collect((array) ($row['assets'] ?? []))
            ->map(fn ($asset) => $this->normalizeAssetRecord($asset))
            ->filter()
            ->values();

        $kind = $this->normalizeIdentityPackType(
            (string) data_get($existingPack, 'type', (string) ($row['kind'] ?? 'custom')),
            (string) ($row['kind'] ?? 'custom')
        );
        $strictnessLevel = $this->normalizeIdentityMode(
            (string) data_get($existingPack, 'strictness_level', (string) ($row['identity_mode'] ?? data_get($profile, 'identity_mode', 'balanced'))),
            (string) ($row['identity_mode'] ?? data_get($profile, 'identity_mode', 'balanced') ?: 'balanced')
        );

        $descriptorSummary = trim((string) data_get(
            $existingPack,
            'descriptor.summary',
            (string) data_get($profile, 'descriptor.summary', data_get($profile, 'identity_summary', (string) ($row['description'] ?? '')))
        ));
        $persistentLabel = trim((string) data_get(
            $existingPack,
            'descriptor.persistent_label',
            (string) ($row['name'] ?? $row['slug'] ?? '')
        ));
        if ($persistentLabel === '') {
            $persistentLabel = $descriptorSummary !== '' ? $descriptorSummary : 'identity-pack';
        }

        $invariants = collect(array_merge(
            (array) data_get($existingPack, 'invariants', []),
            $this->parseList((string) data_get($profile, 'prompt_lock.immutable_elements', ''), 10, 180),
            $this->parseList((string) data_get($profile, 'immutable_traits', ''), 10, 180)
        ))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $transformables = collect(array_merge(
            (array) data_get($existingPack, 'transformables', []),
            (array) data_get($profile, 'allowed_transforms', [])
        ))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $visualTags = collect(array_merge(
            (array) data_get($existingPack, 'visual_tags', []),
            $this->parseList((string) ($row['asset_role'] ?? ''), 4, 80),
            $this->parseList((string) data_get($profile, 'role', ''), 4, 80),
            $this->parseList((string) data_get($profile, 'look_notes', ''), 6, 80),
            $this->parseList((string) data_get($profile, 'styling_notes', ''), 6, 80)
        ))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $positiveExamples = collect((array) data_get($existingPack, 'positive_examples', []));
        if ($positiveExamples->isEmpty()) {
            $positiveExamples = collect((array) data_get($profile, 'shot_summary', []))
                ->filter(fn ($shot) => is_array($shot))
                ->map(fn (array $shot) => trim((string) ($shot['label'] ?? $shot['slot'] ?? '')))
                ->filter(fn (string $label) => $label !== '');
        }
        $positiveExamples = $positiveExamples
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $negativeExamples = collect((array) data_get($existingPack, 'negative_examples', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $canonicalAssets = $this->buildCanonicalAssets($row, $assets, $existingPack);
        $promptNotes = trim((string) data_get($existingPack, 'prompt_notes', (string) data_get($profile, 'prompt_notes', '')));
        $usageNotes = trim((string) data_get($existingPack, 'usage_notes', (string) data_get($profile, 'usage_notes', '')));
        $identitySignals = collect(array_merge(
            [$persistentLabel, $descriptorSummary],
            $invariants,
            array_slice($visualTags, 0, 4)
        ))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->take(12)
            ->values()
            ->all();

        return $this->buildIdentityPack(
            kind: $kind,
            strictnessLevel: $strictnessLevel,
            descriptorSummary: $descriptorSummary,
            persistentLabel: $persistentLabel,
            invariants: $invariants,
            transformables: $transformables,
            visualTags: $visualTags,
            positiveExamples: $positiveExamples,
            negativeExamples: $negativeExamples,
            promptNotes: $promptNotes,
            usageNotes: $usageNotes,
            canonicalAssets: $canonicalAssets,
            identitySignals: $identitySignals
        );
    }

    /**
     * Allinea i meta degli asset collegati per rendere chiaro quale identita usano e qual e l'anchor canonico.
     */
    public function syncAssetMetaForVariable(AssetVariable $variable, array $assetIds, array $extraAssetIds = []): void
    {
        $ids = collect(array_merge($assetIds, $extraAssetIds))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return;
        }

        $assets = BrandAsset::query()
            ->where('tenant_id', (int) $variable->tenant_id)
            ->whereIn('id', $ids)
            ->get();

        $identityPack = $this->synthesizeIdentityPackForVariable($variable, $assets);
        if ($variable->exists && $variable->identity_pack !== $identityPack) {
            $variable->identity_pack = $identityPack;
            $variable->saveQuietly();
        }
        $canonicalAssetIds = collect((array) data_get($identityPack, 'canonical_assets', []))
            ->map(fn ($asset) => is_array($asset) ? (int) ($asset['asset_id'] ?? 0) : 0)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        $visualTags = collect((array) data_get($identityPack, 'visual_tags', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();

        $assets->each(function (BrandAsset $asset) use ($variable, $identityPack, $canonicalAssetIds, $visualTags): void {
            $meta = is_array($asset->meta) ? $asset->meta : [];
            $linkedIds = collect((array) ($meta['linked_variable_ids'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->push((int) $variable->id)
                ->unique()
                ->values()
                ->all();
            $linkedSlugs = collect((array) ($meta['linked_variable_slugs'] ?? []))
                ->map(fn ($slug) => trim((string) $slug))
                ->filter(fn (string $slug) => $slug !== '')
                ->push((string) $variable->slug)
                ->unique()
                ->values()
                ->all();

            $meta['linked_variable_id'] = (int) $variable->id;
            $meta['linked_variable_slug'] = (string) $variable->slug;
            $meta['linked_variable_ids'] = $linkedIds;
            $meta['linked_variable_slugs'] = $linkedSlugs;
            $meta['identity_kind'] = (string) $variable->kind;
            $meta['identity_role'] = (string) ($variable->asset_role ?? '');
            $meta['identity_mode'] = (string) ($variable->identity_mode ?? 'balanced');
            $meta['identity_pack_type'] = (string) data_get($identityPack, 'type', (string) $variable->kind);
            $meta['identity_pack_strictness'] = (string) data_get($identityPack, 'strictness_level', (string) ($variable->identity_mode ?? 'balanced'));
            $meta['identity_pack_visual_tags'] = $visualTags;
            $meta['is_canonical_for_identity'] = (int) $asset->id === (int) ($variable->canonical_asset_id ?? 0);
            $meta['is_canonical_for_identity_pack'] = in_array((int) $asset->id, $canonicalAssetIds, true);

            $asset->meta = $meta;
            $asset->save();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<int, BrandAsset|array<string, mixed>>  $assets
     * @param  array<string, mixed>  $existingPack
     * @return array<int, array<string, mixed>>
     */
    private function buildCanonicalAssets(array $row, Collection $assets, array $existingPack): array
    {
        $normalizedAssets = $assets
            ->map(fn ($asset) => $this->normalizeAssetRecord($asset))
            ->filter()
            ->values();

        $assetLookup = $normalizedAssets
            ->keyBy(fn (array $asset) => (int) ($asset['id'] ?? 0));
        $pathLookup = $normalizedAssets
            ->keyBy(fn (array $asset) => (string) ($asset['path'] ?? ''));

        $ordered = [];
        foreach ((array) data_get($existingPack, 'canonical_assets', []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $assetId = (int) ($asset['asset_id'] ?? 0);
            $path = trim((string) ($asset['path'] ?? ''));
            $normalized = $assetId > 0 ? $assetLookup->get($assetId) : ($path !== '' ? $pathLookup->get($path) : null);
            if (!is_array($normalized) && ($assetId > 0 || $path !== '')) {
                $normalized = $this->normalizeAssetRecord($asset);
            }
            if (!is_array($normalized)) {
                continue;
            }

            $ordered[] = $this->decorateCanonicalAsset($normalized, $asset);
        }

        $canonicalAssetId = (int) ($row['canonical_asset_id'] ?? data_get($row, 'profile.canonical_asset_id', 0));
        if ($canonicalAssetId > 0 && $assetLookup->has($canonicalAssetId)) {
            $ordered[] = $this->decorateCanonicalAsset($assetLookup->get($canonicalAssetId), [
                'label' => 'canonical_anchor',
                'is_primary' => true,
                'source' => 'canonical_asset_id',
            ]);
        }

        foreach ((array) data_get($row, 'profile.shot_summary', []) as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $assetId = (int) ($shot['asset_id'] ?? 0);
            $path = trim((string) ($shot['path'] ?? ''));
            $normalized = $assetId > 0 ? $assetLookup->get($assetId) : ($path !== '' ? $pathLookup->get($path) : null);
            if (!is_array($normalized)) {
                continue;
            }
            $ordered[] = $this->decorateCanonicalAsset($normalized, [
                'label' => trim((string) ($shot['label'] ?? $shot['slot'] ?? 'reference')),
                'is_primary' => false,
                'source' => 'shot_summary',
            ]);
        }

        foreach ($normalizedAssets as $asset) {
            if ((string) ($asset['kind'] ?? '') !== 'image') {
                continue;
            }
            $ordered[] = $this->decorateCanonicalAsset($asset, [
                'label' => 'supporting_reference',
                'is_primary' => false,
                'source' => 'asset_pool',
            ]);
        }

        $deduped = [];
        $seen = [];
        foreach ($ordered as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $assetId = (int) ($asset['asset_id'] ?? 0);
            $path = trim((string) ($asset['path'] ?? ''));
            $key = $assetId > 0 ? 'id:' . $assetId : 'path:' . $path;
            if ($key === 'path:' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $asset;
        }

        return array_slice($deduped, 0, 6);
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function decorateCanonicalAsset(array $asset, array $context): array
    {
        return [
            'asset_id' => (int) ($asset['id'] ?? 0),
            'kind' => (string) ($asset['kind'] ?? ''),
            'path' => (string) ($asset['path'] ?? ''),
            'original_name' => (string) ($asset['original_name'] ?? ''),
            'mime' => (string) ($asset['mime'] ?? ''),
            'meta' => is_array($asset['meta'] ?? null) ? $asset['meta'] : [],
            'created_at' => $this->normalizeDateValue($asset['created_at'] ?? null),
            'updated_at' => $this->normalizeDateValue($asset['updated_at'] ?? null),
            'label' => trim((string) ($context['label'] ?? 'reference')),
            'is_primary' => (bool) ($context['is_primary'] ?? false),
            'source' => (string) ($context['source'] ?? 'asset_pool'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeAssetRecord(mixed $asset): ?array
    {
        if ($asset instanceof BrandAsset) {
            return [
                'id' => (int) $asset->id,
                'kind' => (string) ($asset->kind ?? ''),
                'path' => (string) ($asset->path ?? ''),
                'original_name' => (string) ($asset->original_name ?? ''),
                'mime' => (string) ($asset->mime ?? ''),
                'meta' => is_array($asset->meta) ? $asset->meta : [],
            ];
        }

        if (!is_array($asset)) {
            return null;
        }

        $path = trim((string) ($asset['path'] ?? ''));
        $assetId = (int) ($asset['id'] ?? $asset['asset_id'] ?? 0);
        if ($assetId < 1 && $path === '') {
            return null;
        }

        return [
            'id' => $assetId > 0 ? $assetId : null,
            'kind' => (string) ($asset['kind'] ?? ''),
            'path' => $path,
            'original_name' => (string) ($asset['original_name'] ?? ''),
            'mime' => (string) ($asset['mime'] ?? ''),
            'meta' => is_array($asset['meta'] ?? null) ? $asset['meta'] : [],
            'created_at' => $this->normalizeDateValue($asset['created_at'] ?? null),
            'updated_at' => $this->normalizeDateValue($asset['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<int, string>  $invariants
     * @param  array<int, string>  $transformables
     * @param  array<int, string>  $visualTags
     * @param  array<int, string>  $positiveExamples
     * @param  array<int, string>  $negativeExamples
     * @param  array<int, array<string, mixed>>  $canonicalAssets
     * @param  array<int, string>|null  $identitySignals
     * @return array<string, mixed>
     */
        private function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function buildIdentityPack(
        string $kind,
        string $strictnessLevel,
        string $descriptorSummary,
        string $persistentLabel,
        array $invariants,
        array $transformables,
        array $visualTags,
        array $positiveExamples,
        array $negativeExamples,
        string $promptNotes,
        string $usageNotes,
        array $canonicalAssets,
        ?array $identitySignals = null
    ): array {
        return [
            'version' => 'identity_pack_v1',
            'type' => $kind,
            'strictness_level' => $strictnessLevel,
            'descriptor' => [
                'summary' => $descriptorSummary,
                'persistent_label' => $persistentLabel,
            ],
            'canonical_assets' => $canonicalAssets,
            'invariants' => array_values($invariants),
            'transformables' => array_values($transformables),
            'visual_tags' => array_values(array_unique(array_filter($visualTags))),
            'positive_examples' => array_values($positiveExamples),
            'negative_examples' => array_values($negativeExamples),
            'prompt_notes' => $promptNotes,
            'usage_notes' => $usageNotes,
            'identity_signals' => array_values($identitySignals ?: array_unique(array_filter(array_merge(
                [$persistentLabel, $descriptorSummary],
                $invariants,
                array_slice($visualTags, 0, 4)
            )))),
        ];
    }

    private function buildPromptLockText(string $identitySummary, string $immutableElements): string
    {
        $parts = [];

        if ($identitySummary !== '') {
            $parts[] = 'Mantieni riconoscibile questa identita: ' . Str::limit($identitySummary, 180, '');
        }

        if ($immutableElements !== '') {
            $parts[] = 'Non cambiare: ' . Str::limit($immutableElements, 220, '');
        }

        return trim(implode('. ', $parts));
    }
}




