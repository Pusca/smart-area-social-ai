<?php

namespace App\Services;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ContentMediaPreviewService
{
    private ?bool $ffmpegAvailable = null;
    private ?string $ffmpegBinary = null;

    /**
     * @param  iterable<int, ContentItem>  $items
     */
    public function attachPreviewData(iterable $items): void
    {
        foreach ($items as $item) {
            if (!$item instanceof ContentItem) {
                continue;
            }

            $item->setAttribute('media_preview', $this->resolveForItem($item));
        }
    }

    /**
     * @return array{
     *     is_video: bool,
     *     video_path: string,
     *     thumbnail_path: string,
     *     image_path: string,
     *     preview_image_path: string
     * }
     */
    public function resolveForItem(ContentItem $item): array
    {
        [$videoPath, $assetThumbnailPath] = $this->extractAssetVideoAndThumbnail($item);
        $isVideo = $videoPath !== '';

        $publicDisk = Storage::disk('public');

        $thumbnailPath = trim((string) data_get($item->ai_meta, 'video_generation.thumbnail_path', ''));
        if ($thumbnailPath === '') {
            $thumbnailPath = $assetThumbnailPath;
        }
        if ($thumbnailPath !== '' && !$publicDisk->exists($thumbnailPath)) {
            $thumbnailPath = '';
        }

        if ($thumbnailPath === '' && $videoPath !== '') {
            $thumbnailPath = $this->extractFirstFrameFromVideo($item, $videoPath);
        }

        $imagePath = trim((string) ($item->ai_image_path ?? ''));
        if ($imagePath !== '' && !$publicDisk->exists($imagePath)) {
            $imagePath = '';
        }
        if ($isVideo && !$this->isUsableVideoPreviewImage($imagePath)) {
            $imagePath = '';
        }

        $previewImagePath = $thumbnailPath !== '' ? $thumbnailPath : $imagePath;

        return [
            'is_video' => $isVideo,
            'video_path' => $videoPath,
            'thumbnail_path' => $thumbnailPath,
            'image_path' => $imagePath,
            'preview_image_path' => $previewImagePath,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractAssetVideoAndThumbnail(ContentItem $item): array
    {
        $assetRaw = $item->assets ?? [];
        $assetList = is_string($assetRaw)
            ? (json_decode($assetRaw, true) ?: [])
            : (is_array($assetRaw) ? $assetRaw : []);

        $videoPath = '';
        $thumbnailPath = '';

        foreach ($assetList as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $assetPath = trim((string) ($asset['path'] ?? ''));
            if ($assetPath === '') {
                continue;
            }

            $assetType = strtolower(trim((string) ($asset['type'] ?? '')));
            $ext = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));

            if ($thumbnailPath === '' && (str_contains($assetType, 'thumbnail') || str_contains($assetType, 'thumb'))) {
                $thumbnailPath = $assetPath;
            }

            if ($videoPath === '' && (str_contains($assetType, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true))) {
                $videoPath = $assetPath;
            }
        }

        return [$videoPath, $thumbnailPath];
    }

    private function extractFirstFrameFromVideo(ContentItem $item, string $videoPath): string
    {
        $videoPath = trim($videoPath);
        if ($videoPath === '') {
            return '';
        }

        $publicDisk = Storage::disk('public');
        if (!$publicDisk->exists($videoPath)) {
            return '';
        }

        $existingFirstFramePath = trim((string) data_get($item->ai_meta, 'video_generation.first_frame_path', ''));
        if ($existingFirstFramePath !== '' && $publicDisk->exists($existingFirstFramePath)) {
            return $existingFirstFramePath;
        }

        if (!$this->isFfmpegAvailable()) {
            return '';
        }

        $hash = sha1((string) $item->id . '|' . $videoPath);
        $firstFramePath = 'ai/videos/frames/' . substr($hash, 0, 2) . '/' . $hash . '.jpg';
        if ($publicDisk->exists($firstFramePath)) {
            $this->persistFirstFramePath($item, $firstFramePath);
            return $firstFramePath;
        }

        $directory = dirname($firstFramePath);
        if ($directory !== '' && $directory !== '.') {
            $publicDisk->makeDirectory($directory);
        }

        $videoAbsPath = $publicDisk->path($videoPath);
        $frameAbsPath = $publicDisk->path($firstFramePath);

        $process = new Process([
            $this->resolveFfmpegBinary(),
            '-y',
            '-i',
            $videoAbsPath,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $frameAbsPath,
        ]);
        $process->setTimeout(20);
        $process->run();

        if (!$process->isSuccessful() || !is_file($frameAbsPath) || filesize($frameAbsPath) <= 0) {
            return '';
        }

        $this->persistFirstFramePath($item, $firstFramePath);

        return $firstFramePath;
    }

    private function persistFirstFramePath(ContentItem $item, string $firstFramePath): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        data_set($meta, 'video_generation.first_frame_path', $firstFramePath);

        $currentThumb = trim((string) data_get($meta, 'video_generation.thumbnail_path', ''));
        if ($currentThumb === '') {
            data_set($meta, 'video_generation.thumbnail_path', $firstFramePath);
        }

        $item->ai_meta = $meta;
        $item->saveQuietly();
    }

    private function isFfmpegAvailable(): bool
    {
        if ($this->ffmpegAvailable !== null) {
            return $this->ffmpegAvailable;
        }

        try {
            $process = new Process([$this->resolveFfmpegBinary(), '-version']);
            $process->setTimeout(4);
            $process->run();
            $this->ffmpegAvailable = $process->isSuccessful();
        } catch (\Throwable) {
            $this->ffmpegAvailable = false;
        }

        return $this->ffmpegAvailable;
    }

    private function isUsableVideoPreviewImage(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            return true;
        }

        return false;
    }

    private function resolveFfmpegBinary(): string
    {
        if ($this->ffmpegBinary !== null) {
            return $this->ffmpegBinary;
        }

        $configured = trim((string) config('generation.ffmpeg_binary', ''));
        $this->ffmpegBinary = $configured !== '' ? $configured : 'ffmpeg';

        return $this->ffmpegBinary;
    }
}
