<?php

namespace App\Services;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use Illuminate\Support\Str;

class AssetVariableService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalogForTenant(int $tenantId): array
    {
        $variables = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();

        if ($variables->isEmpty()) {
            return [];
        }

        $allAssetIds = $variables
            ->flatMap(fn (AssetVariable $row) => (array) ($row->asset_ids ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->whereIn('id', $allAssetIds)
            // I meta servono per capire se un asset e anchor canonico o parte di un pack guidato.
            ->get(['id', 'kind', 'path', 'original_name', 'mime', 'meta'])
            ->keyBy('id');

        $out = [];
        foreach ($variables as $variable) {
            $assetIds = collect((array) ($variable->asset_ids ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values();

            $linkedAssets = $assetIds
                ->map(fn (int $id) => $assets->get($id))
                ->filter()
                ->values();

            $assetPaths = $linkedAssets
                ->map(fn ($asset) => (string) ($asset->path ?? ''))
                ->filter(fn (string $path) => $path !== '')
                ->values()
                ->all();

            $canonicalAssetId = (int) ($variable->canonical_asset_id ?? 0);
            $canonicalAsset = $canonicalAssetId > 0 ? $assets->get($canonicalAssetId) : null;

            $out[] = [
                'id' => (int) $variable->id,
                'name' => (string) $variable->name,
                'slug' => (string) $variable->slug,
                'kind' => (string) $variable->kind,
                'asset_role' => (string) ($variable->asset_role ?? ''),
                'description' => (string) ($variable->description ?? ''),
                'canonical_asset_id' => $canonicalAssetId > 0 ? $canonicalAssetId : null,
                'canonical_asset_path' => $canonicalAsset ? (string) ($canonicalAsset->path ?? '') : '',
                'identity_mode' => (string) ($variable->identity_mode ?? 'balanced'),
                'consistency_threshold' => $variable->consistency_threshold !== null
                    ? (int) $variable->consistency_threshold
                    : null,
                'profile' => is_array($variable->profile) ? $variable->profile : [],
                'asset_ids' => $assetIds->all(),
                'asset_paths' => $assetPaths,
                'assets' => $linkedAssets->map(fn ($asset) => [
                    'id' => (int) ($asset->id ?? 0),
                    'kind' => (string) ($asset->kind ?? ''),
                    'path' => (string) ($asset->path ?? ''),
                    'original_name' => (string) ($asset->original_name ?? ''),
                    'mime' => (string) ($asset->mime ?? ''),
                    'meta' => is_array($asset->meta) ? $asset->meta : [],
                ])->all(),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, int|string>  $requestedIds
     * @return array<string, mixed>
     */
    public function resolveForBrief(int $tenantId, string $brief, array $requestedIds = []): array
    {
        $catalog = $this->catalogForTenant($tenantId);
        $byId = collect($catalog)->keyBy(fn ($v) => (int) ($v['id'] ?? 0));
        $normalizedBrief = $this->normalize($brief);

        $requested = collect($requestedIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $requestedResolved = $requested
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->values();

        $detectedResolved = collect();
        $recognizedTerms = [];

        foreach ($catalog as $variable) {
            if (!$this->variableMatchesBrief($normalizedBrief, $variable)) {
                continue;
            }

            $detectedResolved->push($variable);
            $recognizedTerms[] = (string) ($variable['name'] ?? '');
        }

        $resolved = $requestedResolved
            ->concat($detectedResolved)
            ->filter()
            ->unique(fn ($v) => (int) ($v['id'] ?? 0))
            ->values();

        $resolvedIds = $resolved->map(fn ($v) => (int) ($v['id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $resolvedAssetIds = $resolved
            ->flatMap(fn ($v) => (array) ($v['asset_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $resolvedAssetPaths = $resolved
            ->flatMap(fn ($v) => (array) ($v['asset_paths'] ?? []))
            ->map(fn ($path) => (string) $path)
            ->filter(fn (string $path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'catalog' => $catalog,
            'requested_ids' => $requested->all(),
            'detected_ids' => $detectedResolved
                ->map(fn ($v) => (int) ($v['id'] ?? 0))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all(),
            'resolved_ids' => $resolvedIds,
            'resolved_asset_ids' => $resolvedAssetIds,
            'resolved_asset_paths' => $resolvedAssetPaths,
            'resolved' => $resolved->all(),
            'recognized_terms' => array_values(array_unique(array_filter($recognizedTerms))),
            'selection_mode' => $this->selectionMode(
                $requestedResolved->isNotEmpty(),
                $detectedResolved->isNotEmpty()
            ),
        ];
    }

    /**
     * @param  array<int, int|string>  $assetIds
     * @return array<int, int>
     */
    public function sanitizeAssetIdsForTenant(int $tenantId, array $assetIds): array
    {
        $ids = collect($assetIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return [];
        }

        return BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function buildUniqueSlugForTenant(int $tenantId, string $name, ?int $excludeId = null): string
    {
        $base = Str::slug(Str::lower(trim($name)));
        if ($base === '') {
            $base = 'var';
        }
        $base = Str::limit($base, 120, '');
        $slug = $base;
        $i = 2;

        while ($this->slugExists($tenantId, $slug, $excludeId)) {
            $suffix = '-' . $i;
            $slug = Str::limit($base, 120 - strlen($suffix), '') . $suffix;
            $i++;
            if ($i > 999) {
                break;
            }
        }

        return $slug;
    }

    private function slugExists(int $tenantId, string $slug, ?int $excludeId = null): bool
    {
        $query = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug);

        if ($excludeId && $excludeId > 0) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    private function selectionMode(bool $hasRequested, bool $hasDetected): string
    {
        if ($hasRequested && $hasDetected) {
            return 'manual+brief';
        }
        if ($hasRequested) {
            return 'manual';
        }
        if ($hasDetected) {
            return 'brief';
        }
        return 'none';
    }

    private function containsToken(string $haystackNormalized, string $tokenNormalized): bool
    {
        $haystack = trim($haystackNormalized);
        $token = trim($tokenNormalized);
        if ($haystack === '' || $token === '') {
            return false;
        }

        if (str_contains(' ' . $haystack . ' ', ' ' . $token . ' ')) {
            return true;
        }

        return str_contains($haystack, '@' . str_replace(' ', '', $token));
    }

    /**
     * Oltre a nome e slug, usiamo ruolo e descrittori per riconoscere identita nel brief.
     *
     * @param  array<string, mixed>  $variable
     */
    private function variableMatchesBrief(string $normalizedBrief, array $variable): bool
    {
        if ($normalizedBrief === '') {
            return false;
        }

        $tokens = [
            $this->normalize((string) ($variable['name'] ?? '')),
            $this->normalize(str_replace('-', ' ', (string) ($variable['slug'] ?? ''))),
            $this->normalize((string) ($variable['asset_role'] ?? '')),
            $this->normalize((string) ($variable['description'] ?? '')),
            $this->normalize((string) data_get($variable, 'profile.role', '')),
            $this->normalize((string) data_get($variable, 'profile.identity_summary', '')),
            $this->normalize((string) data_get($variable, 'profile.descriptor.summary', '')),
            $this->normalize((string) data_get($variable, 'profile.prompt_lock.immutable_elements', '')),
        ];

        foreach ($this->allowedTransformTokens($variable) as $token) {
            $tokens[] = $this->normalize($token);
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($this->containsToken($normalizedBrief, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $variable
     * @return array<int, string>
     */
    private function allowedTransformTokens(array $variable): array
    {
        return collect((array) data_get($variable, 'profile.allowed_transforms', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = Str::of($value)->lower()->ascii()->toString();
        $value = preg_replace('/[^a-z0-9\s@_-]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return trim($value);
    }
}
