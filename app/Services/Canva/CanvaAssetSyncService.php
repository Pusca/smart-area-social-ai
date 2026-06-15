<?php

namespace App\Services\Canva;

use App\Models\BrandAsset;
use App\Models\CanvaAssetMapping;
use App\Models\ContentItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CanvaAssetSyncService
{
    public function __construct(
        private readonly CanvaBridgeService $bridge,
        private readonly CanvaTokenService $tokenService,
        private readonly CanvaApiClient $apiClient
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function prepareContentItemBundle(ContentItem $item, array $options = []): array
    {
        $bundle = [
            'logo' => null,
            'primary_image' => null,
            'selected_images' => [],
        ];

        if ((bool) ($options['include_logo'] ?? true)) {
            $bundle['logo'] = $this->syncTenantLogo((int) $item->tenant_id);
        }

        if ((bool) ($options['include_generated_visual'] ?? true)) {
            $primary = $this->syncContentItemImage($item);
            if ($primary !== null) {
                $bundle['primary_image'] = $primary;
                $bundle['selected_images'][] = $primary;
            }
        }

        return $bundle;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function syncTenantLogo(int $tenantId): ?array
    {
        $logo = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'logo')
            ->orderByDesc('id')
            ->first();

        return $logo ? $this->syncBrandAsset($logo) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function syncBrandAsset(BrandAsset $asset): ?array
    {
        $path = trim((string) $asset->path);
        if ($path === '' || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $existing = CanvaAssetMapping::query()
            ->where('tenant_id', $asset->tenant_id)
            ->where('brand_asset_id', $asset->id)
            ->where('source_path', $path)
            ->where('sync_status', 'success')
            ->whereNotNull('canva_asset_id')
            ->first();

        if ($existing) {
            return $this->mappingToArray($existing);
        }

        $bytes = Storage::disk('public')->get($path);
        $fileName = trim((string) ($asset->original_name ?: basename($path)));

        return $this->syncBinaryAsset(
            tenantId: (int) $asset->tenant_id,
            fileName: $fileName !== '' ? $fileName : basename($path),
            binary: $bytes,
            assetKind: (string) ($asset->kind ?: 'image'),
            sourcePath: $path,
            brandAssetId: (int) $asset->id,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function syncContentItemImage(ContentItem $item): ?array
    {
        $path = trim((string) ($item->ai_image_path ?? ''));
        if ($path === '' || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $existing = CanvaAssetMapping::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('content_item_id', $item->id)
            ->where('source_path', $path)
            ->where('sync_status', 'success')
            ->whereNotNull('canva_asset_id')
            ->first();

        if ($existing) {
            return $this->mappingToArray($existing);
        }

        $bytes = Storage::disk('public')->get($path);
        $fileName = basename($path) ?: ('content-item-' . $item->id . '.png');

        return $this->syncBinaryAsset(
            tenantId: (int) $item->tenant_id,
            fileName: $fileName,
            binary: $bytes,
            assetKind: 'generated_visual',
            sourcePath: $path,
            contentItemId: (int) $item->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function syncBinaryAsset(
        int $tenantId,
        string $fileName,
        string $binary,
        string $assetKind,
        string $sourcePath,
        ?int $brandAssetId = null,
        ?int $contentItemId = null
    ): array {
        $connection = $this->bridge->requireActiveConnection($tenantId);
        $mapping = CanvaAssetMapping::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'brand_asset_id' => $brandAssetId,
            'content_item_id' => $contentItemId,
            'source_path' => $sourcePath,
        ]);
        $mapping->asset_kind = $assetKind;
        $mapping->sync_status = 'uploading';
        $mapping->save();

        try {
            $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->createAssetUploadJob($accessToken, $fileName, $binary));

            $job = (array) data_get($response, 'job', []);
            $status = (string) ($job['status'] ?? 'in_progress');
            $attempts = max(1, (int) config('canva.asset_upload_poll_attempts', 5));

            while ($status === 'in_progress' && $attempts > 0) {
                usleep(max(100, (int) config('canva.asset_upload_poll_sleep_ms', 800)) * 1000);
                $jobId = trim((string) ($job['id'] ?? ''));
                if ($jobId === '') {
                    break;
                }

                $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->getAssetUploadJob($accessToken, $jobId));
                $job = (array) data_get($response, 'job', []);
                $status = (string) ($job['status'] ?? $status);
                $attempts--;
            }

            if ($status !== 'success') {
                throw new RuntimeException((string) data_get($job, 'error.message', 'Canva asset upload did not complete successfully.'));
            }

            $mapping->canva_asset_id = trim((string) data_get($job, 'asset.id'));
            $mapping->sync_status = 'success';
            $mapping->synced_at = now();
            $mapping->meta = [
                'job' => $job,
            ];
            $mapping->save();

            return $this->mappingToArray($mapping);
        } catch (\Throwable $e) {
            Log::warning('canva.asset_sync_failed', [
                'tenant_id' => $tenantId,
                'source_path' => $sourcePath,
                'message' => $e->getMessage(),
            ]);

            $mapping->sync_status = 'failed';
            $mapping->meta = array_merge((array) ($mapping->meta ?? []), [
                'error' => $e->getMessage(),
            ]);
            $mapping->save();

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mappingToArray(CanvaAssetMapping $mapping): array
    {
        return [
            'canva_asset_id' => (string) ($mapping->canva_asset_id ?? ''),
            'asset_kind' => (string) ($mapping->asset_kind ?? ''),
            'source_path' => (string) ($mapping->source_path ?? ''),
            'brand_asset_id' => $mapping->brand_asset_id,
            'content_item_id' => $mapping->content_item_id,
        ];
    }
}
