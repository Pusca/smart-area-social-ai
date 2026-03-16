<?php

namespace App\Services;

use App\Models\BrandAsset;
use App\Models\AssetVariable;
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

    public function normalizeConsistencyThreshold(mixed $value, int $fallback = 85): int
    {
        $threshold = (int) $value;
        if ($threshold < 1) {
            $threshold = $fallback;
        }

        return max(50, min(99, $threshold));
    }

    /**
     * Converte una textarea libera in un elenco stabile di trasformazioni ammesse.
     *
     * @return array<int, string>
     */
    public function parseAllowedTransforms(string|array|null $value): array
    {
        $items = is_array($value)
            ? $value
            : (preg_split('/[\r\n,;]+/u', trim((string) $value)) ?: []);

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $item) => $item !== '')
            ->map(fn (string $item) => Str::limit($item, 160, ''))
            ->unique()
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
     * Allinea i meta degli asset collegati per rendere chiaro quale identita usano e qual e l'anchor canonico.
     */
    public function syncAssetMetaForVariable(AssetVariable $variable, array $assetIds): void
    {
        $ids = collect($assetIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return;
        }

        BrandAsset::query()
            ->where('tenant_id', (int) $variable->tenant_id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (BrandAsset $asset) use ($variable): void {
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

                // Manteniamo anche i campi legacy singoli per non rompere il codice esistente.
                $meta['linked_variable_id'] = (int) $variable->id;
                $meta['linked_variable_slug'] = (string) $variable->slug;
                $meta['linked_variable_ids'] = $linkedIds;
                $meta['linked_variable_slugs'] = $linkedSlugs;
                $meta['identity_kind'] = (string) $variable->kind;
                $meta['identity_role'] = (string) ($variable->asset_role ?? '');
                $meta['identity_mode'] = (string) ($variable->identity_mode ?? 'balanced');
                $meta['is_canonical_for_identity'] = (int) $asset->id === (int) ($variable->canonical_asset_id ?? 0);

                $asset->meta = $meta;
                $asset->save();
            });
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
