<?php

namespace App\Services\Overlays;

use Illuminate\Support\Str;

final class ContentOverlayReadabilityService
{
    /**
     * @param  array<string, mixed>  $overlayMeta
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function evaluate(array $overlayMeta, ?string $assetPath = null, array $context = []): array
    {
        $templates = array_values(array_filter(
            (array) ($overlayMeta['templates'] ?? []),
            fn ($row) => is_array($row) && (bool) ($row['enabled'] ?? true)
        ));

        if ($templates === []) {
            return [
                'contrast_score' => null,
                'safe_area_score' => null,
                'overlap_risk' => null,
                'mobile_readability' => null,
                'overall_score' => null,
                'warnings' => ['Nessun overlay attivo da valutare.'],
                'template_breakdown' => [],
                'evaluated_at' => now()->toDateTimeString(),
            ];
        }

        $breakdown = [];
        foreach ($templates as $template) {
            $breakdown[] = $this->evaluateTemplate($template, $assetPath, $context);
        }

        $contrast = $this->average($breakdown, 'contrast_score');
        $safeArea = $this->average($breakdown, 'safe_area_score');
        $mobileReadability = $this->average($breakdown, 'mobile_readability');
        $overlapRisk = $this->average($breakdown, 'overlap_risk');
        $overall = round(max(0.0, min(1.0, (($contrast * 0.34) + ($safeArea * 0.24) + ($mobileReadability * 0.26) + ((1 - $overlapRisk) * 0.16)))), 4);

        $warnings = [];
        if ($contrast < 0.62) {
            $warnings[] = 'Contrasto overlay sotto soglia: serve box o colore piu leggibile.';
        }
        if ($safeArea < 0.7) {
            $warnings[] = 'Overlay fuori dalla safe area preferita per uso mobile/social.';
        }
        if ($overlapRisk > 0.45) {
            $warnings[] = 'Rischio overlap alto: testo troppo denso o posizione troppo invasiva.';
        }
        if ($mobileReadability < 0.68) {
            $warnings[] = 'Mobile readability bassa: ridurre parole o aumentare il font.';
        }

        return [
            'contrast_score' => $contrast,
            'safe_area_score' => $safeArea,
            'overlap_risk' => $overlapRisk,
            'mobile_readability' => $mobileReadability,
            'overall_score' => $overall,
            'warnings' => array_values(array_unique($warnings)),
            'template_breakdown' => $breakdown,
            'evaluated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evaluateTemplate(array $template, ?string $assetPath, array $context): array
    {
        $text = trim((string) ($template['text'] ?? ''));
        $secondary = trim((string) ($template['secondary_text'] ?? ''));
        $allText = trim($text . ' ' . $secondary);
        $maxLines = max(1, (int) ($template['max_lines'] ?? 2));
        $fontSizeMode = Str::lower(trim((string) ($template['font_size_mode'] ?? 'large')));
        $position = Str::lower(trim((string) ($template['position'] ?? 'upper_left')));
        $safeArea = Str::lower(trim((string) ($template['safe_area'] ?? 'upper_third')));
        $backgroundStyle = Str::lower(trim((string) ($template['background_style'] ?? 'none')));
        $textColor = (string) ($template['color'] ?? '#FFFFFF');

        $baseLuminance = $this->resolveBackdropLuminance($assetPath, $safeArea, $backgroundStyle);
        $contrast = $this->contrastScore($textColor, $baseLuminance, $backgroundStyle);

        $safeAreaScore = 0.62;
        if (
            ($safeArea === 'upper_third' && in_array($position, ['upper_left', 'upper_center'], true))
            || ($safeArea === 'center_safe' && in_array($position, ['center', 'center_left'], true))
            || ($safeArea === 'lower_third' && in_array($position, ['lower_left', 'lower_center'], true))
        ) {
            $safeAreaScore = 0.92;
        } elseif (str_contains($position, 'upper') || str_contains($position, 'lower') || str_contains($position, 'center')) {
            $safeAreaScore = 0.78;
        }

        $length = mb_strlen($allText, 'UTF-8');
        $lineDensityPenalty = max(0.0, (($length / max(1, $maxLines)) - 24) / 60);
        $sizeBase = match ($fontSizeMode) {
            'xl' => 0.96,
            'large' => 0.86,
            'medium' => 0.74,
            default => 0.58,
        };
        $mobileReadability = round(max(0.0, min(1.0, $sizeBase - min(0.28, $lineDensityPenalty))), 4);

        $overlapRisk = 0.16;
        if ($backgroundStyle === 'none') {
            $overlapRisk += 0.12;
        }
        if ($length > 48) {
            $overlapRisk += 0.18;
        }
        if ($maxLines >= 3) {
            $overlapRisk += 0.09;
        }
        if (in_array($position, ['center', 'center_left'], true)) {
            $overlapRisk += 0.08;
        }

        return [
            'role' => (string) ($template['role'] ?? 'primary'),
            'contrast_score' => $contrast,
            'safe_area_score' => round(max(0.0, min(1.0, $safeAreaScore)), 4),
            'overlap_risk' => round(max(0.0, min(1.0, $overlapRisk)), 4),
            'mobile_readability' => $mobileReadability,
            'text_length' => $length,
            'background_style' => $backgroundStyle,
        ];
    }

    private function resolveBackdropLuminance(?string $assetPath, string $safeArea, string $backgroundStyle): float
    {
        if (in_array($backgroundStyle, ['dark_box', 'gradient_strip'], true)) {
            return 0.12;
        }

        if ($backgroundStyle === 'light_box') {
            return 0.86;
        }

        if (!$assetPath || !is_file($assetPath) || !function_exists('getimagesize')) {
            return 0.48;
        }

        $info = @getimagesize($assetPath);
        if (!is_array($info) || !isset($info['mime'])) {
            return 0.48;
        }

        $image = match ((string) $info['mime']) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($assetPath),
            'image/png' => @imagecreatefrompng($assetPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($assetPath) : false,
            default => false,
        };

        if (!$image) {
            return 0.48;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 4 || $height < 4) {
                return 0.48;
            }

            [$x1, $y1, $x2, $y2] = match ($safeArea) {
                'lower_third' => [0, (int) round($height * 0.62), $width - 1, $height - 1],
                'center_safe' => [(int) round($width * 0.15), (int) round($height * 0.28), (int) round($width * 0.85), (int) round($height * 0.72)],
                default => [0, 0, $width - 1, (int) round($height * 0.38)],
            };

            $samples = 0;
            $luminanceSum = 0.0;
            $stepX = max(1, (int) round(($x2 - $x1) / 8));
            $stepY = max(1, (int) round(($y2 - $y1) / 8));

            for ($x = $x1; $x <= $x2; $x += $stepX) {
                for ($y = $y1; $y <= $y2; $y += $stepY) {
                    $index = imagecolorat($image, $x, $y);
                    $r = ($index >> 16) & 0xFF;
                    $g = ($index >> 8) & 0xFF;
                    $b = $index & 0xFF;
                    $luminanceSum += $this->relativeLuminance([$r, $g, $b]);
                    $samples++;
                }
            }

            return $samples > 0 ? round($luminanceSum / $samples, 4) : 0.48;
        } finally {
            imagedestroy($image);
        }
    }

    private function contrastScore(string $textColor, float $baseLuminance, string $backgroundStyle): float
    {
        $rgb = $this->hexToRgb($textColor) ?? [255, 255, 255];
        $textLuminance = $this->relativeLuminance($rgb);
        $ratio = ($textLuminance > $baseLuminance)
            ? (($textLuminance + 0.05) / ($baseLuminance + 0.05))
            : (($baseLuminance + 0.05) / ($textLuminance + 0.05));

        $score = ($ratio - 1.0) / 9.5;
        if ($backgroundStyle === 'dark_box') {
            $score += 0.08;
        }
        if ($backgroundStyle === 'none') {
            $score -= 0.04;
        }

        return round(max(0.0, min(1.0, $score)), 4);
    }

    /**
     * @param  array<int, int>  $rgb
     */
    private function relativeLuminance(array $rgb): float
    {
        $channels = array_map(function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * @return array<int, int>|null
     */
    private function hexToRgb(string $value): ?array
    {
        $hex = strtoupper(trim($value));
        if (!str_starts_with($hex, '#')) {
            $hex = '#' . $hex;
        }
        if (preg_match('/^#([0-9A-F]{6})$/', $hex) !== 1) {
            return null;
        }

        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function average(array $rows, string $key): float
    {
        $values = array_values(array_filter(array_map(
            fn ($row) => is_numeric($row[$key] ?? null) ? (float) $row[$key] : null,
            $rows
        ), fn ($value) => $value !== null));

        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 4);
    }
}
