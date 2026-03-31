<?php

namespace App\Services\Canva;

use App\DTO\CanvaDesignPayload;
use App\Jobs\PollCanvaDesignAutofillJob;
use App\Models\CanvaDesign;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CanvaDesignGenerationService
{
    public function __construct(
        private readonly CanvaBridgeService $bridge,
        private readonly CanvaAssetSyncService $assetSyncService,
        private readonly CanvaTemplateMappingService $templateMappingService,
        private readonly CanvaApiClient $apiClient,
        private readonly CanvaTokenService $tokenService
    ) {
    }

    public function resolveChannelFormat(ContentItem $item): string
    {
        $format = Str::lower(trim((string) $item->format));

        return match (true) {
            str_contains($format, 'carousel') => 'carousel',
            str_contains($format, 'story') => 'story',
            str_contains($format, 'presentation'), str_contains($format, 'deck') => 'investor_presentation',
            default => 'instagram_post',
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createFromContentItem(ContentItem $item, ?string $channelFormat = null, array $options = []): CanvaDesign
    {
        $connection = $this->bridge->requireActiveConnection((int) $item->tenant_id);
        $channelFormat = $channelFormat ?: $this->resolveChannelFormat($item);
        $payload = $this->buildPayloadFromContentItem($item, $channelFormat);
        try {
            $assetBundle = $this->assetSyncService->prepareContentItemBundle($item, $options);
        } catch (\Throwable $e) {
            Log::warning('canva.design.asset_bundle_failed', [
                'tenant_id' => $item->tenant_id,
                'content_item_id' => $item->id,
                'message' => $e->getMessage(),
            ]);

            $assetBundle = [
                'logo' => null,
                'primary_image' => null,
                'selected_images' => [],
                'error' => $e->getMessage(),
            ];
        }
        $mapping = $this->templateMappingService->mappingForTenant((int) $item->tenant_id, $channelFormat);

        $payloadArray = array_merge($payload->toArray(), [
            'primary_image' => $assetBundle['primary_image'] ?? null,
            'logo' => $assetBundle['logo'] ?? null,
        ]);

        $supportsAutofill = $mapping
            && $mapping->status === 'active'
            && !empty((array) ($mapping->dataset_schema_json ?? []))
            && in_array('autofill', (array) ($connection->capabilities ?? []), true)
            && in_array('brand_template', (array) ($connection->capabilities ?? []), true);

        $autofillData = $supportsAutofill
            ? $this->templateMappingService->mapPayloadToDataset($mapping, $payloadArray)
            : [];

        if ($supportsAutofill && $autofillData !== []) {
            $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->createAutofillJob(
                $accessToken,
                (string) $mapping->canva_template_id,
                $autofillData,
                $payload->headline
            ));

            $design = CanvaDesign::query()->create([
                'tenant_id' => (int) $item->tenant_id,
                'content_item_id' => (int) $item->id,
                'content_plan_id' => $item->content_plan_id ? (int) $item->content_plan_id : null,
                'canva_template_mapping_id' => (int) $mapping->id,
                'design_type' => $channelFormat,
                'canva_edit_url' => $mapping->canva_create_url,
                'canva_view_url' => $mapping->canva_view_url,
                'template_id' => (string) $mapping->canva_template_id,
                'source_mode' => 'autofill',
                'generation_payload_json' => [
                    'design_payload' => $payloadArray,
                    'asset_bundle' => $assetBundle,
                    'autofill_data' => $autofillData,
                ],
                'status' => (string) data_get($response, 'job.status', 'autofill_pending'),
                'meta' => [
                    'autofill_job' => (array) data_get($response, 'job', []),
                    'template_name' => (string) $mapping->canva_template_name,
                ],
            ]);

            $this->storeContentItemSnapshot($item, $design);
            PollCanvaDesignAutofillJob::dispatch((int) $design->id);

            return $design->fresh();
        }

        $design = CanvaDesign::query()->create([
            'tenant_id' => (int) $item->tenant_id,
            'content_item_id' => (int) $item->id,
            'content_plan_id' => $item->content_plan_id ? (int) $item->content_plan_id : null,
            'canva_template_mapping_id' => $mapping?->id,
            'design_type' => $channelFormat,
            'canva_edit_url' => $mapping?->canva_create_url ?: (string) config('canva.manual_editor_url'),
            'canva_view_url' => $mapping?->canva_view_url,
            'template_id' => $mapping?->canva_template_id,
            'source_mode' => 'fallback_manual',
            'generation_payload_json' => [
                'design_payload' => $payloadArray,
                'asset_bundle' => $assetBundle,
                'manual_handoff' => true,
            ],
            'status' => 'manual_handoff_ready',
            'meta' => [
                'reason' => $mapping ? 'autofill_unavailable_or_dataset_empty' : 'no_template_mapping',
                'template_name' => $mapping?->canva_template_name,
            ],
        ]);

        $this->storeContentItemSnapshot($item, $design);

        return $design->fresh();
    }

    public function linkManualDesign(CanvaDesign $design, string $designIdOrUrl): CanvaDesign
    {
        $value = trim($designIdOrUrl);
        if ($value === '') {
            throw new RuntimeException('Provide a Canva design ID or URL.');
        }

        $designId = $value;
        $editUrl = $design->canva_edit_url;

        if (preg_match('~canva\\.com/design/([^/?#]+)/?~i', $value, $matches) === 1) {
            $designId = trim((string) ($matches[1] ?? ''));
            $editUrl = $value;
        }

        if ($designId === '') {
            throw new RuntimeException('Unable to resolve Canva design ID.');
        }

        $design->canva_design_id = $designId;
        $design->canva_edit_url = $editUrl ?: 'https://www.canva.com/design/' . $designId . '/edit';
        $design->status = 'ready_in_canva';
        $design->meta = array_merge((array) ($design->meta ?? []), [
            'manual_linked_at' => now()->toDateTimeString(),
        ]);
        $design->save();

        if ($design->contentItem) {
            $this->storeContentItemSnapshot($design->contentItem, $design);
        }

        return $design->fresh();
    }

    public function refreshAutofillStatus(CanvaDesign $design): CanvaDesign
    {
        if ($design->source_mode !== 'autofill') {
            return $design;
        }

        $jobId = trim((string) data_get($design->meta, 'autofill_job.id', ''));
        if ($jobId === '') {
            throw new RuntimeException('Missing Canva autofill job ID.');
        }

        $connection = $this->bridge->requireActiveConnection((int) $design->tenant_id);
        $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->getAutofillJob($accessToken, $jobId));
        $job = (array) data_get($response, 'job', []);
        $status = (string) ($job['status'] ?? $design->status);

        $design->status = $status;
        $design->meta = array_merge((array) ($design->meta ?? []), [
            'autofill_job' => $job,
        ]);

        if ($status === 'success') {
            $design->canva_design_id = trim((string) data_get($job, 'result.design.id', $design->canva_design_id));
            $design->canva_edit_url = trim((string) data_get($job, 'result.design.urls.edit_url', data_get($job, 'result.design.url', $design->canva_edit_url)));
            $design->canva_view_url = trim((string) data_get($job, 'result.design.urls.view_url', $design->canva_view_url));
            $design->thumbnail_url = trim((string) data_get($job, 'result.design.thumbnail.url', $design->thumbnail_url));
            $design->status = 'ready_in_canva';
        }

        if ($status === 'failed') {
            $design->status = 'failed';
        }

        $design->save();

        if ($design->contentItem) {
            $this->storeContentItemSnapshot($design->contentItem, $design);
        }

        return $design->fresh();
    }

    private function buildPayloadFromContentItem(ContentItem $item, string $channelFormat): CanvaDesignPayload
    {
        $profile = TenantProfile::query()->where('tenant_id', $item->tenant_id)->first();
        $headline = trim((string) data_get($item->ai_meta, 'hook_meta.main_hook', $item->title ?: ''));
        $headline = $headline !== '' ? $headline : Str::limit((string) ($item->ai_caption ?: $item->caption ?: 'Social AI design'), 90, '');

        $subheadline = trim((string) data_get($item->ai_meta, 'hook_meta.narrative_angle', $item->content_angle ?: ''));
        $subheadline = $subheadline !== '' ? $subheadline : trim((string) data_get($item->ai_meta, 'creative_brief.content_angle', ''));

        $body = trim((string) ($item->ai_caption ?: $item->caption ?: ''));
        $body = preg_replace('/\s+/', ' ', $body ?: '') ?: '';
        $body = Str::limit($body, $channelFormat === 'story' ? 140 : 320, '');

        $cta = trim((string) ($item->ai_cta ?: data_get($item->ai_meta, 'hook_meta.cta_mode', '')));
        $brandClaim = trim((string) ($profile?->business_name ?: ''));
        if ($profile?->services) {
            $brandClaim = trim($brandClaim . ' · ' . Str::limit((string) $profile->services, 80, ''));
        }

        return new CanvaDesignPayload(
            channelFormat: $channelFormat,
            headline: $headline,
            subheadline: $subheadline,
            body: $body,
            cta: $cta,
            brandClaim: $brandClaim,
            slides: $this->buildSlides($headline, $body, $cta, $channelFormat),
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildSlides(string $headline, string $body, string $cta, string $channelFormat): array
    {
        if (!in_array($channelFormat, ['carousel', 'investor_presentation'], true)) {
            return [];
        }

        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $body) ?: [])));
        $slides = [];
        $slides[] = [
            'headline' => $headline,
            'body' => $sentences[0] ?? $body,
        ];

        foreach (array_slice($sentences, 1, 2) as $sentence) {
            $slides[] = [
                'headline' => Str::limit($headline, 60, ''),
                'body' => $sentence,
            ];
        }

        $slides[] = [
            'headline' => 'CTA',
            'body' => $cta !== '' ? $cta : 'Continua in Canva.',
        ];

        return $slides;
    }

    private function storeContentItemSnapshot(ContentItem $item, CanvaDesign $design): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        data_set($meta, 'canva.latest_design_id', (int) $design->id);
        data_set($meta, 'canva.latest_source_mode', (string) $design->source_mode);
        data_set($meta, 'canva.latest_status', (string) $design->status);
        data_set($meta, 'canva.canva_design_id', (string) ($design->canva_design_id ?? ''));
        $item->ai_meta = $meta;
        $item->save();
    }
}
