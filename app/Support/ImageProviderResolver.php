<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class ImageProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('image');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('image');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('image', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('image', $preferred, $fallback);
    }

    public static function inRule(): string
    {
        return 'in:' . implode(',', self::allowed());
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}