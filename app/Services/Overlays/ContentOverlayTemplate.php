<?php

namespace App\Services\Overlays;

final readonly class ContentOverlayTemplate
{
    /**
     * @param  array<int, string>  $emphasisWords
     */
    public function __construct(
        public string $role,
        public string $text,
        public string $secondaryText,
        public ContentOverlayStyle $style,
        public int $timingStartMs = 0,
        public int $timingEndMs = 0,
        public array $emphasisWords = [],
        public bool $enabled = true
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) ($data['role'] ?? 'primary'),
            text: (string) ($data['text'] ?? ''),
            secondaryText: (string) ($data['secondary_text'] ?? ''),
            style: ContentOverlayStyle::fromArray($data),
            timingStartMs: max(0, (int) ($data['timing_start_ms'] ?? 0)),
            timingEndMs: max(0, (int) ($data['timing_end_ms'] ?? 0)),
            emphasisWords: array_values(array_filter(array_map('strval', (array) ($data['emphasis_words'] ?? [])))),
            enabled: (bool) ($data['enabled'] ?? true)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            [
                'role' => $this->role,
                'text' => $this->text,
                'secondary_text' => $this->secondaryText,
                'timing_start_ms' => $this->timingStartMs,
                'timing_end_ms' => $this->timingEndMs,
                'emphasis_words' => $this->emphasisWords,
                'enabled' => $this->enabled,
            ],
            $this->style->toArray()
        );
    }
}
