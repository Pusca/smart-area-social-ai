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
        $payload = $this->builder->imagePayload(
            prompt:        $prompt,
            aspectRatio:   $aspectRatio,
            referenceUrls: $referenceUrls,
        );

        $response   = $this->client->post('/v1/generations', $payload);
        $generation = $this->poll((string) ($response['id'] ?? ''));

        // Luma Agents returns the image URL in different possible locations
        $imageUrl = (string) (
            data_get($generation, 'output.url') ?:
            data_get($generation, 'assets.image') ?:
            data_get($generation, 'result.url') ?:
            data_get($generation, 'url') ?:
            ''
        );
        if ($imageUrl === '') {
            throw new RuntimeException('Luma Agents image generation completed but no image URL in response. Full response: ' . json_encode($generation));
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
            $result = $this->client->get('/v1/generations/' . $id);
            $state  = strtolower((string) ($result['state'] ?? $result['status'] ?? ''));

            if (in_array($state, ['completed', 'succeeded', 'success', 'done'], true)) {
                return $result;
            }

            if (in_array($state, ['failed', 'error', 'cancelled'], true)) {
                $reason = (string) data_get($result, 'failure_reason', data_get($result, 'error', 'unknown'));
                throw new RuntimeException("Luma Agents image generation failed: {$reason}");
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

}
