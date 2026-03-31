<?php

namespace App\DTO;

class CanvaDesignPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $selectedImages
     * @param  array<string, mixed>|null  $logo
     * @param  array<int, array<string, mixed>>  $slides
     */
    public function __construct(
        public readonly string $channelFormat,
        public readonly string $headline,
        public readonly string $subheadline,
        public readonly string $body,
        public readonly string $cta,
        public readonly string $brandClaim,
        public readonly array $selectedImages = [],
        public readonly ?array $logo = null,
        public readonly ?array $chartBlock = null,
        public readonly array $slides = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel_format' => $this->channelFormat,
            'headline' => $this->headline,
            'subheadline' => $this->subheadline,
            'body' => $this->body,
            'cta' => $this->cta,
            'brand_claim' => $this->brandClaim,
            'selected_images' => $this->selectedImages,
            'logo' => $this->logo,
            'chart_block' => $this->chartBlock,
            'slides' => $this->slides,
        ];
    }
}
