<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KlingService
{
    private function baseUrl(): string
    {
        return rtrim((string) (config('kling.base_url') ?: 'https://api-singapore.klingai.com'), '/');
    }

    private function accessKey(): string
    {
        $key = trim((string) (config('kling.access_key') ?: env('KLING_ACCESS_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing KLING_ACCESS_KEY');
        }

        return $key;
    }

    private function secretKey(): string
    {
        $key = trim((string) (config('kling.secret_key') ?: env('KLING_SECRET_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing KLING_SECRET_KEY');
        }

        return $key;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withToken($this->jwtToken())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('kling.connect_timeout') ?: 15));
    }

    /**
     * @param  array<int, string>  $referenceInputs
     * @param  array<string, mixed>  $options
     * @return array{id:string,raw:array<string,mixed>,request_mode:string,request_summary:array<string,mixed>}
     */
    public function createVideoJob(string $prompt, array $referenceInputs = [], array $options = []): array
    {
        $requestMode = $this->resolveRequestMode($referenceInputs, (string) ($options['request_mode'] ?? ''));
        $timeout = (int) (config('kling.timeout_create') ?: 60);
        $request = $this->request($timeout)->retry(1, 350);

        $attemptErrors = [];
        $successfulPayload = null;
        $successfulRequestMode = $requestMode;
        $successfulUrl = '';
        $successfulResponse = null;
        $attemptCount = 0;

        foreach ($this->buildCreateAttempts($prompt, $referenceInputs, $options, $requestMode) as $attempt) {
            $attemptCount++;
            $attemptUrl = (string) ($attempt['url'] ?? '');
            $attemptPayload = is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [];
            $attemptRequestMode = (string) ($attempt['request_mode'] ?? $requestMode);
            $res = $request->post($attemptUrl, $attemptPayload);

            if ($res->successful()) {
                $successfulPayload = $attemptPayload;
                $successfulRequestMode = $attemptRequestMode;
                $successfulUrl = $attemptUrl;
                $successfulResponse = $res;
                break;
            }

            $attemptErrors[] = $this->formatCreateError($res, $attemptUrl);
            if (!$this->isUnsupportedModelResponse($res)) {
                throw new RuntimeException('Kling video create error ' . implode(' | ', $attemptErrors));
            }
        }

        if (!is_array($successfulPayload) || !$successfulResponse instanceof Response) {
            throw new RuntimeException('Kling video create error ' . implode(' | ', $attemptErrors));
        }

        $data = $successfulResponse->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Kling create response payload.');
        }

        $taskId = trim((string) (
            data_get($data, 'data.task_id')
            ?? data_get($data, 'task_id')
            ?? data_get($data, 'request_id')
            ?? ''
        ));
        if ($taskId === '') {
            throw new RuntimeException('Missing Kling task id in create response.');
        }

        $summary = $this->buildRequestSummary($successfulPayload, $successfulRequestMode);
        $summary['attempt_count'] = $attemptCount;
        $summary['fallback_applied'] = $attemptCount > 1;
        Log::info('KlingService createVideoJob', $summary + [
            'task_id' => $taskId,
            'url' => $successfulUrl,
        ]);

        return [
            'id' => $taskId,
            'raw' => $data,
            'request_mode' => $successfulRequestMode,
            'request_summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveVideoJob(string $taskId, string $requestMode = 'text'): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('Missing Kling task id.');
        }

        $timeout = (int) (config('kling.timeout_poll') ?: 60);
        $errors = [];

        foreach ($this->retrieveUrls($taskId, $requestMode) as $url) {
            $res = $this->request($timeout)->get($url);
            if ($res->successful()) {
                $data = $res->json();
                if (!is_array($data)) {
                    throw new RuntimeException('Invalid Kling retrieve response payload.');
                }

                return $data;
            }

            $errors[] = "({$res->status()}) URL={$url} BODY=" . $res->body();
        }

        throw new RuntimeException('Kling video retrieve error ' . implode(' | ', $errors));
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForVideoCompletion(string $taskId, string $requestMode = 'text'): array
    {
        $pollEvery = (int) (config('kling.poll_interval') ?: 8);
        $pollEvery = max(2, min(30, $pollEvery));
        $timeout = (int) (config('kling.poll_timeout') ?: 420);
        $timeout = max(30, $timeout);
        if ($this->isOfficialKlingEndpoint()) {
            $timeout = max($timeout, 540);
        }
        $deadline = microtime(true) + $timeout;

        do {
            $job = $this->retrieveVideoJob($taskId, $requestMode);
            $statusRaw = $this->extractTaskStatus($job);
            $status = strtolower(trim($statusRaw));

            if (in_array($status, ['succeed', 'succeeded', 'completed', 'done', 'success'], true)) {
                return $job;
            }

            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                $reason = $this->extractFailureReason($job);
                throw new RuntimeException("Kling video generation failed: {$reason}");
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Kling video generation timeout after {$timeout}s (status={$statusRaw})");
            }

            sleep($pollEvery);
        } while (true);
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    public function downloadVideoContent(array $jobPayload): string
    {
        $url = $this->extractVideoUrl($jobPayload);
        if ($url === '') {
            throw new RuntimeException('Kling completed payload missing downloadable video URL.');
        }

        return $this->downloadBinary($url, (int) (config('kling.timeout_download') ?: 240));
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    public function downloadThumbnailContent(array $jobPayload): ?string
    {
        $url = $this->extractThumbnailUrl($jobPayload);
        if ($url === '') {
            return null;
        }

        try {
            return $this->downloadBinary($url, (int) (config('kling.timeout_download') ?: 240));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $referenceInputs
     */
    public function resolveRequestMode(array $referenceInputs, string $preferredMode = ''): string
    {
        $preferredMode = strtolower(trim($preferredMode));
        if (in_array($preferredMode, ['text', 'image', 'multi-image'], true)) {
            return $preferredMode;
        }

        $count = count(array_values(array_filter($referenceInputs, fn ($value) => is_string($value) && trim($value) !== '')));

        return match (true) {
            $count >= 2 => 'multi-image',
            $count === 1 => 'image',
            default => 'text',
        };
    }

    /**
     * @param  array<int, string>  $referenceInputs
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildCreatePayload(string $prompt, array $referenceInputs, array $options, string $requestMode): array
    {
        $modelName = $this->resolveModelName((string) ($options['model'] ?? config('kling.model') ?: ''), $requestMode);
        $payload = [
            'model_name' => $modelName,
            'prompt' => $this->normalizePrompt($prompt),
            'duration' => $this->normalizeDuration(
                (int) ($options['seconds'] ?? config('kling.video_seconds') ?: 5),
                $modelName
            ),
            'aspect_ratio' => $this->normalizeAspectRatio(
                (string) ($options['ratio'] ?? config('kling.video_ratio') ?: ''),
                (string) ($options['size'] ?? '')
            ),
            'cfg_scale' => $this->normalizeCfgScale((float) ($options['cfg_scale'] ?? config('kling.cfg_scale') ?: 0.5)),
        ];

        if ($this->shouldSendModeForModel($modelName)) {
            $payload['mode'] = $this->normalizeMode((string) ($options['mode'] ?? config('kling.mode') ?: 'pro'));
        }

        $negativePrompt = trim((string) ($options['negative_prompt'] ?? config('kling.negative_prompt') ?: ''));
        if ($negativePrompt !== '') {
            $payload['negative_prompt'] = $this->normalizeNegativePrompt($negativePrompt);
        }

        $callbackUrl = trim((string) ($options['callback_url'] ?? config('kling.callback_url') ?: ''));
        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        $externalTaskId = trim((string) ($options['external_task_id'] ?? ''));
        if ($externalTaskId !== '') {
            $payload['external_task_id'] = $externalTaskId;
        }

        $sound = trim((string) ($options['sound'] ?? config('kling.sound') ?: ''));
        if ($sound !== '') {
            $payload['sound'] = $sound;
        }

        $referenceInputs = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $referenceInputs),
            fn ($value) => $value !== ''
        ));

        if ($requestMode === 'image') {
            $payload['image'] = $referenceInputs[0] ?? '';
        } elseif ($requestMode === 'multi-image') {
            $limit = max(2, min(4, (int) (config('kling.max_reference_images') ?: 4)));
            $payload['image_list'] = array_map(
                fn ($value) => ['image' => $value],
                array_slice($referenceInputs, 0, $limit)
            );
        }

        return $payload;
    }

    private function resolveModelName(string $configuredModel, string $requestMode): string
    {
        $model = strtolower(trim($configuredModel));
        if ($model === '') {
            return $this->defaultModelForRequestMode($requestMode);
        }

        if (!$this->isOfficialKlingEndpoint()) {
            return $model;
        }

        $model = str_replace(['_', '.'], '-', $model);
        if (!$this->supportsModelForRequestMode($model, $requestMode)) {
            return $this->defaultModelForRequestMode($requestMode);
        }

        return $model;
    }

    /**
     * @param  array<int, string>  $referenceInputs
     * @param  array<string, mixed>  $options
     * @return array<int, array{request_mode:string,url:string,payload:array<string,mixed>}>
     */
    private function buildCreateAttempts(string $prompt, array $referenceInputs, array $options, string $requestMode): array
    {
        $attempts = [];
        $configuredModel = (string) ($options['model'] ?? config('kling.model') ?: '');
        $strictModel = $this->shouldUseStrictModel($options, $configuredModel);

        $this->appendCreateAttempts(
            $attempts,
            $prompt,
            $referenceInputs,
            $options,
            $requestMode,
            $this->modelCandidatesForRequestMode($requestMode, $configuredModel, $strictModel)
        );

        if (!$strictModel && $requestMode === 'multi-image' && count($referenceInputs) > 1) {
            $fallbackOptions = $options;
            $fallbackOptions['request_mode'] = 'image';

            $this->appendCreateAttempts(
                $attempts,
                $prompt,
                array_values(array_slice($referenceInputs, 0, 1)),
                $fallbackOptions,
                'image',
                $this->modelCandidatesForRequestMode('image', $configuredModel)
            );
        }

        return array_values($attempts);
    }

    /**
     * @param  array<string, array{request_mode:string,url:string,payload:array<string,mixed>}>  $attempts
     * @param  array<int, string>  $referenceInputs
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $modelCandidates
     */
    private function appendCreateAttempts(
        array &$attempts,
        string $prompt,
        array $referenceInputs,
        array $options,
        string $requestMode,
        array $modelCandidates
    ): void {
        foreach ($modelCandidates as $modelCandidate) {
            $attemptOptions = $options;
            $attemptOptions['model'] = $modelCandidate;
            $payload = $this->buildCreatePayload($prompt, $referenceInputs, $attemptOptions, $requestMode);
            $url = $this->createUrl($requestMode);
            $key = implode('|', [
                $requestMode,
                $url,
                (string) ($payload['model_name'] ?? ''),
                !empty($payload['image']) ? '1' : '0',
                (string) count((array) ($payload['image_list'] ?? [])),
            ]);

            if (!isset($attempts[$key])) {
                $attempts[$key] = [
                    'request_mode' => $requestMode,
                    'url' => $url,
                    'payload' => $payload,
                ];
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function modelCandidatesForRequestMode(string $requestMode, string $configuredModel, bool $strictModel = false): array
    {
        $configuredModel = strtolower(trim(str_replace(['_', '.'], '-', $configuredModel)));

        if ($strictModel && $configuredModel !== '') {
            if ($this->isOfficialKlingEndpoint() && !$this->supportsModelForRequestMode($configuredModel, $requestMode)) {
                return [];
            }

            return [$configuredModel];
        }

        $candidates = [];

        if ($configuredModel !== '') {
            $candidates[] = $configuredModel;
        }

        $fallbacks = match ($requestMode) {
            'multi-image' => ['kling-v3-omni', 'kling-v3', 'kling-v2'],
            'image' => ['kling-v3-omni', 'kling-v3', 'kling-video-o1', 'kling-v2-6', 'kling-v2-1', 'kling-v2', 'kling-v1-6'],
            default => ['kling-v3-omni', 'kling-v3', 'kling-video-o1', 'kling-v2-6', 'kling-v2-1-master', 'kling-v2-1', 'kling-v2', 'kling-v1-6'],
        };

        foreach ($fallbacks as $fallback) {
            $candidates[] = $fallback;
        }

        $candidates = array_values(array_filter(array_map(function ($model) use ($requestMode) {
            $normalized = strtolower(trim(str_replace(['_', '.'], '-', (string) $model)));
            if ($normalized === '') {
                return null;
            }

            if ($this->isOfficialKlingEndpoint() && !$this->supportsModelForRequestMode($normalized, $requestMode)) {
                return null;
            }

            return $normalized;
        }, $candidates)));

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function shouldUseStrictModel(array $options, string $configuredModel): bool
    {
        if (array_key_exists('strict_model', $options)) {
            return (bool) $options['strict_model'];
        }

        return trim($configuredModel) !== '' && (bool) config('kling.strict_model', false);
    }

    private function defaultModelForRequestMode(string $requestMode): string
    {
        return match ($requestMode) {
            'multi-image' => 'kling-v3-omni',
            'image' => 'kling-v3-omni',
            default => 'kling-v3-omni',
        };
    }

    private function supportsModelForRequestMode(string $model, string $requestMode): bool
    {
        $model = strtolower(trim($model));
        $requestMode = strtolower(trim($requestMode));

        if ($model === '') {
            return false;
        }

        return match ($requestMode) {
            'multi-image' => in_array($model, ['kling-v3-omni', 'kling-v3', 'kling-v2'], true),
            'image' => in_array($model, ['kling-v3-omni', 'kling-v3', 'kling-video-o1', 'kling-v2-6', 'kling-v2', 'kling-v2-1', 'kling-v2-1-master', 'kling-v1-6'], true),
            default => in_array($model, ['kling-v3-omni', 'kling-v3', 'kling-video-o1', 'kling-v2-6', 'kling-v2', 'kling-v2-1', 'kling-v2-1-master', 'kling-v1-6'], true),
        };
    }

    private function shouldSendModeForModel(string $modelName): bool
    {
        return !in_array(strtolower(trim($modelName)), ['kling-v2'], true);
    }

    private function isOfficialKlingEndpoint(): bool
    {
        return str_contains(strtolower($this->baseUrl()), 'klingai.com');
    }

    /**
     * @return array<int, string>
     */
    private function retrieveUrls(string $taskId, string $requestMode): array
    {
        $urls = [];
        $generic = trim((string) (config('kling.retrieve_endpoint') ?: ''));
        if ($generic !== '') {
            $urls[] = $this->buildUrlFromEndpoint($generic, $taskId);
        }

        $specificKey = match ($requestMode) {
            'image' => 'image_retrieve_endpoint',
            'multi-image' => 'multi_image_retrieve_endpoint',
            default => 'text_retrieve_endpoint',
        };

        $specific = trim((string) (config("kling.{$specificKey}") ?: ''));
        if ($specific !== '') {
            $urls[] = $this->buildUrlFromEndpoint($specific, $taskId);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    private function createUrl(string $requestMode): string
    {
        $endpoint = match ($requestMode) {
            'image' => (string) (config('kling.image_create_endpoint') ?: '/v1/videos/image2video'),
            'multi-image' => (string) (config('kling.multi_image_create_endpoint') ?: '/v1/videos/multi-image2video'),
            default => (string) (config('kling.text_create_endpoint') ?: '/v1/videos/text2video'),
        };

        return $this->buildUrlFromEndpoint($endpoint);
    }

    private function buildUrlFromEndpoint(string $endpoint, ?string $id = null): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new RuntimeException('Missing Kling endpoint configuration.');
        }
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }
        if ($id !== null) {
            $endpoint = str_replace('{id}', rawurlencode($id), $endpoint);
        }

        return $this->baseUrl() . $endpoint;
    }

    private function jwtToken(): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $now = time();
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $this->accessKey(),
            'exp' => $now + 1800,
            'nbf' => max(0, $now - 5),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = hash_hmac('sha256', $header . '.' . $payload, $this->secretKey(), true);

        return $header . '.' . $payload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizePrompt(string $prompt): string
    {
        $prompt = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        if ($prompt === '') {
            $prompt = 'Create a coherent social reel video with a realistic subject and native social pacing.';
        }

        $limit = (int) (config('kling.max_prompt_chars') ?: 1400);
        $limit = max(300, min(1800, $limit));

        return mb_strlen($prompt, 'UTF-8') > $limit
            ? trim(mb_substr($prompt, 0, $limit, 'UTF-8'))
            : $prompt;
    }

    private function normalizeNegativePrompt(string $negativePrompt): string
    {
        $negativePrompt = trim(preg_replace('/\s+/u', ' ', $negativePrompt) ?? $negativePrompt);

        return mb_strlen($negativePrompt, 'UTF-8') > 600
            ? trim(mb_substr($negativePrompt, 0, 600, 'UTF-8'))
            : $negativePrompt;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['std', 'pro'], true) ? $mode : 'pro';
    }

    private function normalizeDuration(int $seconds, string $modelName = ''): int
    {
        $modelName = strtolower(trim($modelName));

        if (in_array($modelName, ['kling-v3-omni', 'kling-v3'], true)) {
            return max(3, min(15, $seconds));
        }

        return $seconds >= 8 ? 10 : 5;
    }

    private function normalizeCfgScale(float $cfgScale): float
    {
        return max(0.0, min(1.0, $cfgScale));
    }

    private function normalizeAspectRatio(string $configuredRatio, string $size): string
    {
        $configuredRatio = strtolower(trim($configuredRatio));
        $allowed = ['16:9', '9:16', '1:1'];
        if (in_array($configuredRatio, $allowed, true)) {
            return $configuredRatio;
        }

        if (preg_match('/^(\d{1,4}):(\d{1,4})$/', $configuredRatio, $m) === 1) {
            return $this->closestAllowedRatio((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{2,5})x(\d{2,5})$/', trim(strtolower($size)), $m) === 1) {
            return $this->closestAllowedRatio((int) $m[1], (int) $m[2]);
        }

        return '9:16';
    }

    private function closestAllowedRatio(int $width, int $height): string
    {
        $target = $width / max(1, $height);
        $best = '9:16';
        $bestDiff = INF;

        foreach (['16:9', '9:16', '1:1'] as $candidate) {
            [$cw, $ch] = array_map('intval', explode(':', $candidate, 2));
            $diff = abs($target - ($cw / max(1, $ch)));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $job
     */
    private function extractTaskStatus(array $job): string
    {
        return trim((string) (
            data_get($job, 'data.task_status')
            ?? data_get($job, 'task_status')
            ?? data_get($job, 'status')
            ?? data_get($job, 'data.status')
            ?? ''
        ));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function extractFailureReason(array $job): string
    {
        foreach ([
            'data.task_status_msg',
            'task_status_msg',
            'data.message',
            'message',
            'error.message',
        ] as $path) {
            $value = trim((string) data_get($job, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        $status = $this->extractTaskStatus($job);

        return $status !== '' ? "video_generation_failed (status={$status})" : 'video_generation_failed';
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    private function extractVideoUrl(array $jobPayload): string
    {
        $candidates = [
            'data.task_result.videos.0.url',
            'task_result.videos.0.url',
            'data.videos.0.url',
            'videos.0.url',
            'data.task_result.video.url',
            'task_result.video.url',
            'data.video.url',
            'video.url',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($jobPayload, $path, ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    private function extractThumbnailUrl(array $jobPayload): string
    {
        $candidates = [
            'data.task_result.images.0.url',
            'task_result.images.0.url',
            'data.images.0.url',
            'images.0.url',
            'data.task_result.cover.url',
            'task_result.cover.url',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($jobPayload, $path, ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL)) {
                return $value;
            }
        }

        return '';
    }

    private function downloadBinary(string $url, int $timeout): string
    {
        $res = Http::timeout($timeout)
            ->connectTimeout((int) (config('kling.connect_timeout') ?: 15))
            ->get($url);

        if (!$res->successful()) {
            throw new RuntimeException("Kling asset download error ({$res->status()}) URL={$url}");
        }

        $body = $res->body();
        if (!is_string($body) || $body === '') {
            throw new RuntimeException("Kling asset download empty body URL={$url}");
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildRequestSummary(array $payload, string $requestMode): array
    {
        return [
            'request_mode' => $requestMode,
            'model' => (string) ($payload['model_name'] ?? ''),
            'mode' => (string) ($payload['mode'] ?? ''),
            'duration' => (int) ($payload['duration'] ?? 0),
            'aspect_ratio' => (string) ($payload['aspect_ratio'] ?? ''),
            'has_negative_prompt' => !empty($payload['negative_prompt']),
            'reference_count' => is_array($payload['image_list'] ?? null)
                ? count((array) $payload['image_list'])
                : (!empty($payload['image']) ? 1 : 0),
        ];
    }

    private function isUnsupportedModelResponse(Response $response): bool
    {
        return $response->status() === 400
            && str_contains(strtolower($response->body()), 'model is not supported');
    }

    private function formatCreateError(Response $response, string $url): string
    {
        return "({$response->status()}) URL={$url} BODY=" . $response->body();
    }
}

