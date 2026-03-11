<?php

namespace App\Services\Social;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SocialAssetUrlService
{
    /**
     * @return array{media_type:string,path:string,public_url:string}
     */
    public function resolveForContentItem(ContentItem $item): array
    {
        [$path, $mediaType] = $this->extractPrimaryMedia($item);

        if ($path === '') {
            throw new RuntimeException('Nessun asset pronto per la pubblicazione automatica.');
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            throw new RuntimeException("Asset non trovato sul disco pubblico: {$path}");
        }

        return [
            'media_type' => $mediaType,
            'path' => $path,
            'public_url' => $this->buildPublicUrl($path),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractPrimaryMedia(ContentItem $item): array
    {
        $assets = is_array($item->assets) ? $item->assets : [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (str_contains($type, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
                return [$path, 'video'];
            }
        }

        $imagePath = trim((string) ($item->ai_image_path ?? ''));
        if ($imagePath !== '') {
            return [$imagePath, 'image'];
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                return [$path, 'image'];
            }
        }

        return ['', 'image'];
    }

    private function buildPublicUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $baseUrl = trim((string) config('meta.public_media_base_url', ''));

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . $path;
        }

        $relative = Storage::disk('public')->url($path);
        $absolute = url($relative);
        $host = (string) parse_url($absolute, PHP_URL_HOST);
        $allowLocal = (bool) config('meta.allow_local_public_urls', false);

        if (!$allowLocal && in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('L URL media pubblico punta a localhost. Imposta APP_URL pubblico oppure SOCIAL_PUBLIC_MEDIA_BASE_URL.');
        }

        return $absolute;
    }
}
