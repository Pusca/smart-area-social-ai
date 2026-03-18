<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RunwayService
{
    private function baseUrl(): string
    {
        return rtrim((string) (config('runway.base_url') ?: 'https://api.dev.runwayml.com'), '/');
    }

    private function apiKey(): string
    {
        $key = trim((string) (config('runway.api_key') ?: env('RUNWAY_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing RUNWAY_API_KEY');
        }

        return $key;
    }

    private function request(int $timeout): PendingRequest
    {
        $req = Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('runway.connect_timeout') ?: 15));

        $version = trim((string) (config('runway.api_version') ?: ''));
        if ($version !== '') {
            $req = $req->withHeaders([
                'X-Runway-Version' => $version,
            ]);
        }

        return $req;
    }

    private function createUrl(): string
    {
        $endpoint = (string) (config('runway.create_endpoint') ?: '/v1/image_to_video');
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        return $this->baseUrl() . $endpoint;
    }

    private function retrieveUrl(string $id): string
    {
        $endpoint = (string) (config('runway.retrieve_endpoint') ?: '/v1/tasks/{id}');
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }
        $endpoint = str_replace('{id}', rawurlencode($id), $endpoint);

        return $this->baseUrl() . $endpoint;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{id:string,raw:array}
     */
    public function createVideoJob(string $prompt, ?string $inputReferenceAbsolutePath = null, array $options = []): array
    {
        $model = strtolower(trim((string) ($options['model'] ?? config('runway.model') ?: 'gen4.5')));
        if ($model === '' || str_starts_with($model, 'sora-') || str_starts_with($model, 'kling-')) {
            $model = strtolower(trim((string) (config('runway.model') ?: 'gen4.5')));
        }
        if ($model === '' || str_starts_with($model, 'sora-') || str_starts_with($model, 'kling-')) {
            $model = 'gen4.5';
        }
        if (in_array($model, ['gen4_turbo', 'gen4-turbo'], true)) {
            $model = 'gen4.5';
        }
        $seconds = (int) ($options['seconds'] ?? config('runway.video_seconds') ?: 8);
        $seconds = max(3, min(15, $seconds));
        $size = trim((string) ($options['size'] ?? ''));
        $ratio = $this->normalizeRatio((string) (config('runway.video_ratio') ?: ''), $size);
        $safePrompt = $this->normalizePrompt($prompt);

        $payload = [
            'model' => $model,
            'promptText' => $safePrompt,
            'duration' => $seconds,
        ];

        if ($ratio !== '') {
            $payload['ratio'] = $ratio;
        }

        if (is_string($inputReferenceAbsolutePath) && $inputReferenceAbsolutePath !== '' && is_file($inputReferenceAbsolutePath)) {
            $payload['promptImage'] = $this->toImageDataUri($inputReferenceAbsolutePath);
        }

        $url = $this->createUrl();
        $timeout = (int) (config('runway.timeout_create') ?: 60);
        $res = $this->request($timeout)->retry(1, 350)->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException("Runway video create error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $data = $res->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Runway create response payload.');
        }

        $id = trim((string) (
            $data['id']
            ?? data_get($data, 'task.id')
            ?? data_get($data, 'taskId')
            ?? ''
        ));
        if ($id === '') {
            throw new RuntimeException('Missing Runway task id in create response.');
        }

        return [
            'id' => $id,
            'raw' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveVideoJob(string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('Missing Runway task id.');
        }

        $url = $this->retrieveUrl($taskId);
        $timeout = (int) (config('runway.timeout_poll') ?: 60);
        $res = $this->request($timeout)->get($url);
        if (!$res->successful()) {
            throw new RuntimeException("Runway video retrieve error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $data = $res->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Runway retrieve response payload.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForVideoCompletion(string $taskId): array
    {
        $pollEvery = (int) (config('runway.poll_interval') ?: 8);
        $pollEvery = max(2, min(30, $pollEvery));
        $timeout = (int) (config('runway.poll_timeout') ?: 420);
        $timeout = max(30, $timeout);
        $deadline = microtime(true) + $timeout;

        do {
            $job = $this->retrieveVideoJob($taskId);
            $statusRaw = (string) ($job['status'] ?? data_get($job, 'task.status', ''));
            $status = strtolower(trim($statusRaw));

            if (in_array($status, ['completed', 'succeeded', 'done', 'success'], true)) {
                return $job;
            }

            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                $reason = $this->extractFailureReason($job);
                throw new RuntimeException("Runway video generation failed: {$reason}");
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Runway video generation timeout after {$timeout}s (status={$statusRaw})");
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
            throw new RuntimeException('Runway completed payload missing downloadable video URL.');
        }

        return $this->downloadBinary($url, (int) (config('runway.timeout_download') ?: 240));
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
            return $this->downloadBinary($url, (int) (config('runway.timeout_download') ?: 240));
        } catch (\Throwable) {
            return null;
        }
    }

    private function downloadBinary(string $url, int $timeout): string
    {
        $res = Http::timeout($timeout)
            ->connectTimeout((int) (config('runway.connect_timeout') ?: 15))
            ->get($url);

        if (!$res->successful()) {
            throw new RuntimeException("Runway asset download error ({$res->status()}) URL={$url}");
        }

        $body = $res->body();
        if (!is_string($body) || $body === '') {
            throw new RuntimeException("Runway asset download empty body URL={$url}");
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractVideoUrl(array $payload): string
    {
        $candidates = [
            'output.0.url',
            'output.0.video_url',
            'output.video_url',
            'video_url',
            'result.video_url',
            'artifacts.0.url',
            'assets.0.url',
            'task.output.0.url',
            'task.output.video_url',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($this->isLikelyVideoUrl($value)) {
                return $value;
            }
        }

        return $this->findFirstUrlRecursive($payload, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractThumbnailUrl(array $payload): string
    {
        $candidates = [
            'output.0.thumbnail_url',
            'output.0.preview_image_url',
            'output.thumbnail_url',
            'thumbnail_url',
            'preview_image_url',
            'assets.0.thumbnail_url',
            'task.output.0.thumbnail_url',
        ];

        foreach ($candidates as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($this->isLikelyImageUrl($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  mixed  $value
     */
    private function findFirstUrlRecursive($value, bool $preferVideo = false): string
    {
        if (is_string($value)) {
            $candidate = trim($value);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL)) {
                return '';
            }
            if ($preferVideo) {
                return $this->isLikelyVideoUrl($candidate) ? $candidate : '';
            }
            return $candidate;
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $node) {
            $url = $this->findFirstUrlRecursive($node, $preferVideo);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function isLikelyVideoUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $l = strtolower($url);
        if (str_contains($l, '.mp4') || str_contains($l, '.mov') || str_contains($l, '.webm') || str_contains($l, '.m4v')) {
            return true;
        }

        return str_contains($l, 'video') || str_contains($l, 'mp4');
    }

    private function isLikelyImageUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $l = strtolower($url);
        if (str_contains($l, '.jpg') || str_contains($l, '.jpeg') || str_contains($l, '.png') || str_contains($l, '.webp')) {
            return true;
        }

        return str_contains($l, 'thumb') || str_contains($l, 'preview') || str_contains($l, 'image');
    }

    private function normalizeRatio(string $configuredRatio, string $size): string
    {
        $configuredRatio = trim($configuredRatio);
        if ($configuredRatio !== '') {
            $mapped = $this->mapRatioToRunway($configuredRatio);
            if ($mapped !== '') {
                return $mapped;
            }
        }

        if (!preg_match('/^(\d{2,5})x(\d{2,5})$/', trim(strtolower($size)), $m)) {
            return '720:1280';
        }

        $w = (int) $m[1];
        $h = (int) $m[2];
        if ($w <= 0 || $h <= 0) {
            return '720:1280';
        }

        return $this->closestRunwayRatio($w, $h);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function extractFailureReason(array $job): string
    {
        $message = trim((string) (
            data_get($job, 'error.message')
            ?? data_get($job, 'task.error.message')
            ?? data_get($job, 'failure_reason')
            ?? data_get($job, 'reason')
            ?? ''
        ));

        $code = trim((string) (
            data_get($job, 'error.code')
            ?? data_get($job, 'task.error.code')
            ?? data_get($job, 'failure_code')
            ?? ''
        ));

        $status = trim((string) (
            $job['status']
            ?? data_get($job, 'task.status')
            ?? ''
        ));

        if ($message !== '' && $code !== '' && !str_contains(strtolower($message), strtolower($code))) {
            return $code . ': ' . $message;
        }

        if ($message !== '') {
            return $message;
        }

        if ($code !== '') {
            return $code;
        }

        if ($status !== '') {
            return 'video_generation_failed (status=' . $status . ')';
        }

        return 'video_generation_failed';
    }

    private function normalizePrompt(string $prompt): string
    {
        $prompt = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        if ($prompt === '') {
            $prompt = 'Generate a coherent social reel video from the provided references.';
        }

        $limit = (int) (config('runway.max_prompt_chars') ?: 980);
        $limit = max(200, min(1000, $limit));
        if (mb_strlen($prompt, 'UTF-8') > $limit) {
            $prompt = trim(mb_substr($prompt, 0, $limit, 'UTF-8'));
        }

        return $prompt;
    }

    private function mapRatioToRunway(string $ratio): string
    {
        $ratio = strtolower(trim($ratio));
        if ($ratio === '') {
            return '';
        }

        $allowed = $this->allowedRunwayRatios();
        foreach ($allowed as $candidate) {
            if (strtolower($candidate) === $ratio) {
                return $candidate;
            }
        }

        $shortMap = [
            '16:9' => '1280:720',
            '9:16' => '720:1280',
            '4:3' => '1104:832',
            '3:4' => '832:1104',
            '1:1' => '960:960',
            '21:9' => '1584:672',
        ];
        if (isset($shortMap[$ratio])) {
            return $shortMap[$ratio];
        }

        if (preg_match('/^(\d{2,5})x(\d{2,5})$/', $ratio, $m) === 1) {
            return $this->closestRunwayRatio((int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^(\d{1,3}):(\d{1,3})$/', $ratio, $m) === 1) {
            return $this->closestRunwayRatio((int) $m[1], (int) $m[2]);
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function allowedRunwayRatios(): array
    {
        return ['1280:720', '720:1280', '1104:832', '832:1104', '960:960', '1584:672'];
    }

    private function closestRunwayRatio(int $w, int $h): string
    {
        $target = $w / max(1, $h);
        $best = '720:1280';
        $bestDiff = INF;

        foreach ($this->allowedRunwayRatios() as $candidate) {
            [$cw, $ch] = array_map('intval', explode(':', $candidate, 2));
            if ($cw <= 0 || $ch <= 0) {
                continue;
            }
            $diff = abs($target - ($cw / $ch));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function toImageDataUri(string $absolutePath): string
    {
        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (!str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Runway input reference must be an image file.');
        }

        $bytes = @file_get_contents($absolutePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to read Runway input reference image.');
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
