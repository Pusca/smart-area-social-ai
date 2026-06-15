<?php

namespace App\Services\Overlays;

final readonly class ContentOverlayPreset
{
    public function __construct(
        public string $key,
        public string $label,
        public string $tone,
        public ContentOverlayStyle $style
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            label: (string) ($data['label'] ?? $key),
            tone: (string) ($data['tone'] ?? 'modern'),
            style: ContentOverlayStyle::fromArray($data)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'tone' => $this->tone,
            'style' => $this->style->toArray(),
        ];
    }
}
