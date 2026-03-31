<?php

namespace App\Services\Canva;

use App\Models\CanvaTemplateMapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class CanvaTemplateMappingService
{
    public function __construct(
        private readonly CanvaBridgeService $bridge,
        private readonly CanvaTemplateCatalogService $catalogService,
        private readonly CanvaTokenService $tokenService,
        private readonly CanvaApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function workflowOptions(): array
    {
        return (array) config('canva.workflows', []);
    }

    /**
     * @return Collection<int, CanvaTemplateMapping>
     */
    public function mappingsForTenant(int $tenantId): Collection
    {
        return CanvaTemplateMapping::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('channel_format')
            ->get();
    }

    public function mappingForTenant(int $tenantId, string $channelFormat): ?CanvaTemplateMapping
    {
        return CanvaTemplateMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('channel_format', $channelFormat)
            ->first();
    }

    public function saveMapping(int $tenantId, string $channelFormat, ?string $templateId): CanvaTemplateMapping
    {
        if (!array_key_exists($channelFormat, $this->workflowOptions())) {
            throw new RuntimeException('Unsupported Canva workflow: ' . $channelFormat);
        }

        $templateId = trim((string) $templateId);
        $existing = $this->mappingForTenant($tenantId, $channelFormat);

        if ($templateId === '') {
            return CanvaTemplateMapping::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'channel_format' => $channelFormat],
                [
                    'canva_template_id' => null,
                    'canva_template_name' => null,
                    'dataset_schema_json' => [],
                    'mapping_rules_json' => [],
                    'status' => 'inactive',
                    'canva_view_url' => null,
                    'canva_create_url' => null,
                    'last_synced_at' => Carbon::now(),
                    'meta' => array_merge((array) ($existing?->meta ?? []), [
                        'deactivated_at' => Carbon::now()->toDateTimeString(),
                    ]),
                ]
            );
        }

        $connection = $this->bridge->requireActiveConnection($tenantId);
        $previewItem = collect($this->catalogService->previewForTenant($tenantId))
            ->first(fn (array $item): bool => (string) ($item['id'] ?? '') === $templateId);

        $datasetPayload = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->getBrandTemplateDataset($accessToken, $templateId));
        $dataset = (array) data_get($datasetPayload, 'dataset', []);
        $mappingRules = [
            'field_map' => $this->inferFieldMap($dataset),
        ];

        return CanvaTemplateMapping::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'channel_format' => $channelFormat],
            [
                'canva_template_id' => $templateId,
                'canva_template_name' => (string) ($previewItem['title'] ?? $existing?->canva_template_name ?? $templateId),
                'dataset_schema_json' => $dataset,
                'mapping_rules_json' => $mappingRules,
                'status' => 'active',
                'canva_view_url' => (string) ($previewItem['view_url'] ?? $existing?->canva_view_url),
                'canva_create_url' => (string) ($previewItem['create_url'] ?? $existing?->canva_create_url),
                'last_synced_at' => Carbon::now(),
                'meta' => [
                    'template_preview' => $previewItem,
                ],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $designPayload
     * @return array<string, mixed>
     */
    public function mapPayloadToDataset(CanvaTemplateMapping $mapping, array $designPayload): array
    {
        $dataset = (array) ($mapping->dataset_schema_json ?? []);
        $fieldMap = (array) data_get($mapping->mapping_rules_json, 'field_map', []);

        $expandedPayload = array_merge($designPayload, [
            'primary_image' => data_get($designPayload, 'primary_image', data_get($designPayload, 'selected_images.0')),
            'logo' => data_get($designPayload, 'logo'),
        ]);

        $output = [];

        foreach ($dataset as $fieldName => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $type = strtolower(trim((string) ($definition['type'] ?? '')));
            $source = (string) ($fieldMap[$fieldName] ?? $this->resolveAutofillSourceForFieldName($fieldName));
            $value = data_get($expandedPayload, $source);

            if ($type === 'text') {
                $text = is_array($value) ? trim((string) ($value['text'] ?? $value['label'] ?? '')) : trim((string) $value);
                if ($text !== '') {
                    $output[$fieldName] = [
                        'type' => 'text',
                        'text' => $text,
                    ];
                }
                continue;
            }

            if ($type === 'image') {
                $assetId = trim((string) data_get($value, 'canva_asset_id', is_string($value) ? $value : ''));
                if ($assetId !== '') {
                    $output[$fieldName] = [
                        'type' => 'image',
                        'asset_id' => $assetId,
                    ];
                }
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, string>
     */
    private function inferFieldMap(array $dataset): array
    {
        $fieldMap = [];

        foreach ($dataset as $fieldName => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $type = strtolower(trim((string) ($definition['type'] ?? '')));
            $normalized = Str::of($fieldName)->lower()->replace(['-', ' '], '_')->value();

            $fieldMap[$fieldName] = match (true) {
                $type === 'image' && str_contains($normalized, 'logo') => 'logo',
                $type === 'image' => 'primary_image',
                str_contains($normalized, 'headline'), str_contains($normalized, 'title'), str_contains($normalized, 'hook') => 'headline',
                str_contains($normalized, 'sub'), str_contains($normalized, 'kicker'), str_contains($normalized, 'eyebrow') => 'subheadline',
                str_contains($normalized, 'cta'), str_contains($normalized, 'button'), str_contains($normalized, 'action') => 'cta',
                str_contains($normalized, 'claim'), str_contains($normalized, 'tagline'), str_contains($normalized, 'promise') => 'brand_claim',
                default => 'body',
            };
        }

        return $fieldMap;
    }

    private function resolveAutofillSourceForFieldName(string $fieldName): string
    {
        $normalized = Str::of($fieldName)->lower()->replace(['-', ' '], '_')->value();

        return match (true) {
            str_contains($normalized, 'logo') => 'logo',
            str_contains($normalized, 'image'), str_contains($normalized, 'photo'), str_contains($normalized, 'hero'), str_contains($normalized, 'background') => 'primary_image',
            str_contains($normalized, 'headline'), str_contains($normalized, 'title'), str_contains($normalized, 'hook') => 'headline',
            str_contains($normalized, 'sub'), str_contains($normalized, 'kicker') => 'subheadline',
            str_contains($normalized, 'cta'), str_contains($normalized, 'button') => 'cta',
            str_contains($normalized, 'claim'), str_contains($normalized, 'tagline') => 'brand_claim',
            default => 'body',
        };
    }
}
