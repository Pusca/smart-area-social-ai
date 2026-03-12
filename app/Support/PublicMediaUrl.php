<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PublicMediaUrl
{
    public static function build(string $path): string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            throw new RuntimeException('Missing public media path.');
        }

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
