<?php

namespace App\Services\Luma;

class LumaPayloadBuilder
{
    /**
     * Build payload for POST /dream-machine/v1/generations/image
     *
     * @param  string        $prompt
     * @param  string        $model          photon-1 | photon-flash-1
     * @param  string        $aspectRatio    e.g. "1:1", "9:16", "16:9"
     * @param  list<string>  $referenceUrls  Public URLs for character_ref / image_ref
     * @param  string        $referenceMode  character_ref | image_ref | style_ref | modify_image_ref
     */
    public function imagePayload(
        string $prompt,
        string $model,
        string $aspectRatio = '1:1',
        array  $referenceUrls = [],
        string $referenceMode = 'character_ref',
    ): array {
        $payload = [
            'model'        => $model,
            'prompt'       => $prompt,
            'aspect_ratio' => $aspectRatio,
        ];

        if (!empty($referenceUrls)) {
            // Luma supports up to 4 reference images for character_ref
            $urls = array_values(array_slice($referenceUrls, 0, 4));
            $payload[$referenceMode] = ['images' => array_map(fn ($u) => ['url' => $u], $urls)];
        }

        return $payload;
    }

    /**
     * Build payload for POST /dream-machine/v1/generations (video)
     *
     * @param  string        $prompt
     * @param  string        $model          ray-2 | ray-flash-2
     * @param  string        $duration       '5s' | '10s' | '15s'
     * @param  string        $aspectRatio    e.g. "9:16" for reels
     * @param  list<string>  $referenceUrls  Public URLs for keyframes or image_ref
     * @param  string|null   $startFrameUrl  Optional: image for first keyframe
     */
    public function videoPayload(
        string  $prompt,
        string  $model,
        string  $duration,
        string  $aspectRatio = '9:16',
        array   $referenceUrls = [],
        ?string $startFrameUrl = null,
    ): array {
        $payload = [
            'model'        => $model,
            'prompt'       => $prompt,
            'duration'     => $duration,
            'aspect_ratio' => $aspectRatio,
        ];

        // Use start frame as keyframe[0] if provided
        if ($startFrameUrl !== null && $startFrameUrl !== '') {
            $payload['keyframes'] = [
                'frame0' => ['type' => 'image', 'url' => $startFrameUrl],
            ];
        } elseif (!empty($referenceUrls)) {
            // First reference URL as the start keyframe for visual consistency
            $payload['keyframes'] = [
                'frame0' => ['type' => 'image', 'url' => $referenceUrls[0]],
            ];
        }

        return $payload;
    }
}
