<?php

namespace App\Services\Luma;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LumaVideoService
{
    public function __construct(
        private readonly LumaClient              $client,
        private readonly LumaPayloadBuilder      $builder,
        private readonly LumaVideoProfileResolver $resolver,
    ) {
    }

    /**
     * Generate a video via Luma with duration fallback chain.
     *
     * Tries requestedSeconds → next lower duration → 5s until one succeeds.
     * Returns absolute path on public disk where the video was saved.
     *
     * @param  string        $prompt
     * @param  int           $requestedSeconds     5, 10, or 15
     * @param  list<string>  $referenceUrls        Public URLs for keyframe/style reference
     * @param  string        $aspectRatio          e.g. "9:16" for reels
     * @param  string|null   $outputStoragePath    Relative path on public disk; auto-generated if null
     * @return array{path: string, abs: string, duration: string, model: string}
     *
     * @throws RuntimeException if all fallback attempts fail
     */
    public function generate(
        string  $prompt,
        int     $requestedSeconds = 5,
        array   $referenceUrls = [],
        string  $aspectRatio = '9:16',
        ?string $outputStoragePath = null,
    ): array {
        $chain  = $this->resolver->fallbackChain($requestedSeconds);
        $errors = [];

        foreach ($chain as $profile) {
            try {
                $generationId = $this->createGeneration($prompt, $profile, $referenceUrls, $aspectRatio);
                $videoUrl     = $this->poll($generationId);
                $savedPath    = $this->downloadToStorage($videoUrl, $outputStoragePath);

                $disk = Storage::disk('public');
                return [
                    'path'     => $savedPath,
                    'abs'      => $disk->path($savedPath),
                    'duration' => $profile['duration'],
                    'model'    => $profile['model'],
                ];
            } catch (RuntimeException $e) {
                $errors[] = "[{$profile['model']}/{$profile['duration']}] " . $e->getMessage();
                // Try next profile in chain
            }
        }

        throw new RuntimeException(
            'Luma video generation failed for all fallback profiles: ' . implode(' | ', $errors)
        );
    }

    private function createGeneration(
        string $prompt,
        array  $profile,
        array  $referenceUrls,
        string $aspectRatio,
    ): string {
        $startFrame = $referenceUrls[0] ?? null;
        $payload = $this->builder->videoPayload(
            prompt:        $prompt,
            model:         $profile['model'],
            duration:      $profile['duration'],
            aspectRatio:   $aspectRatio,
            referenceUrls: $referenceUrls,
            startFrameUrl: $startFrame,
        );

        $response = $this->client->post('/dream-machine/v1/generations', $payload);
        $id = (string) ($response['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Luma video generation returned no ID.');
        }
        return $id;
    }

    private function poll(string $id): string
    {
        $maxAttempts  = (int) config('luma.poll_max_attempts', 60);
        $pollInterval = (int) config('luma.poll_interval', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $this->client->get('/dream-machine/v1/generations/' . $id);
            $state  = (string) ($result['state'] ?? '');

            if ($state === 'completed') {
                $videoUrl = (string) data_get($result, 'assets.video', '');
                if ($videoUrl === '') {
                    throw new RuntimeException('Luma video completed but no video URL in response.');
                }
                return $videoUrl;
            }

            if ($state === 'failed') {
                $reason = (string) data_get($result, 'failure_reason', 'unknown');
                throw new RuntimeException("Luma video generation failed: {$reason}");
            }

            sleep($pollInterval);
        }

        throw new RuntimeException("Luma video generation timed out after {$maxAttempts} poll attempts.");
    }

    private function downloadToStorage(string $url, ?string $storagePath): string
    {
        $response = Http::timeout(300)->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Failed to download Luma video from {$url}: HTTP {$response->status()}");
        }

        if ($storagePath === null || $storagePath === '') {
            $storagePath = 'generated/videos/' . uniqid('luma_', true) . '.mp4';
        }

        Storage::disk('public')->put($storagePath, $response->body());
        return $storagePath;
    }
}
