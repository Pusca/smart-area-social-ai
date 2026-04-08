<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NanoBananaService
{
    private function apiKey(): string
    {
        $key = trim((string) (config('nanobanana.api_key') ?: env('NANOBANANA_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing NANOBANANA_API_KEY');
        }

        return $key;
    }

    private function configuredBaseUrl(): string
    {
        $base = rtrim((string) (config('nanobanana.base_url') ?: 'https://generativelanguage.googleapis.com'), '/');
        if ($base === '') {
            $base = 'https://generativelanguage.googleapis.com';
        }

        return $base;
    }

    private function baseUrlForKey(string $apiKey): string
    {
        $base = $this->configuredBaseUrl();

        // Auto-fix common misconfiguration: Google API key with old placeholder host.
        if ($this->isGoogleApiKey($apiKey) && str_contains(strtolower($base), 'api.nanobanana.ai')) {
            return 'https://generativelanguage.googleapis.com';
        }

        return $base;
    }

    private function isGoogleApiKey(string $apiKey): bool
    {
        return str_starts_with($apiKey, 'AIza');
    }

    private function authMode(string $apiKey): string
    {
        $mode = strtolower(trim((string) (config('nanobanana.auth_mode') ?: 'auto')));

        if ($mode === 'auto') {
            return $this->isGoogleApiKey($apiKey) ? 'x-goog-api-key' : 'bearer';
        }

        if (in_array($mode, ['x-goog-api-key', 'x_goog_api_key', 'goog', 'google'], true)) {
            return 'x-goog-api-key';
        }

        return 'bearer';
    }

    private function shouldUseGeminiApi(string $apiKey): bool
    {
        $base = strtolower($this->baseUrlForKey($apiKey));
        if (str_contains($base, 'generativelanguage.googleapis.com')) {
            return true;
        }

        return $this->isGoogleApiKey($apiKey);
    }

    private function request(int $timeout, string $apiKey, bool $asJson = true): PendingRequest
    {
        $req = Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('nanobanana.connect_timeout') ?: 15));

        if ($asJson) {
            $req = $req->asJson();
        }

        $authMode = $this->authMode($apiKey);
        if ($authMode === 'x-goog-api-key') {
            $req = $req->withHeaders(['x-goog-api-key' => $apiKey]);
        } else {
            $req = $req->withToken($apiKey);
        }

        return $req;
    }

    /**
     * @return array{b64:string,b64_json:string,raw:array}
     */
    public function generateImageBase64(string $prompt, ?string $modelOverride = null): array
    {
        $apiKey = $this->apiKey();
        $model = trim((string) ($modelOverride ?: config('nanobanana.image_model') ?: 'gemini-2.5-flash-image'));
        if ($this->shouldUseGeminiApi($apiKey)) {
            $model = $this->normalizeGeminiModelName($model);
            return $this->generateImageBase64Gemini($prompt, $model, $apiKey);
        }

        return $this->generateImageBase64Legacy($prompt, $model, $apiKey);
    }

    /**
     * @param  array<int, string>  $imageAbsolutePaths
     * @return array{b64:string,b64_json:string,raw:array}
     */
    public function generateImageEditBase64(string $prompt, array $imageAbsolutePaths, ?string $modelOverride = null): array
    {
        $apiKey = $this->apiKey();
        $model = trim((string) ($modelOverride ?: config('nanobanana.image_model') ?: 'gemini-2.5-flash-image'));
        if ($this->shouldUseGeminiApi($apiKey)) {
            $model = $this->normalizeGeminiModelName($model);
            return $this->generateImageEditBase64Gemini($prompt, $imageAbsolutePaths, $model, $apiKey);
        }

        return $this->generateImageEditBase64Legacy($prompt, $imageAbsolutePaths, $model, $apiKey);
    }

    /**
     * @return array{b64:string,b64_json:string,raw:array}
     */
    private function generateImageBase64Gemini(string $prompt, string $model, string $apiKey): array
    {
        $timeout = (int) (config('nanobanana.timeout') ?: 120);
        $url = $this->geminiGenerateUrl($model, $apiKey);

        try {
            $payload = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ]],
            ];
            $generationConfig = $this->geminiGenerationConfig();
            if (!empty($generationConfig)) {
                $payload['generationConfig'] = $generationConfig;
            }

            $res = $this->request($timeout, $apiKey, true)
                ->retry(2, 500)
                ->post($url, $payload);

            if (!$res->successful()) {
                throw new RuntimeException("NanoBanana image error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid NanoBanana image response payload.');
            }

            $b64 = $this->extractBase64FromGeminiPayload($data, $apiKey, $timeout);
            if ($b64 === '') {
                $retry = $this->retryGeminiImageRequestWithoutText($url, $payload, $apiKey, $timeout);
                if ($retry !== null) {
                    return $retry;
                }

                throw new RuntimeException($this->missingGeminiImageMessage($data, 'NanoBanana response'));
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('NanoBananaService generateImageBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $imageAbsolutePaths
     * @return array{b64:string,b64_json:string,raw:array}
     */
    private function generateImageEditBase64Gemini(string $prompt, array $imageAbsolutePaths, string $model, string $apiKey): array
    {
        $timeout = (int) (config('nanobanana.timeout') ?: 120);
        $url = $this->geminiGenerateUrl($model, $apiKey);

        $paths = array_values(array_filter($imageAbsolutePaths, fn ($p) => is_string($p) && is_file($p)));
        if (empty($paths)) {
            throw new RuntimeException('No valid image file provided for NanoBanana image edit.');
        }

        try {
            $parts = [['text' => $prompt]];
            foreach ($paths as $idx => $path) {
                $mime = mime_content_type($path) ?: 'application/octet-stream';
                $bytes = @file_get_contents($path);
                if (!is_string($bytes) || $bytes === '') {
                    continue;
                }
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data' => base64_encode($bytes),
                    ],
                ];
                if ($idx >= 3) {
                    break;
                }
            }
            if (count($parts) < 2) {
                throw new RuntimeException('No readable image bytes provided for NanoBanana image edit.');
            }

            $payload = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => $parts,
                ]],
            ];
            $generationConfig = $this->geminiGenerationConfig();
            if (!empty($generationConfig)) {
                $payload['generationConfig'] = $generationConfig;
            }

            $res = $this->request($timeout, $apiKey, true)
                ->retry(1, 400)
                ->post($url, $payload);

            if (!$res->successful()) {
                throw new RuntimeException("NanoBanana image edit error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid NanoBanana image edit response payload.');
            }

            $b64 = $this->extractBase64FromGeminiPayload($data, $apiKey, $timeout);
            if ($b64 === '') {
                $retry = $this->retryGeminiImageRequestWithoutText($url, $payload, $apiKey, $timeout);
                if ($retry !== null) {
                    return $retry;
                }

                throw new RuntimeException($this->missingGeminiImageMessage($data, 'NanoBanana edit response'));
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('NanoBananaService generateImageEditBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
                'images_count' => count($paths),
            ]);
            throw $e;
        }
    }

    /**
     * @return array{b64:string,b64_json:string,raw:array}
     */
    private function generateImageBase64Legacy(string $prompt, string $model, string $apiKey): array
    {
        $timeout = (int) (config('nanobanana.timeout') ?: 120);
        $url = $this->legacyEndpointUrl('nanobanana.image_endpoint', '/v1/images/generations', $apiKey);

        try {
            $res = $this->request($timeout, $apiKey, true)
                ->retry(2, 500)
                ->post($url, [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => (string) (config('nanobanana.image_size') ?: '1024x1024'),
                ]);

            if (!$res->successful()) {
                throw new RuntimeException("NanoBanana image error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid NanoBanana image response payload.');
            }

            $b64 = $this->extractBase64FromPayload($data);
            if ($b64 === '') {
                throw new RuntimeException('Missing base64 image field in NanoBanana response.');
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('NanoBananaService generateImageBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $imageAbsolutePaths
     * @return array{b64:string,b64_json:string,raw:array}
     */
    private function generateImageEditBase64Legacy(string $prompt, array $imageAbsolutePaths, string $model, string $apiKey): array
    {
        $timeout = (int) (config('nanobanana.timeout') ?: 120);
        $url = $this->legacyEndpointUrl('nanobanana.image_edit_endpoint', '/v1/images/edits', $apiKey);

        $paths = array_values(array_filter($imageAbsolutePaths, fn ($p) => is_string($p) && is_file($p)));
        if (empty($paths)) {
            throw new RuntimeException('No valid image file provided for NanoBanana image edit.');
        }

        try {
            $req = $this->request($timeout, $apiKey, false)->retry(1, 400);

            foreach ($paths as $idx => $path) {
                $filename = basename($path);
                $mime = mime_content_type($path) ?: 'application/octet-stream';
                $bytes = @file_get_contents($path);
                if (!is_string($bytes) || $bytes === '') {
                    continue;
                }
                $req = $req->attach('image[]', $bytes, $filename, ['Content-Type' => $mime]);
                if ($idx >= 3) {
                    break;
                }
            }

            $res = $req->post($url, [
                'model' => $model,
                'prompt' => $prompt,
                'size' => (string) (config('nanobanana.image_size') ?: '1024x1024'),
            ]);

            if (!$res->successful()) {
                throw new RuntimeException("NanoBanana image edit error ({$res->status()}) URL={$url} BODY=" . $res->body());
            }

            $data = $res->json();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid NanoBanana image edit response payload.');
            }

            $b64 = $this->extractBase64FromPayload($data);
            if ($b64 === '') {
                throw new RuntimeException('Missing base64 image field in NanoBanana edit response.');
            }

            return [
                'b64' => $b64,
                'b64_json' => $b64,
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::warning('NanoBananaService generateImageEditBase64 failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'url' => $url,
                'images_count' => count($paths),
            ]);
            throw $e;
        }
    }

    private function geminiGenerateUrl(string $model, string $apiKey): string
    {
        $template = trim((string) (config('nanobanana.generate_endpoint') ?: '/{version}/models/{model}:generateContent'));
        if ($template === '') {
            $template = '/{version}/models/{model}:generateContent';
        }
        if (!str_starts_with($template, '/')) {
            $template = '/' . $template;
        }

        $version = trim((string) (config('nanobanana.api_version') ?: 'v1beta'));
        if ($version === '') {
            $version = 'v1beta';
        }

        $path = str_replace(['{version}', '{model}'], [$version, rawurlencode($model)], $template);
        return $this->baseUrlForKey($apiKey) . $path;
    }

    private function legacyEndpointUrl(string $configKey, string $fallback, string $apiKey): string
    {
        $endpoint = trim((string) (config($configKey) ?: $fallback));
        if ($endpoint === '') {
            $endpoint = $fallback;
        }
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        return $this->baseUrlForKey($apiKey) . $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiGenerationConfig(): array
    {
        $config = [];

        $modalities = $this->normalizeResponseModalities();
        if (!empty($modalities)) {
            $config['responseModalities'] = $modalities;
        }

        $imageConfig = $this->normalizeImageConfig();
        if (!empty($imageConfig)) {
            $config['imageConfig'] = $imageConfig;
        }

        return $config;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeResponseModalities(): array
    {
        $raw = config('nanobanana.response_modalities', 'IMAGE');
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[\s,;|]+/', (string) $raw) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $value = strtoupper(trim((string) $item));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        if (empty($normalized)) {
            return ['TEXT', 'IMAGE'];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, string>
     */
    private function normalizeImageConfig(): array
    {
        $imageConfig = [];
        $aspectRatio = trim((string) (config('nanobanana.aspect_ratio') ?: ''));
        $rawSize = trim((string) (config('nanobanana.image_size') ?: ''));

        if ($aspectRatio === '' && preg_match('/^(\d+)\s*x\s*(\d+)$/i', $rawSize, $m) === 1) {
            $ratio = $this->ratioFromDimensions((int) $m[1], (int) $m[2]);
            if ($ratio !== null) {
                $aspectRatio = $ratio;
            }
        }

        if ($aspectRatio !== '') {
            $imageConfig['aspectRatio'] = $aspectRatio;
        }

        $sizeNormalized = strtoupper($rawSize);
        if (in_array($sizeNormalized, ['512PX', '1K', '2K', '4K'], true)) {
            $imageConfig['imageSize'] = $sizeNormalized;
        }

        return $imageConfig;
    }

    private function ratioFromDimensions(int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $g = $this->gcd($width, $height);
        $w = (int) ($width / max(1, $g));
        $h = (int) ($height / max(1, $g));
        $ratio = $w . ':' . $h;

        $allowed = [
            '1:1', '9:16', '16:9', '4:3', '3:4', '3:2', '2:3', '4:5', '5:4', '1:4', '4:1', '1:8', '8:1', '21:9',
        ];
        if (in_array($ratio, $allowed, true)) {
            return $ratio;
        }

        $target = $width / $height;
        $nearest = null;
        $minDiff = 1.0;
        foreach ($allowed as $candidate) {
            [$cw, $ch] = array_map('intval', explode(':', $candidate, 2));
            $diff = abs($target - ($cw / $ch));
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $nearest = $candidate;
            }
        }

        return $minDiff <= 0.03 ? $nearest : null;
    }

    private function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);
        if ($a === 0) {
            return max(1, $b);
        }
        if ($b === 0) {
            return max(1, $a);
        }

        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return max(1, $a);
    }

    private function normalizeGeminiModelName(string $model): string
    {
        $clean = trim($model);
        if ($clean === '') {
            return 'gemini-2.5-flash-image';
        }

        return match (strtolower($clean)) {
            'nano-banana', 'nanobanana', 'nano_banana', 'banana' => 'gemini-2.5-flash-image',
            default => $clean,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBase64FromGeminiPayload(array $payload, string $apiKey = '', int $timeout = 120): string
    {
        $candidates = (array) data_get($payload, 'candidates', []);
        foreach ($candidates as $candidate) {
            $parts = (array) data_get($candidate, 'content.parts', []);
            foreach ($parts as $part) {
                $value = trim((string) data_get($part, 'inlineData.data', data_get($part, 'inline_data.data', '')));
                if ($value !== '') {
                    return $value;
                }

                $downloadCandidate = $this->extractGeminiDownloadCandidate((array) $part);
                if ($downloadCandidate !== '' && $apiKey !== '') {
                    $downloaded = $this->downloadGeminiImageAsBase64($downloadCandidate, $apiKey, $timeout);
                    if ($downloaded !== '') {
                        return $downloaded;
                    }
                }
            }
        }

        foreach ([
            'generatedImages.0.image.imageBytes',
            'generatedImages.0.image.bytesBase64Encoded',
            'generated_images.0.image.image_bytes',
            'generated_images.0.image.bytes_base64_encoded',
            'candidates.0.content.parts.0.inlineData.data',
            'candidates.0.content.parts.0.inline_data.data',
            'data.0.b64_json',
            'data.0.b64',
        ] as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($apiKey !== '') {
            $downloadCandidate = $this->extractGeminiDownloadCandidate($payload);
            if ($downloadCandidate !== '') {
                return $this->downloadGeminiImageAsBase64($downloadCandidate, $apiKey, $timeout);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{b64:string,b64_json:string,raw:array}|null
     */
    private function retryGeminiImageRequestWithoutText(string $url, array $payload, string $apiKey, int $timeout): ?array
    {
        $currentModalities = array_values(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            (array) data_get($payload, 'generationConfig.responseModalities', [])
        )));

        if (empty($currentModalities)) {
            return null;
        }

        if ($currentModalities === ['IMAGE']) {
            return null;
        }

        $retryPayload = $payload;
        $retryPayload['generationConfig'] = is_array($retryPayload['generationConfig'] ?? null)
            ? $retryPayload['generationConfig']
            : [];
        $retryPayload['generationConfig']['responseModalities'] = ['IMAGE'];

        $retryResponse = $this->request($timeout, $apiKey, true)
            ->retry(1, 300)
            ->post($url, $retryPayload);

        if (!$retryResponse->successful()) {
            return null;
        }

        $retryData = $retryResponse->json();
        if (!is_array($retryData)) {
            return null;
        }

        $retryB64 = $this->extractBase64FromGeminiPayload($retryData, $apiKey, $timeout);
        if ($retryB64 === '') {
            return null;
        }

        return [
            'b64' => $retryB64,
            'b64_json' => $retryB64,
            'raw' => $retryData,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function missingGeminiImageMessage(array $payload, string $context): string
    {
        $finishReason = trim((string) data_get($payload, 'candidates.0.finishReason', data_get($payload, 'candidates.0.finish_reason', '')));
        $text = $this->extractGeminiText($payload);
        $parts = ["Missing base64 image field in {$context}."];

        if ($finishReason !== '') {
            $parts[] = 'finishReason=' . $finishReason . '.';
        }

        if ($text !== '') {
            $parts[] = 'Provider text=' . $text . '.';
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    private function extractGeminiDownloadCandidate(array $payload): string
    {
        $candidates = [
            'candidates.0.content.parts.0.fileData.fileUri',
            'candidates.0.content.parts.0.fileData.file_uri',
            'candidates.0.content.parts.0.fileData.uri',
            'candidates.0.content.parts.0.fileData.downloadUri',
            'candidates.0.content.parts.0.fileData.download_uri',
            'candidates.0.content.parts.0.file_data.file_uri',
            'candidates.0.content.parts.0.file_data.uri',
            'candidates.0.content.parts.0.file_data.download_uri',
            'generatedImages.0.image.fileUri',
            'generatedImages.0.image.file_uri',
            'generatedImages.0.image.downloadUri',
            'generatedImages.0.image.download_uri',
            'generatedImages.0.image.uri',
            'generated_images.0.image.file_uri',
            'generated_images.0.image.download_uri',
            'generated_images.0.image.uri',
            'fileData.fileUri',
            'fileData.file_uri',
            'fileData.downloadUri',
            'fileData.download_uri',
            'fileData.uri',
            'file_data.file_uri',
            'file_data.download_uri',
            'file_data.uri',
            'downloadUri',
            'download_uri',
            'uri',
            'fileUri',
            'file_uri',
            'name',
            'fileData.name',
            'file_data.name',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($this->looksLikeGeminiImageDownloadCandidate($value)) {
                return $value;
            }
        }

        return $this->findFirstMatchingStringRecursive($payload, fn (string $value) => $this->looksLikeGeminiImageDownloadCandidate($value));
    }

    private function looksLikeGeminiImageDownloadCandidate(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/\.(png|jpg|jpeg|webp)(?:\?|$)/i', $value) === 1) {
            return true;
        }

        if ((str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))
            && (
                str_contains($value, ':download')
                || str_contains($value, 'alt=media')
                || str_contains($value, '/files/')
                || str_contains($value, '/download/')
            )
        ) {
            return true;
        }

        return preg_match('/^(?:v1(?:alpha|beta)\d*\/)?files\/[A-Za-z0-9._:-]+$/i', ltrim($value, '/')) === 1;
    }

    private function downloadGeminiImageAsBase64(string $downloadCandidate, string $apiKey, int $timeout): string
    {
        $url = $this->resolveGeminiDownloadUrl($downloadCandidate, $apiKey, $timeout);
        if ($url === '') {
            return '';
        }

        $response = $this->request($timeout, $apiKey, false)->get($url);
        if (!$response->successful()) {
            return '';
        }

        $body = $response->body();
        if (!is_string($body) || $body === '') {
            return '';
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type')));
        if (str_contains($contentType, 'application/json')) {
            $data = $response->json();
            if (is_array($data)) {
                $nextCandidate = $this->extractGeminiDownloadCandidate($data);
                if ($nextCandidate !== '' && trim($nextCandidate) !== trim($downloadCandidate)) {
                    return $this->downloadGeminiImageAsBase64($nextCandidate, $apiKey, $timeout);
                }
            }

            return '';
        }

        return base64_encode($body);
    }

    private function resolveGeminiDownloadUrl(string $downloadCandidate, string $apiKey, int $timeout): string
    {
        $downloadCandidate = trim($downloadCandidate);
        if ($downloadCandidate === '') {
            return '';
        }

        if ($this->isDirectGeminiMediaUrl($downloadCandidate)) {
            return $downloadCandidate;
        }

        $metadataUrl = $this->geminiFileUrl($downloadCandidate);
        if ($metadataUrl === '') {
            return '';
        }

        if (str_ends_with($metadataUrl, ':download')) {
            return $metadataUrl;
        }

        $metadataResponse = $this->request($timeout, $apiKey, true)->get($metadataUrl);
        if ($metadataResponse->successful()) {
            $metadata = $metadataResponse->json();
            if (is_array($metadata)) {
                foreach (['downloadUri', 'download_uri', 'uri', 'fileUri', 'file_uri'] as $field) {
                    $value = trim((string) data_get($metadata, $field, ''));
                    if ($this->isDirectGeminiMediaUrl($value)) {
                        return $value;
                    }
                }
            }
        }

        return $metadataUrl . ':download';
    }

    private function isDirectGeminiMediaUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://'))) {
            return false;
        }

        return str_contains($value, ':download')
            || str_contains($value, 'alt=media')
            || str_contains($value, '/download/')
            || preg_match('/\.(png|jpg|jpeg|webp)(?:\?|$)/i', $value) === 1;
    }

    private function geminiFileUrl(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            $path = trim((string) parse_url($normalized, PHP_URL_PATH));
            if ($path === '') {
                return '';
            }
            $normalized = $path;
        }

        $normalized = ltrim($normalized, '/');
        $version = trim((string) (config('nanobanana.api_version') ?: 'v1beta'));
        $normalized = preg_replace('/^download\/' . preg_quote($version, '/') . '\//i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^' . preg_quote($version, '/') . '\//i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[:?].*$/', '', $normalized) ?? $normalized;

        if (!str_starts_with($normalized, 'files/')) {
            $offset = stripos($normalized, 'files/');
            if ($offset === false) {
                return '';
            }
            $normalized = substr($normalized, $offset);
        }

        return rtrim($this->configuredBaseUrl(), '/') . '/' . $version . '/' . ltrim($normalized, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractGeminiText(array $payload): string
    {
        $candidates = (array) data_get($payload, 'candidates', []);
        $chunks = [];

        foreach ($candidates as $candidate) {
            $parts = (array) data_get($candidate, 'content.parts', []);
            foreach ($parts as $part) {
                $text = trim((string) data_get($part, 'text', ''));
                if ($text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        $text = trim(implode(' ', $chunks));
        if ($text === '') {
            $text = trim((string) data_get($payload, 'promptFeedback.blockReasonMessage', ''));
        }

        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, 220);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBase64FromPayload(array $payload): string
    {
        $candidates = [
            'data.0.b64_json',
            'data.0.b64',
            'output.0.b64_json',
            'output.0.b64',
            'image.b64_json',
            'image.b64',
            'image_base64',
            'b64_json',
            'b64',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
