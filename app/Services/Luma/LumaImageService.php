<?php

namespace App\Services\Luma;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LumaImageService
{
    public function __construct(
        private readonly LumaClient         $client,
        private readonly LumaPayloadBuilder $builder,
    ) {
    }

    /**
     * Generate an image via Luma, poll until complete, and return raw binary data.
     *
     * @param  string        $prompt
     * @param  list<string>  $referenceUrls   Public URLs (brand refs, identity refs)
     * @param  string        $aspectRatio     e.g. "1:1", "9:16"
     * @param  bool          $fast            Use photon-flash-1 instead of photon-1
     * @return string                         Raw image binary (JPEG/PNG)
     *
     * @throws RuntimeException on API error or generation failure
     */
    public function generate(
        string $prompt,
        array  $referenceUrls = [],
        string $aspectRatio = '1:1',
        bool   $fast = false,
    ): string {
        $model   = $fast
            ? (string) config('luma.image.model_fast', 'photon-flash-1')
            : (string) config('luma.image.model_default', 'photon-1');

        $referenceMode = $this->resolveReferenceMode($referenceUrls);

        $payload = $this->builder->imagePayload(
            prompt:        $prompt,
            model:         $model,
            aspectRatio:   $aspectRatio,
            referenceUrls: $referenceUrls,
            referenceMode: $referenceMode,
        );

        $response   = $this->client->post('/dream-machine/v1/generations/image', $payload);
        $generation = $this->poll((string) ($response['id'] ?? ''));

        $imageUrl = (string) data_get($generation, 'assets.image', '');
        if ($imageUrl === '') {
            throw new RuntimeException('Luma image generation completed but no image URL in response.');
        }

        return $this->downloadBinary($imageUrl);
    }

    /**
     * Poll generation status until 'completed' or 'failed'.
     */
    private function poll(string $id): array
    {
        if ($id === '') {
            throw new RuntimeException('Luma image generation returned no ID.');
        }

        $maxAttempts  = (int) config('luma.poll_max_attempts', 60);
        $pollInterval = (int) config('luma.poll_interval', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $this->client->get('/dream-machine/v1/generations/' . $id);
            $state  = (string) ($result['state'] ?? '');

            if ($state === 'completed') {
                return $result;
            }

            if ($state === 'failed') {
                $reason = (string) data_get($result, 'failure_reason', 'unknown');
                throw new RuntimeException("Luma image generation failed: {$reason}");
            }

            sleep($pollInterval);
        }

        throw new RuntimeException("Luma image generation timed out after {$maxAttempts} attempts.");
    }

    private function downloadBinary(string $url): string
    {
        $response = Http::timeout(120)->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Failed to download Luma image from {$url}: HTTP {$response->status()}");
        }
        return $response->body();
    }

    /**
     * Pick the best reference mode based on available URLs.
     * When URLs represent people/brand identity → character_ref.
     * Single URL as style source → style_ref.
     */
    private function resolveReferenceMode(array $referenceUrls): string
    {
        if (empty($referenceUrls)) {
            return 'image_ref';
        }
        // Multiple references → likely identity/character lock
        if (count($referenceUrls) > 1) {
            return 'character_ref';
        }
        return 'image_ref';
    }
}
