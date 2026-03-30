<?php

namespace App\Services\Overlays;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class ContentOverlayRenderer
{
    public function __construct(
        private readonly ContentOverlayFontRegistry $fontRegistry,
        private readonly ContentOverlayReadabilityService $readability
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(ContentItem $item): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $overlayMeta = (array) data_get($meta, 'overlay_meta', []);

        if ($overlayMeta === [] || (string) data_get($overlayMeta, 'mode', 'auto') === 'off') {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'overlay_disabled',
            ]);
        }

        $publicDisk = Storage::disk('public');
        $videoPath = trim((string) data_get($meta, 'video_generation.video_path', ''));

        try {
            if ($videoPath !== '' && $publicDisk->exists($videoPath)) {
                return $this->renderVideo($item, $overlayMeta, $videoPath);
            }

            $imagePath = trim((string) ($item->ai_image_path ?? ''));
            if ($imagePath !== '' && $publicDisk->exists($imagePath)) {
                return $this->renderImage($item, $overlayMeta, $imagePath);
            }

            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'missing_render_source',
            ]);
        } catch (Throwable $e) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'overlay_render_exception',
                'error' => Str::limit($e->getMessage(), 200, ''),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overlayMeta
     * @return array<string, mixed>
     */
    private function renderImage(ContentItem $item, array $overlayMeta, string $imagePath): array
    {
        $publicDisk = Storage::disk('public');
        $sourceAbs = $publicDisk->path($imagePath);
        $fontPath = $this->fontRegistry->resolveFontPath(
            (string) data_get($overlayMeta, 'templates.0.font_family', 'arial'),
            (string) data_get($overlayMeta, 'templates.0.font_weight', '700'),
            (string) data_get($overlayMeta, 'templates.0.fallback_font_family', '')
        );

        if ($fontPath === null) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'font_not_found',
            ]);
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        if (!$this->canRunBinary($ffmpeg)) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'ffmpeg_unavailable',
            ]);
        }

        $size = @getimagesize($sourceAbs);
        if (!is_array($size)) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'invalid_image_source',
            ]);
        }

        $filters = $this->buildImageFilters((int) $size[0], (int) $size[1], (array) ($overlayMeta['templates'] ?? []), $fontPath);
        if ($filters === '') {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'no_overlay_filters',
            ]);
        }

        $targetPath = 'ai/overlays/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.png';
        $targetAbs = $publicDisk->path($targetPath);
        if (!is_dir(dirname($targetAbs))) {
            @mkdir(dirname($targetAbs), 0775, true);
        }
        $process = new Process([$ffmpeg, '-y', '-i', $sourceAbs, '-vf', $filters, '-frames:v', '1', $targetAbs]);
        $process->setTimeout(240);
        $process->run();

        if (!$process->isSuccessful() || !$publicDisk->exists($targetPath)) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'ffmpeg_render_failed',
                'error' => Str::limit(trim((string) $process->getErrorOutput()) ?: trim((string) $process->getOutput()), 220, ''),
            ]);
        }

        $item->ai_image_path = $targetPath;
        $assets = is_array($item->assets) ? $item->assets : [];
        $item->assets = $this->upsertRenderedAsset($assets, 'ai_overlay_rendered', $targetPath);
        $item->save();

        return $this->persistRenderResult($item, $overlayMeta, [
            'applied' => true,
            'kind' => 'image',
            'source_path' => $imagePath,
            'output_path' => $targetPath,
            'reason' => 'image_overlay_rendered',
        ], $sourceAbs);
    }

    /**
     * @param  array<string, mixed>  $overlayMeta
     * @return array<string, mixed>
     */
    private function renderVideo(ContentItem $item, array $overlayMeta, string $videoPath): array
    {
        $publicDisk = Storage::disk('public');
        $sourceAbs = $publicDisk->path($videoPath);
        $fontPath = $this->fontRegistry->resolveFontPath(
            (string) data_get($overlayMeta, 'templates.0.font_family', 'arial'),
            (string) data_get($overlayMeta, 'templates.0.font_weight', '700'),
            (string) data_get($overlayMeta, 'templates.0.fallback_font_family', '')
        );

        if ($fontPath === null) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'font_not_found',
            ]);
        }

        $ffmpeg = $this->resolveFfmpegBinary();
        if (!$this->canRunBinary($ffmpeg)) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'ffmpeg_unavailable',
            ]);
        }

        $dimensions = $this->probeVideoDimensions($sourceAbs);
        $filters = $this->buildVideoFilters(
            width: (int) ($dimensions['width'] ?? 1080),
            height: (int) ($dimensions['height'] ?? 1920),
            templates: (array) ($overlayMeta['templates'] ?? []),
            fontPath: $fontPath,
            durationMs: (int) ($dimensions['duration_ms'] ?? 15000)
        );
        if ($filters === '') {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'no_overlay_filters',
            ]);
        }

        $targetPath = 'ai/overlays/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.mp4';
        $targetAbs = $publicDisk->path($targetPath);
        if (!is_dir(dirname($targetAbs))) {
            @mkdir(dirname($targetAbs), 0775, true);
        }
        $process = new Process([
            $ffmpeg,
            '-y',
            '-i',
            $sourceAbs,
            '-vf',
            $filters,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '22',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'copy',
            '-movflags',
            '+faststart',
            $targetAbs,
        ]);
        $process->setTimeout(900);
        $process->run();

        if (!$process->isSuccessful() || !$publicDisk->exists($targetPath)) {
            return $this->persistRenderResult($item, $overlayMeta, [
                'applied' => false,
                'reason' => 'ffmpeg_render_failed',
                'error' => Str::limit(trim((string) $process->getErrorOutput()) ?: trim((string) $process->getOutput()), 220, ''),
            ]);
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['video_generation'] = array_merge((array) data_get($meta, 'video_generation', []), [
            'video_path' => $targetPath,
            'overlay_source_path' => $videoPath,
        ]);
        $item->ai_meta = $meta;
        $assets = is_array($item->assets) ? $item->assets : [];
        $item->assets = $this->upsertRenderedAsset($assets, 'ai_overlay_rendered_video', $targetPath);
        $item->save();

        return $this->persistRenderResult($item, $overlayMeta, [
            'applied' => true,
            'kind' => 'video',
            'source_path' => $videoPath,
            'output_path' => $targetPath,
            'reason' => 'video_overlay_rendered',
        ], null, $sourceAbs);
    }

    /**
     * @param  array<string, mixed>  $overlayMeta
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function persistRenderResult(ContentItem $item, array $overlayMeta, array $result, ?string $imageAbs = null, ?string $videoAbs = null): array
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $readability = $this->readability->evaluate(
            ['templates' => (array) ($overlayMeta['templates'] ?? [])],
            $imageAbs,
            ['video_source' => $videoAbs]
        );
        $meta['overlay_meta'] = array_replace_recursive($overlayMeta, [
            'readability' => $readability,
            'rendering' => array_merge((array) data_get($overlayMeta, 'rendering', []), $result, [
                'status' => (bool) ($result['applied'] ?? false) ? 'rendered' : 'skipped',
                'rendered_at' => now()->toDateTimeString(),
            ]),
        ]);
        $item->ai_meta = $meta;
        $item->save();

        return (array) data_get($item->ai_meta, 'overlay_meta.rendering', $result);
    }

    /**
     * @param  array<int, mixed>  $assets
     * @return array<int, array<string, string>>
     */
    private function upsertRenderedAsset(array $assets, string $type, string $path): array
    {
        $filtered = array_values(array_filter($assets, function ($asset) use ($type): bool {
            return !(is_array($asset) && (string) ($asset['type'] ?? '') === $type);
        }));

        $filtered[] = [
            'type' => $type,
            'path' => $path,
        ];

        return $filtered;
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     */
    private function buildImageFilters(int $width, int $height, array $templates, string $fontPath): string
    {
        $filters = [];
        foreach ($templates as $template) {
            if (!is_array($template) || trim((string) ($template['text'] ?? '')) === '') {
                continue;
            }
            $filters = array_merge($filters, $this->templateFilters($template, $fontPath, $width, $height, null));
        }

        return implode(',', $filters);
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     */
    private function buildVideoFilters(int $width, int $height, array $templates, string $fontPath, int $durationMs): string
    {
        $filters = [];
        foreach ($templates as $template) {
            if (!is_array($template) || trim((string) ($template['text'] ?? '')) === '') {
                continue;
            }
            $filters = array_merge($filters, $this->templateFilters($template, $fontPath, $width, $height, $durationMs));
        }

        return implode(',', $filters);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<int, string>
     */
    private function templateFilters(array $template, string $fontPath, int $width, int $height, ?int $durationMs): array
    {
        $primaryText = $this->prepareText((string) ($template['text'] ?? ''), $template);
        $secondaryText = $this->prepareText((string) ($template['secondary_text'] ?? ''), $template, true);
        $fontSize = $this->fontSizeForMode((string) ($template['font_size_mode'] ?? 'large'), $width, $height, false);
        $secondaryFontSize = $this->fontSizeForMode((string) ($template['font_size_mode'] ?? 'large'), $width, $height, true);
        $safeArea = (string) ($template['safe_area'] ?? 'upper_third');
        [$x, $y] = $this->coordinates(
            (string) ($template['position'] ?? 'upper_left'),
            (string) ($template['alignment'] ?? 'left'),
            $safeArea,
            $width,
            $height,
            $fontSize,
            false
        );
        [$sx, $sy] = $this->coordinates(
            (string) ($template['position'] ?? 'upper_left'),
            (string) ($template['alignment'] ?? 'left'),
            $safeArea,
            $width,
            $height,
            $secondaryFontSize,
            true
        );

        $primary = $this->drawtextFilter($primaryText, $fontPath, [
            'fontcolor' => (string) ($template['color'] ?? '#FFFFFF'),
            'fontsize' => $fontSize,
            'x' => $x,
            'y' => $y,
            'shadow' => (bool) ($template['shadow'] ?? false),
            'stroke_color' => (string) ($template['stroke_color'] ?? '#111827'),
            'background_style' => (string) ($template['background_style'] ?? 'none'),
            'enable' => $durationMs ? $this->enableExpression($template, $durationMs) : null,
            'alpha' => $durationMs ? $this->alphaExpression($template, $durationMs, (string) ($template['animation_style'] ?? 'fade')) : null,
        ]);
        $filters = [$primary];

        if ($secondaryText !== '') {
            $filters[] = $this->drawtextFilter($secondaryText, $fontPath, [
                'fontcolor' => (string) ($template['color'] ?? '#FFFFFF'),
                'fontsize' => $secondaryFontSize,
                'x' => $sx,
                'y' => $sy,
                'shadow' => (bool) ($template['shadow'] ?? false),
                'stroke_color' => (string) ($template['stroke_color'] ?? '#111827'),
                'background_style' => 'none',
                'enable' => $durationMs ? $this->enableExpression($template, $durationMs) : null,
                'alpha' => $durationMs ? $this->alphaExpression($template, $durationMs, (string) ($template['animation_style'] ?? 'fade')) : null,
            ]);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function prepareText(string $text, array $template, bool $secondary = false): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $emphasisWords = array_values(array_filter(array_map('strval', (array) ($template['emphasis_words'] ?? []))));
        foreach ($emphasisWords as $word) {
            if ($word === '') {
                continue;
            }
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', mb_strtoupper($word, 'UTF-8'), $text) ?? $text;
        }

        $text = match ((string) ($template['text_case'] ?? 'sentence')) {
            'uppercase' => mb_strtoupper($text, 'UTF-8'),
            'title' => mb_convert_case($text, MB_CASE_TITLE, 'UTF-8'),
            default => $text,
        };

        $maxLines = $secondary ? 2 : max(1, (int) ($template['max_lines'] ?? 2));
        return $this->wrapText($text, $maxLines, 18);
    }

    private function wrapText(string $text, int $maxLines, int $wordsPerLine): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = [];

        foreach ($words as $word) {
            $current[] = $word;
            if (count($current) >= $wordsPerLine) {
                $lines[] = implode(' ', $current);
                $current = [];
            }
            if (count($lines) >= $maxLines) {
                break;
            }
        }

        if ($current !== [] && count($lines) < $maxLines) {
            $lines[] = implode(' ', $current);
        }

        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    private function fontSizeForMode(string $mode, int $width, int $height, bool $secondary): int
    {
        $base = min($width, $height);
        $factor = match (Str::lower(trim($mode))) {
            'xl' => 0.085,
            'large' => 0.068,
            'medium' => 0.054,
            default => 0.043,
        };
        if ($secondary) {
            $factor *= 0.62;
        }

        return max(24, (int) round($base * $factor));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function coordinates(string $position, string $alignment, string $safeArea, int $width, int $height, int $fontSize, bool $secondary): array
    {
        $marginX = max(28, (int) round($width * 0.06));
        $upperGuard = match (Str::lower(trim($safeArea))) {
            'center_safe' => max(42, (int) round($height * 0.18)),
            'lower_third' => max(42, (int) round($height * 0.11)),
            default => max(42, (int) round($height * 0.11)),
        };
        $lowerGuard = match (Str::lower(trim($safeArea))) {
            'center_safe' => max(78, (int) round($height * 0.18)),
            'upper_third' => max(84, (int) round($height * 0.15)),
            default => max(84, (int) round($height * 0.15)),
        };
        $lineOffset = $secondary ? (int) round($fontSize * 1.35) : 0;

        $x = match ($alignment) {
            'center' => '(w-text_w)/2',
            'right' => 'w-text_w-' . $marginX,
            default => (string) $marginX,
        };

        $y = match ($position) {
            'upper_center', 'upper_left' => (string) ($upperGuard + (int) round($fontSize * 1.18) + $lineOffset),
            'center', 'center_left' => '(h/2)-' . (int) round($fontSize * (0.25 - ($secondary ? 0.9 : 0))),
            'lower_center', 'lower_left' => 'h-' . ($lowerGuard + ($secondary ? 0 : $fontSize * 2.15) - $lineOffset),
            default => (string) ($upperGuard + (int) round($fontSize * 1.18) + $lineOffset),
        };

        return [$x, $y];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function drawtextFilter(string $text, string $fontPath, array $options): string
    {
        $parts = [
            'drawtext',
            'fontfile=' . $this->escapeFilterValue($fontPath),
            'text=' . $this->escapeFilterValue($text),
            'fontcolor=' . $this->escapeFilterValue((string) ($options['fontcolor'] ?? '#FFFFFF')),
            'fontsize=' . (int) ($options['fontsize'] ?? 48),
            'line_spacing=' . (int) round(((int) ($options['fontsize'] ?? 48)) * 0.28),
            'x=' . (string) ($options['x'] ?? '0'),
            'y=' . (string) ($options['y'] ?? '0'),
        ];

        if (!empty($options['shadow'])) {
            $parts[] = 'shadowcolor=' . $this->escapeFilterValue('black@0.6');
            $parts[] = 'shadowx=3';
            $parts[] = 'shadowy=4';
        }

        $stroke = trim((string) ($options['stroke_color'] ?? ''));
        if ($stroke !== '') {
            $parts[] = 'bordercolor=' . $this->escapeFilterValue($stroke);
            $parts[] = 'borderw=2';
        }

        $boxStyle = $this->boxStyle((string) ($options['background_style'] ?? 'none'));
        if ($boxStyle !== null) {
            $parts[] = 'box=1';
            $parts[] = 'boxcolor=' . $this->escapeFilterValue($boxStyle['color']);
            $parts[] = 'boxborderw=' . (int) $boxStyle['border'];
        }

        if (!empty($options['enable'])) {
            $parts[] = 'enable=' . $this->escapeFilterValue((string) $options['enable']);
        }
        if (!empty($options['alpha'])) {
            $parts[] = 'alpha=' . $this->escapeFilterValue((string) $options['alpha']);
        }

        return implode(':', $parts);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function enableExpression(array $template, int $durationMs): string
    {
        $start = max(0, (int) ($template['timing_start_ms'] ?? 0)) / 1000;
        $end = max($start + 0.4, min($durationMs / 1000, ((int) ($template['timing_end_ms'] ?? $durationMs)) / 1000));

        return "between(t\\,{$start}\\,{$end})";
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function alphaExpression(array $template, int $durationMs, string $animationStyle): string
    {
        $start = max(0, (int) ($template['timing_start_ms'] ?? 0)) / 1000;
        $end = max($start + 0.4, min($durationMs / 1000, ((int) ($template['timing_end_ms'] ?? $durationMs)) / 1000));
        if ($animationStyle === 'none') {
            return '1';
        }

        $fade = $animationStyle === 'pop' ? 0.18 : 0.28;
        $fadeOutStart = max($start, $end - $fade);

        return "if(lt(t\\,{$start})\\,0\\,if(lt(t\\," . round($start + $fade, 2) . ")\\,(t-{$start})/{$fade}\\,if(lt(t\\,{$fadeOutStart})\\,1\\,if(lt(t\\,{$end})\\,({$end}-t)/{$fade}\\,0))))";
    }

    /**
     * @return array{color:string,border:int}|null
     */
    private function boxStyle(string $backgroundStyle): ?array
    {
        return match (Str::lower(trim($backgroundStyle))) {
            'dark_box' => ['color' => 'black@0.58', 'border' => 26],
            'light_box' => ['color' => 'white@0.78', 'border' => 24],
            'gradient_strip' => ['color' => 'black@0.42', 'border' => 30],
            default => null,
        };
    }

    private function escapeFilterValue(string $value): string
    {
        $value = str_replace('\\', '/', $value);
        $value = str_replace(':', '\:', $value);
        $value = str_replace("'", "\\'", $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace('[', '\[', $value);
        $value = str_replace(']', '\]', $value);
        $value = str_replace("\n", '\n', $value);

        return "'" . $value . "'";
    }

    /**
     * @return array<string, int>
     */
    private function probeVideoDimensions(string $videoAbs): array
    {
        $ffprobe = $this->resolveFfprobeBinary();
        if (!$this->canRunBinary($ffprobe)) {
            return ['width' => 1080, 'height' => 1920, 'duration_ms' => 15000];
        }

        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height:format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=0',
            $videoAbs,
        ]);
        $process->setTimeout(20);
        $process->run();

        $width = 1080;
        $height = 1920;
        $durationMs = 15000;
        if ($process->isSuccessful()) {
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($key === 'width') {
                    $width = max(240, (int) $value);
                } elseif ($key === 'height') {
                    $height = max(240, (int) $value);
                } elseif ($key === 'duration') {
                    $durationMs = max(1000, (int) round(((float) $value) * 1000));
                }
            }
        }

        return ['width' => $width, 'height' => $height, 'duration_ms' => $durationMs];
    }

    private function resolveFfmpegBinary(): string
    {
        $configured = trim((string) config('generation.ffmpeg_binary', ''));
        foreach (array_filter([
            $configured,
            PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg',
            'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
        ]) as $candidate) {
            if ($this->canRunBinary($candidate)) {
                return $candidate;
            }
        }

        return $configured !== '' ? $configured : (PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg');
    }

    private function resolveFfprobeBinary(): string
    {
        $configured = trim((string) config('generation.ffprobe_binary', ''));
        foreach (array_filter([
            $configured,
            PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe',
            'C:\\Program Files\\ffmpeg\\bin\\ffprobe.exe',
            'C:\\ffmpeg\\bin\\ffprobe.exe',
            '/usr/bin/ffprobe',
            '/usr/local/bin/ffprobe',
        ]) as $candidate) {
            if ($this->canRunBinary($candidate)) {
                return $candidate;
            }
        }

        return $configured !== '' ? $configured : (PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe');
    }

    private function canRunBinary(string $binary): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(6);
            $process->run();
            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }
}
