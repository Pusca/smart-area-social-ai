<?php

namespace App\Services\Canva;

use App\Jobs\PollCanvaExportJob;
use App\Models\CanvaDesign;
use App\Models\CanvaExportJob as CanvaExportJobRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CanvaExportService
{
    public function __construct(
        private readonly CanvaBridgeService $bridge,
        private readonly CanvaTokenService $tokenService,
        private readonly CanvaApiClient $apiClient
    ) {
    }

    public function requestExport(CanvaDesign $design, string $exportType): CanvaExportJobRecord
    {
        $exportType = Str::lower(trim($exportType));
        if ($exportType === '') {
            throw new RuntimeException('Export type is required.');
        }

        if (trim((string) $design->canva_design_id) === '') {
            throw new RuntimeException('Canva design ID is missing. Link the Canva design before exporting.');
        }

        $connection = $this->bridge->requireActiveConnection((int) $design->tenant_id);
        $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->createExportJob(
            $accessToken,
            (string) $design->canva_design_id,
            $this->exportFormatPayload($exportType)
        ));

        $job = CanvaExportJobRecord::query()->create([
            'tenant_id' => (int) $design->tenant_id,
            'canva_design_id' => (int) $design->id,
            'external_job_id' => trim((string) data_get($response, 'job.id', '')),
            'export_type' => $exportType,
            'status' => (string) data_get($response, 'job.status', 'pending'),
            'metadata_json' => [
                'initial_response' => $response,
            ],
        ]);

        $design->status = 'export_pending';
        $design->save();

        PollCanvaExportJob::dispatch((int) $job->id);

        return $job->fresh();
    }

    public function refreshExportJob(CanvaExportJobRecord $job): CanvaExportJobRecord
    {
        $design = $job->design;
        if (!$design) {
            throw new RuntimeException('Canva design not found for export job.');
        }

        $connection = $this->bridge->requireActiveConnection((int) $job->tenant_id);
        $response = $this->tokenService->withAccessToken($connection, fn (string $accessToken): array => $this->apiClient->getExportJob(
            $accessToken,
            (string) $job->external_job_id
        ));

        $payload = (array) data_get($response, 'job', []);
        $status = (string) ($payload['status'] ?? $job->status);
        $job->status = $status;
        $job->metadata_json = array_merge((array) ($job->metadata_json ?? []), [
            'latest_response' => $response,
        ]);

        if ($status === 'success') {
            $urls = array_values(array_filter(array_map('strval', (array) ($payload['urls'] ?? []))));
            if ($urls === []) {
                throw new RuntimeException('Canva export completed without download URLs.');
            }

            $bytes = $this->apiClient->downloadFile($urls[0]);
            $extension = $this->resolveExtension((string) $job->export_type);
            $path = 'canva/exports/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $extension;
            Storage::disk('public')->put($path, $bytes);

            $job->download_url = $urls[0];
            $job->stored_path = $path;
            $job->completed_at = now();
            $job->save();

            $design->exported_asset_path = $path;
            $design->exported_file_type = (string) $job->export_type;
            $design->status = 'exported';
            $design->save();

            $this->attachExportToContentItem($design, $path, (string) $job->export_type);

            return $job->fresh();
        }

        if ($status === 'failed') {
            $job->completed_at = now();
            $job->save();
            $design->status = 'export_failed';
            $design->save();

            return $job->fresh();
        }

        $job->save();

        return $job->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function exportFormatPayload(string $exportType): array
    {
        return match ($exportType) {
            'png' => [
                'type' => 'png',
                'lossless' => true,
            ],
            'pdf' => [
                'type' => 'pdf',
                'export_quality' => 'regular',
            ],
            'pptx' => [
                'type' => 'pptx',
            ],
            'mp4' => [
                'type' => 'mp4',
                'quality' => '720p',
            ],
            default => throw new RuntimeException('Unsupported Canva export type: ' . $exportType),
        };
    }

    private function resolveExtension(string $exportType): string
    {
        return match ($exportType) {
            'pdf' => 'pdf',
            'pptx' => 'pptx',
            'mp4' => 'mp4',
            default => 'png',
        };
    }

    private function attachExportToContentItem(CanvaDesign $design, string $path, string $exportType): void
    {
        if (!$design->contentItem) {
            return;
        }

        $contentItem = $design->contentItem;
        $assets = is_array($contentItem->assets) ? $contentItem->assets : [];
        $alreadyPresent = collect($assets)->contains(fn ($asset): bool => is_array($asset) && (string) ($asset['path'] ?? '') === $path);

        if (!$alreadyPresent) {
            $assets[] = [
                'type' => 'canva_export_' . $exportType,
                'path' => $path,
                'source' => 'canva',
            ];
            $contentItem->assets = $assets;
        }

        $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
        data_set($meta, 'canva.latest_export.path', $path);
        data_set($meta, 'canva.latest_export.type', $exportType);
        data_set($meta, 'canva.latest_export.design_id', (int) $design->id);
        $contentItem->ai_meta = $meta;
        $contentItem->save();
    }
}
