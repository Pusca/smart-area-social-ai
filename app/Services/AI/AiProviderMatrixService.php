<?php

namespace App\Services\AI;

use App\Support\GraderProviderResolver;
use App\Support\ImageProviderResolver;
use App\Support\TextProviderResolver;
use App\Support\VideoProviderResolver;

class AiProviderMatrixService
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function resolve(array $meta = []): array
    {
        $text = TextProviderResolver::resolve(
            (string) data_get($meta, 'provider_matrix.text.provider', data_get($meta, 'text_provider', '')),
            TextProviderResolver::default()
        );
        $grader = GraderProviderResolver::resolve(
            (string) data_get($meta, 'provider_matrix.grader.provider', data_get($meta, 'grader_provider', '')),
            GraderProviderResolver::default()
        );
        $image = ImageProviderResolver::resolve(
            (string) data_get($meta, 'provider_matrix.image.provider', data_get($meta, 'image_provider', '')),
            ImageProviderResolver::default()
        );
        $video = VideoProviderResolver::resolve(
            (string) data_get($meta, 'provider_matrix.video.provider', data_get($meta, 'video_provider', '')),
            VideoProviderResolver::default()
        );

        return [
            'text' => [
                'provider' => $text,
                'available' => TextProviderResolver::allowed(),
            ],
            'grader' => [
                'provider' => $grader,
                'available' => GraderProviderResolver::allowed(),
            ],
            'image' => [
                'provider' => $image,
                'available' => ImageProviderResolver::allowed(),
            ],
            'video' => [
                'provider' => $video,
                'available' => VideoProviderResolver::allowed(),
            ],
            'resolved_at' => now()->toDateTimeString(),
        ];
    }
}
