<?php

namespace App\Services\Overlays;

final readonly class ContentOverlayStyle
{
    public function __construct(
        public string $fontFamily,
        public string $fontWeight,
        public string $fontSizeMode,
        public string $textCase,
        public string $alignment,
        public string $position,
        public string $safeArea,
        public int $maxLines,
        public string $color,
        public string $strokeColor,
        public bool $shadow,
        public string $backgroundStyle,
        public string $animationStyle,
        public string $fallbackFontFamily = ''
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fontFamily: (string) ($data['font_family'] ?? 'arial'),
            fontWeight: (string) ($data['font_weight'] ?? '700'),
            fontSizeMode: (string) ($data['font_size_mode'] ?? 'large'),
            textCase: (string) ($data['text_case'] ?? 'sentence'),
            alignment: (string) ($data['alignment'] ?? 'left'),
            position: (string) ($data['position'] ?? 'upper_left'),
            safeArea: (string) ($data['safe_area'] ?? 'upper_third'),
            maxLines: max(1, (int) ($data['max_lines'] ?? 2)),
            color: (string) ($data['color'] ?? '#FFFFFF'),
            strokeColor: (string) ($data['stroke_color'] ?? '#111827'),
            shadow: (bool) ($data['shadow'] ?? true),
            backgroundStyle: (string) ($data['background_style'] ?? 'dark_box'),
            animationStyle: (string) ($data['animation_style'] ?? 'fade'),
            fallbackFontFamily: (string) ($data['fallback_font_family'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'font_family' => $this->fontFamily,
            'font_weight' => $this->fontWeight,
            'font_size_mode' => $this->fontSizeMode,
            'text_case' => $this->textCase,
            'alignment' => $this->alignment,
            'position' => $this->position,
            'safe_area' => $this->safeArea,
            'max_lines' => $this->maxLines,
            'color' => $this->color,
            'stroke_color' => $this->strokeColor,
            'shadow' => $this->shadow,
            'background_style' => $this->backgroundStyle,
            'animation_style' => $this->animationStyle,
            'fallback_font_family' => $this->fallbackFontFamily,
        ];
    }
}
