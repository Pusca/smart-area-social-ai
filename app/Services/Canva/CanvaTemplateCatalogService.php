<?php

namespace App\Services\Canva;

use Illuminate\Support\Carbon;
use RuntimeException;

class CanvaTemplateCatalogService
{
    public function __construct(
        private readonly CanvaBridgeService $bridge,
        private readonly CanvaTokenService $tokenService,
        private readonly CanvaApiClient $apiClient
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refreshCatalogForTenant(int $tenantId, string $query = '', ?int $limit = null): array
    {
        $connection = $this->bridge->requireActiveConnection($tenantId);
        $capabilities = array_values(array_filter(array_map('strval', (array) ($connection->capabilities ?? []))));

        if (!in_array('brand_template', $capabilities, true)) {
            throw new RuntimeException('Canva account does not support brand template discovery.');
        }

        $response = $this->tokenService->withAccessToken($connection, function (string $accessToken) use ($query, $limit): array {
            return $this->apiClient->listBrandTemplates($accessToken, array_filter([
                'limit' => $limit ?: (int) config('canva.catalog_preview_limit', 12),
                'query' => trim($query) !== '' ? trim($query) : null,
                'dataset' => 'any',
            ], fn ($value) => $value !== null && $value !== ''));
        });

        $items = collect((array) ($response['items'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row): array => [
                'id' => trim((string) ($row['id'] ?? '')),
                'title' => trim((string) ($row['title'] ?? 'Template')),
                'view_url' => trim((string) ($row['view_url'] ?? '')),
                'create_url' => trim((string) ($row['create_url'] ?? '')),
                'thumbnail_url' => trim((string) data_get($row, 'thumbnail.url', '')),
                'updated_at' => data_get($row, 'updated_at'),
                'created_at' => data_get($row, 'created_at'),
            ])
            ->filter(fn (array $row) => $row['id'] !== '')
            ->values()
            ->all();

        $meta = (array) ($connection->meta ?? []);
        $meta['catalog_preview'] = [
            'items' => $items,
            'continuation' => data_get($response, 'continuation'),
            'refreshed_at' => Carbon::now()->toDateTimeString(),
        ];
        $connection->meta = $meta;
        $connection->last_synced_at = Carbon::now();
        $connection->save();

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function previewForTenant(int $tenantId): array
    {
        $summary = $this->bridge->connectionSummary($tenantId);

        return array_values(array_filter((array) ($summary['catalog_preview'] ?? []), fn ($row) => is_array($row)));
    }
}
