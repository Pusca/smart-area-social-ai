<?php

namespace App\Services\Luma;

class LumaPayloadBuilder
{
    /**
     * Build payload for POST /v1/generations (Luma Agents — uni-1 model)
     *
     * Generation:  { prompt, aspect_ratio }
     * Edit:        { type: "image_edit", prompt, source: { url } }
     *
     * @param  string        $prompt
     * @param  string        $aspectRatio    e.g. "1:1", "9:16", "16:9"
     * @param  list<string>  $referenceUrls  Public URLs — first is used as source for image_edit
     */
    public function imagePayload(
        string $prompt,
        string $aspectRatio = '1:1',
        array  $referenceUrls = [],
    ): array {
        // If we have reference images, use image_edit mode with the first reference as source
        if (!empty($referenceUrls)) {
            return [
                'type'         => 'image_edit',
                'prompt'       => $prompt,
                'aspect_ratio' => $aspectRatio,
                'source'       => ['url' => $referenceUrls[0]],
            ];
        }

        return [
            'prompt'       => $prompt,
            'aspect_ratio' => $aspectRatio,
        ];
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
