<?php

namespace App\Services\AI;

use App\Models\TenantFineTuneRun;
use App\Models\TenantProfile;
use App\Services\OpenAiFineTuningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantFineTuningService
{
    public function __construct(
        private readonly TenantFineTuningDatasetService $datasetBuilder,
        private readonly OpenAiFineTuningService $openAiFineTuningService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function previewStats(int $tenantId): array
    {
        $stats = $this->datasetBuilder->previewStats($tenantId);
        $latestRun = $this->latestRunForTenant($tenantId);
        $activeModel = $this->activeModelForTenant($tenantId);

        return $stats + [
            'latest_run' => $latestRun,
            'active_model' => $activeModel,
            'enabled' => (bool) config('generation.fine_tuning_enabled', true),
        ];
    }

    public function latestRunForTenant(int $tenantId): ?TenantFineTuneRun
    {
        return TenantFineTuneRun::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();
    }

    public function activeModelForTenant(int $tenantId): ?string
    {
        $row = TenantFineTuneRun::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('fine_tuned_model')
            ->latest('activated_at')
            ->latest('id')
            ->first();

        $model = trim((string) ($row?->fine_tuned_model ?? ''));
        return $model !== '' ? $model : null;
    }

    /**
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>
     */
    public function decorateProviderMatrix(int $tenantId, array $providerMatrix): array
    {
        $activeModel = $this->activeModelForTenant($tenantId);
        $baseModel = trim((string) (config('openai.fine_tuning_base_model') ?: config('openai.text_model') ?: 'gpt-4.1-mini'));
        $textProvider = strtolower(trim((string) data_get($providerMatrix, 'text.provider', 'openai')));

        if ($textProvider !== 'openai') {
            data_set($providerMatrix, 'text.base_model', $baseModel);
            data_set($providerMatrix, 'text.fine_tuned_active', false);
            data_set($providerMatrix, 'text.model_override', null);
            return $providerMatrix;
        }

        data_set($providerMatrix, 'text.base_model', $baseModel);
        data_set($providerMatrix, 'text.fine_tuned_active', $activeModel !== null);
        data_set($providerMatrix, 'text.model_override', $activeModel);
        data_set($providerMatrix, 'text.fine_tuned_model', $activeModel);

        return $providerMatrix;
    }

    public function startRunForTenant(int $tenantId): TenantFineTuneRun
    {
        if (!(bool) config('generation.fine_tuning_enabled', true)) {
            throw new RuntimeException('Fine-tuning disabilitato da configurazione.');
        }

        $existing = $this->latestRunForTenant($tenantId);
        if ($existing && in_array((string) $existing->status, ['preparing', 'uploading', 'queued', 'validating_files', 'running'], true)) {
            throw new RuntimeException('Esiste gia un run di fine-tuning in corso per questo tenant.');
        }

        $dataset = $this->datasetBuilder->buildDataset($tenantId);
        $baseModel = trim((string) (config('openai.fine_tuning_base_model') ?: config('openai.text_model') ?: 'gpt-4.1-mini'));
        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $businessSlug = Str::slug(trim((string) ($profile?->business_name ?? 'tenant-' . $tenantId)));
        $suffix = Str::limit($businessSlug !== '' ? $businessSlug : ('tenant-' . $tenantId), 18, '');

        $run = TenantFineTuneRun::query()->create([
            'tenant_id' => $tenantId,
            'provider' => 'openai',
            'base_model' => $baseModel,
            'status' => 'preparing',
            'training_examples_count' => (int) ($dataset['training_examples'] ?? 0),
            'validation_examples_count' => (int) ($dataset['validation_examples'] ?? 0),
            'training_dataset_path' => (string) ($dataset['training_path'] ?? ''),
            'validation_dataset_path' => (string) ($dataset['validation_path'] ?? ''),
            'dataset_stats' => (array) ($dataset['stats'] ?? []),
            'requested_at' => now(),
        ]);

        try {
            $run->status = 'uploading';
            $run->save();

            $trainingUpload = $this->openAiFineTuningService->uploadDatasetFile(
                (string) $run->training_dataset_path,
                'tenant-' . $tenantId . '-training.jsonl'
            );
            $validationUpload = null;
            if (filled($run->validation_dataset_path) && is_string($run->validation_dataset_path)) {
                $validationUpload = $this->openAiFineTuningService->uploadDatasetFile(
                    (string) $run->validation_dataset_path,
                    'tenant-' . $tenantId . '-validation.jsonl'
                );
            }

            $requestPayload = [
                'model' => $baseModel,
                'training_file' => (string) ($trainingUpload['id'] ?? ''),
                'suffix' => $suffix,
            ];
            if (!empty($validationUpload['id'])) {
                $requestPayload['validation_file'] = (string) $validationUpload['id'];
            }

            $remote = $this->openAiFineTuningService->createFineTuningJob($requestPayload);
            $run->training_file_id = (string) ($trainingUpload['id'] ?? '');
            $run->validation_file_id = (string) ($validationUpload['id'] ?? '');
            $run->remote_job_id = (string) ($remote['id'] ?? '');
            $run->status = $this->normalizeRemoteStatus((string) ($remote['status'] ?? 'queued'));
            $run->request_meta = $requestPayload;
            $run->result_meta = $remote;
            $run->save();
        } catch (Throwable $e) {
            $run->status = 'failed';
            $run->last_error = Str::limit($e->getMessage(), 1000, '');
            $run->failed_at = now();
            $run->save();
            throw $e;
        }

        return $run->fresh();
    }

    public function syncLatestRunForTenant(int $tenantId): ?TenantFineTuneRun
    {
        $run = $this->latestRunForTenant($tenantId);
        if (!$run) {
            return null;
        }

        return $this->syncRun($run);
    }

    public function syncRun(TenantFineTuneRun $run): TenantFineTuneRun
    {
        $jobId = trim((string) ($run->remote_job_id ?? ''));
        if ($jobId === '') {
            return $run;
        }

        $remote = $this->openAiFineTuningService->retrieveFineTuningJob($jobId);
        $status = $this->normalizeRemoteStatus((string) ($remote['status'] ?? 'queued'));
        $run->status = $status;
        $run->result_meta = $remote;
        $run->fine_tuned_model = trim((string) ($remote['fine_tuned_model'] ?? $run->fine_tuned_model ?? '')) ?: null;
        $run->last_error = trim((string) (($remote['error']['message'] ?? '') ?: $run->last_error ?? '')) ?: null;

        if ($status === 'succeeded') {
            $run->completed_at = now();
            $run->failed_at = null;
            $run->save();
            if ((bool) config('generation.fine_tuning_auto_activate', true) && !empty($run->fine_tuned_model)) {
                $this->activateRun($run);
            }

            return $run->fresh();
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $run->failed_at = now();
        }

        $run->save();
        return $run->fresh();
    }

    public function activateRun(TenantFineTuneRun $run): TenantFineTuneRun
    {
        if (trim((string) ($run->fine_tuned_model ?? '')) === '') {
            throw new RuntimeException('Questo run non ha ancora un modello fine-tuned utilizzabile.');
        }

        DB::transaction(function () use ($run): void {
            TenantFineTuneRun::query()
                ->where('tenant_id', (int) $run->tenant_id)
                ->update([
                    'is_active' => false,
                    'activated_at' => null,
                ]);

            $run->is_active = true;
            $run->activated_at = now();
            $run->save();
        });

        return $run->fresh();
    }

    private function normalizeRemoteStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'validating_files', 'queued', 'running', 'succeeded', 'failed', 'cancelled' => $status,
            default => $status !== '' ? $status : 'queued',
        };
    }
}