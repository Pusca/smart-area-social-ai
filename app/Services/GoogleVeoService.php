<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleVeoService
{
    private function apiKey(): string
    {
        $key = trim((string) (config('google_veo.api_key') ?: env('GOOGLE_VEO_API_KEY') ?: env('NANOBANANA_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing GOOGLE_VEO_API_KEY');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) (config('google_veo.base_url') ?: 'https://generativelanguage.googleapis.com'), '/');
    }

    private function apiVersion(): string
    {
        return trim((string) (config('google_veo.api_version') ?: 'v1beta'));
    }

    private function request(int $timeout, bool $asJson = true): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('google_veo.connect_timeout') ?: 15))
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey(),
            ]);

        if ($asJson) {
            $request = $request->asJson();
        }

        return $request;
    }

    private function createUrl(string $model): string
    {
        return sprintf(
            '%s/%s/models/%s:predictLongRunning',
            $this->baseUrl(),
            $this->apiVersion(),
            rawurlencode($model)
        );
    }

    private function operationUrl(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Missing Google Veo operation name.');
        }

        if (str_starts_with($name, 'http://') || str_starts_with($name, 'https://')) {
            return $name;
        }

        $name = ltrim($name, '/');
        if (!str_starts_with($name, $this->apiVersion() . '/')) {
            $name = $this->apiVersion() . '/' . $name;
        }

        return $this->baseUrl() . '/' . $name;
    }

    private function fileUrl(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Missing Google Veo file name.');
        }

        if (str_starts_with($name, 'http://') || str_starts_with($name, 'https://')) {
            return $name;
        }

        $name = ltrim($name, '/');
        if (!str_starts_with($name, $this->apiVersion() . '/')) {
            $name = $this->apiVersion() . '/' . $name;
        }

        return $this->baseUrl() . '/' . $name;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{id:string,raw:array<string,mixed>,request_summary:array<string,mixed>}
     */
    public function createVideoJob(string $prompt, ?string $inputReferenceAbsolutePath = null, array $options = []): array
    {
        $model = $this->normalizeModelName((string) ($options['model'] ?? config('google_veo.model') ?: ''));
        $seconds = $this->normalizeDuration((int) ($options['seconds'] ?? config('google_veo.video_seconds') ?: 8));
        $ratio = $this->normalizeAspectRatio(
            (string) ($options['ratio'] ?? config('google_veo.video_ratio') ?: ''),
            (string) ($options['size'] ?? '')
        );
        $resolution = $this->normalizeResolution((string) ($options['resolution'] ?? config('google_veo.resolution') ?: '720p'));
        $negativePrompt = trim((string) ($options['negative_prompt'] ?? config('google_veo.negative_prompt') ?: ''));
        $generateAudio = (bool) ($options['generate_audio'] ?? config('google_veo.generate_audio', false));

        $payload = [
            'instances' => [[
                'prompt' => $this->normalizePrompt($prompt),
            ]],
            'parameters' => [
                'aspectRatio' => $ratio,
                'durationSeconds' => $seconds,
                'resolution' => $resolution,
                'sampleCount' => 1,
                'generateAudio' => $generateAudio,
            ],
        ];

        $hasImageInput = false;
        if (is_string($inputReferenceAbsolutePath) && $inputReferenceAbsolutePath !== '' && is_file($inputReferenceAbsolutePath)) {
            $mime = strtolower((string) (mime_content_type($inputReferenceAbsolutePath) ?: ''));
            $bytes = @file_get_contents($inputReferenceAbsolutePath);
            if (str_starts_with($mime, 'image/') && is_string($bytes) && $bytes !== '') {
                $payload['instances'][0]['image'] = [
                    'bytesBase64Encoded' => base64_encode($bytes),
                    'mimeType' => $mime,
                ];
                $payload['parameters']['personGeneration'] = (string) (config('google_veo.person_generation_image') ?: 'allow_adult');
                $hasImageInput = true;
            }
        }

        if (!$hasImageInput) {
            $payload['parameters']['personGeneration'] = (string) (config('google_veo.person_generation_text') ?: 'allow_all');
        }

        if ($negativePrompt !== '') {
            $payload['parameters']['negativePrompt'] = $negativePrompt;
        }

        $url = $this->createUrl($model);
        $timeout = (int) (config('google_veo.timeout_create') ?: 60);
        $response = $this->request($timeout)->retry(1, 500)->post($url, $payload);

        if (!$response->successful()) {
            throw new RuntimeException("Google Veo video create error ({$response->status()}) URL={$url} BODY=" . $response->body());
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Google Veo create response payload.');
        }

        $operationName = trim((string) ($data['name'] ?? data_get($data, 'operation.name', '')));
        if ($operationName === '') {
            throw new RuntimeException('Missing Google Veo operation name in create response.');
        }

        $summary = [
            'mode' => $hasImageInput ? 'image_to_video' : 'text_to_video',
            'model' => $model,
            'seconds' => $seconds,
            'aspect_ratio' => $ratio,
            'resolution' => $resolution,
            'generate_audio' => $generateAudio,
            'has_image_input' => $hasImageInput,
            'has_negative_prompt' => $negativePrompt !== '',
        ];

        Log::info('GoogleVeoService createVideoJob', $summary + [
            'operation_name' => $operationName,
            'url' => $url,
        ]);

        return [
            'id' => $operationName,
            'raw' => $data,
            'request_summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveVideoJob(string $operationName): array
    {
        $url = $this->operationUrl($operationName);
        $timeout = (int) (config('google_veo.timeout_poll') ?: 60);
        $response = $this->request($timeout)->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Google Veo video retrieve error ({$response->status()}) URL={$url} BODY=" . $response->body());
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Google Veo retrieve response payload.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForVideoCompletion(string $operationName): array
    {
        $pollEvery = max(2, min(30, (int) (config('google_veo.poll_interval') ?: 10)));
        $timeout = max(30, (int) (config('google_veo.poll_timeout') ?: 900));
        $deadline = microtime(true) + $timeout;

        do {
            $job = $this->retrieveVideoJob($operationName);
            $done = (bool) ($job['done'] ?? false);
            if ($done) {
                if (is_array($job['error'] ?? null)) {
                    $reason = $this->extractFailureReason($job);
                    throw new RuntimeException("Google Veo video generation failed: {$reason}");
                }

                return $job;
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Google Veo video generation timeout after {$timeout}s");
            }

            sleep($pollEvery);
        } while (true);
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    public function downloadVideoContent(array $jobPayload): string
    {
        $downloadUrl = $this->extractDownloadUrl($jobPayload);
        if ($downloadUrl === '') {
            $fileName = $this->extractFileName($jobPayload);
            if ($fileName !== '') {
                $filePayload = $this->retrieveFile($fileName);
                $downloadUrl = $this->extractDownloadUrl($filePayload);
                if ($downloadUrl === '') {
                    $downloadUrl = $this->fileUrl($fileName) . ':download';
                }
            }
        }

        if ($downloadUrl === '') {
            throw new RuntimeException('Google Veo completed payload missing downloadable video URL.');
        }

        return $this->downloadBinary($downloadUrl, (int) (config('google_veo.timeout_download') ?: 240));
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    public function downloadThumbnailContent(array $jobPayload): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieveFile(string $fileName): array
    {
        $url = $this->fileUrl($fileName);
        $timeout = (int) (config('google_veo.timeout_poll') ?: 60);
        $response = $this->request($timeout)->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Google Veo file retrieve error ({$response->status()}) URL={$url} BODY=" . $response->body());
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Google Veo file response payload.');
        }

        return $data;
    }

    private function downloadBinary(string $url, int $timeout): string
    {
        $response = $this->request($timeout, false)->get($url);
        if (!$response->successful()) {
            throw new RuntimeException("Google Veo video download error ({$response->status()}) URL={$url} BODY=" . $response->body());
        }

        $body = $response->body();
        if (!is_string($body) || $body === '') {
            throw new RuntimeException("Google Veo video download returned an empty body URL={$url}");
        }

        return $body;
    }

    private function normalizePrompt(string $prompt): string
    {
        $limit = max(400, min(2400, (int) (config('google_veo.max_prompt_chars') ?: 1600)));

        return \Illuminate\Support\Str::limit(trim($prompt), $limit, '');
    }

    private function normalizeDuration(int $seconds): int
    {
        $seconds = max(1, $seconds);
        if ($seconds <= 4) {
            return 4;
        }

        if ($seconds <= 6) {
            return 6;
        }

        return 8;
    }

    private function normalizeAspectRatio(string $ratio, string $size = ''): string
    {
        $ratio = trim($ratio);
        if (in_array($ratio, ['9:16', '16:9'], true)) {
            return $ratio;
        }

        if (preg_match('/^\s*(\d{2,4})x(\d{2,4})\s*$/i', $size, $matches) === 1) {
            $width = (int) ($matches[1] ?? 0);
            $height = (int) ($matches[2] ?? 0);
            if ($width > 0 && $height > 0) {
                return $height > $width ? '9:16' : '16:9';
            }
        }

        return '9:16';
    }

    private function normalizeResolution(string $resolution): string
    {
        $resolution = strtolower(trim($resolution));

        return in_array($resolution, ['720p', '1080p'], true) ? $resolution : '720p';
    }

    private function normalizeModelName(string $model): string
    {
        $model = strtolower(trim($model));

        return match ($model) {
            '', 'veo3.1', 'veo-3.1', 'veo_3.1', 'veo3_1',
            'veo-3.1-generate-001', 'veo-3.1-generate-preview' => 'veo-3.1-generate-preview',
            default => 'veo-3.1-generate-preview',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractDownloadUrl(array $payload): string
    {
        $candidates = [
            'response.generatedVideos.0.video.downloadUri',
            'response.generatedVideos.0.downloadUri',
            'response.generateVideoResponse.generatedSamples.0.video.downloadUri',
            'response.generateVideoResponse.generatedVideos.0.video.downloadUri',
            'generatedVideos.0.video.downloadUri',
            'generatedVideos.0.downloadUri',
            'file.downloadUri',
            'downloadUri',
            'response.generatedVideos.0.video.uri',
            'response.generateVideoResponse.generatedSamples.0.video.uri',
            'generatedVideos.0.video.uri',
            'file.uri',
            'uri',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($this->isLikelyDownloadUrl($value)) {
                return $value;
            }
        }

        return $this->findFirstMatchingStringRecursive($payload, fn (string $value) => $this->isLikelyDownloadUrl($value));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFileName(array $payload): string
    {
        $candidates = [
            'response.generatedVideos.0.video.name',
            'response.generateVideoResponse.generatedSamples.0.video.name',
            'response.generateVideoResponse.generatedVideos.0.video.name',
            'generatedVideos.0.video.name',
            'file.name',
            'name',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if (str_starts_with($value, 'files/')) {
                return $value;
            }
        }

        return $this->findFirstMatchingStringRecursive($payload, fn (string $value) => str_starts_with($value, 'files/'));
    }

    private function isLikelyDownloadUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            return false;
        }

        return str_contains($value, ':download')
            || str_contains($value, 'alt=media')
            || str_contains($value, '/files/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFailureReason(array $payload): string
    {
        $candidates = [
            trim((string) data_get($payload, 'error.message', '')),
            trim((string) data_get($payload, 'response.error.message', '')),
            trim((string) data_get($payload, 'response.status.message', '')),
            trim((string) data_get($payload, 'message', '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'unknown_error';
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    private function findFirstMatchingStringRecursive(array $payload, callable $matcher): string
    {
        foreach ($payload as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '' && $matcher($trimmed) === true) {
                    return $trimmed;
                }
                continue;
            }

            if (is_array($value)) {
                $found = $this->findFirstMatchingStringRecursive($value, $matcher);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }
}
